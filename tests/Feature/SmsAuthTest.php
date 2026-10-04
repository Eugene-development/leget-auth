<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class SmsAuthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        config(['sms.driver' => 'test', 'services.smartcaptcha.secret' => null]);
    }

    private function user(Role $role = Role::Client, string $phone = '+7 (999) 123-45-67'): User
    {
        $user = User::create(['name' => 'SMS User', 'email' => uniqid().'@example.test', 'phone' => $phone, 'password' => 'secret-password']);
        $user->forceFill(['role' => $role, 'university_enrolled_at' => now()])->save();

        return $user;
    }

    private function challenge(string $context = 'client', string $phone = '89991234567'): array
    {
        return $this->postJson('/api/sms/request', compact('phone', 'context'))->assertOk()->assertHeader('Cache-Control', 'no-store, private')->json();
    }

    private function payload(array $challenge, string $context = 'client', string $phone = '+79991234567'): array
    {
        return ['phone' => $phone, 'context' => $context, 'challenge_id' => $challenge['challenge_id'], 'code' => $challenge['test_code']];
    }

    public function test_every_role_can_login_without_changing_role_or_education(): void
    {
        foreach (Role::cases() as $index => $role) {
            $phone = '+7999123450'.$index;
            $user = $this->user($role, $phone);
            $context = $role === Role::Superadmin ? 'admin' : 'client';
            $challenge = $this->challenge($context, $phone);
            $this->postJson('/api/sms/verify', $this->payload($challenge, $context, $phone))
                ->assertOk()->assertJsonPath('user.id', $user->id)->assertJsonPath('role', $role->value)->assertJsonStructure(['token']);
            $this->assertEquals($role, $user->fresh()->role);
            $this->assertNotNull($user->fresh()->university_enrolled_at);
        }
    }

    public function test_code_is_single_use_and_does_not_verify_email(): void
    {
        $this->user();
        $payload = $this->payload($this->challenge());
        $this->postJson('/api/sms/verify', $payload)->assertOk()->assertJsonPath('email_verified', false);
        $this->postJson('/api/sms/verify', $payload)->assertUnprocessable();
    }

    public function test_code_expires(): void
    {
        $this->user();
        $payload = $this->payload($this->challenge());
        $this->travel(301)->seconds();
        $this->postJson('/api/sms/verify', $payload)->assertUnprocessable();
    }

    public function test_five_wrong_attempts_lock_challenge(): void
    {
        $this->user();
        $payload = $this->payload($this->challenge());
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/sms/verify', [...$payload, 'code' => '000000'])->assertUnprocessable();
        }
        $this->postJson('/api/sms/verify', $payload)->assertUnprocessable();
    }

    public function test_resend_is_limited_and_invalidates_previous_code(): void
    {
        $this->user();
        $old = $this->payload($this->challenge());
        $this->postJson('/api/sms/request', ['phone' => '+7 (999) 123-45-67', 'context' => 'owner'])->assertStatus(429);
        $this->travel(61)->seconds();
        $new = $this->payload($this->challenge());
        $this->postJson('/api/sms/verify', $old)->assertUnprocessable();
        $this->postJson('/api/sms/verify', $new)->assertOk();
    }

    public function test_missing_and_ambiguous_numbers_cannot_login(): void
    {
        $payload = $this->payload($this->challenge());
        $this->postJson('/api/sms/verify', $payload)->assertUnprocessable()->assertJsonMissingPath('token');
        $this->travel(61)->seconds();
        $this->user();
        $this->user(Role::Admin, '89991234567');
        $this->postJson('/api/sms/verify', $this->payload($this->challenge()))->assertUnprocessable();
    }

    public function test_context_cannot_be_switched_and_client_cannot_enter_admin(): void
    {
        $this->user();
        $challenge = $this->challenge();
        $this->postJson('/api/sms/verify', $this->payload($challenge, 'admin'))->assertUnprocessable();
        $this->postJson('/api/sms/verify', $this->payload($challenge))->assertOk();
        $this->travel(61)->seconds();
        $this->postJson('/api/sms/verify', $this->payload($this->challenge('admin'), 'admin'))->assertForbidden();
    }

    public function test_superadmin_uses_admin_or_university_context(): void
    {
        $this->user(Role::Superadmin);
        $this->postJson('/api/sms/verify', $this->payload($this->challenge()))->assertForbidden();
        $this->travel(61)->seconds();
        $this->postJson('/api/sms/verify', $this->payload($this->challenge('university'), 'university'))->assertOk();
    }

    public function test_owner_context_preserves_existing_login_contract(): void
    {
        $this->user(Role::Admin);
        $this->postJson('/api/sms/verify', $this->payload($this->challenge('owner'), 'owner'))->assertOk()->assertJsonStructure(['user', 'token', 'email_verified', 'expires_in']);
    }

    public function test_changed_number_cannot_redeem_old_challenge(): void
    {
        $user = $this->user();
        $payload = $this->payload($this->challenge());
        $user->update(['phone' => '+79991234568']);
        $this->postJson('/api/sms/verify', $payload)->assertUnprocessable();
    }

    public function test_production_and_unconfigured_provider_fail_closed(): void
    {
        $this->app['env'] = 'production';
        $this->postJson('/api/sms/request', ['phone' => '+79991234567', 'context' => 'client'])->assertStatus(503)->assertJsonMissingPath('test_code');
        $this->app['env'] = 'testing';
        config(['sms.driver' => 'live']);
        $this->postJson('/api/sms/request', ['phone' => '+79991234567', 'context' => 'client'])->assertStatus(503);
    }

    public function test_invalid_phone_and_failed_captcha_are_rejected(): void
    {
        $this->postJson('/api/sms/request', ['phone' => 'abc', 'context' => 'client'])->assertUnprocessable();
        config(['services.smartcaptcha.secret' => 'test-secret']);
        $this->postJson('/api/sms/request', ['phone' => '+79991234567', 'context' => 'client'])->assertUnprocessable()->assertJsonMissingPath('test_code');
    }
}
