<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\FormTestCase;

class CrmIntakeTest extends FormTestCase
{
    private User $owner;

    private User $manager;

    private string $site;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::create(['name' => 'Owner', 'email' => 'owner@crm.test', 'password' => 'secret']);
        $this->manager = User::create(['name' => 'Manager', 'email' => 'manager@crm.test', 'password' => 'secret']);
        $this->site = (string) Str::ulid();
        DB::table('licenses')->insert(['id' => $this->site, 'domain' => 'tenant.test', 'user_id' => $this->owner->id]);
        config(['forms.context_secret' => str_repeat('s', 64)]);
    }

    private function signed(string $domain = 'tenant.test', ?int $time = null, ?string $ip = null): array
    {
        $p = rtrim(strtr(base64_encode(json_encode(['domain' => $domain, 'timestamp' => $time ?? time(), 'client_ip' => $ip])), '+/', '-_'), '=');

        return ['X-Leget-Form-Context' => $p.'.'.hash_hmac('sha256', $p, config('forms.context_secret'))];
    }

    public function test_signed_host_binds_site_and_client_license_and_url_cannot_override_it(): void
    {
        Mail::shouldReceive('send')->once();
        $p = ['service_type' => 'contact', 'name' => 'Test', 'phone' => '12345678', 'source_url' => 'https://attacker.test/contact', 'license_id' => 'attacker'];
        $response = $this->postJson('/api/notify/service-request', $p, $this->signed())->assertOk();
        $this->assertDatabaseHas('service_requests', ['id' => $response->json('id'), 'license_id' => $this->site, 'site_domain' => 'tenant.test', 'recipient_email' => 'owner@crm.test']);
    }

    public function test_unsigned_requests_are_unassigned_invalid_signature_is_rejected(): void
    {
        Mail::shouldReceive('send')->once();
        $p = ['service_type' => 'contact', 'name' => 'Test', 'phone' => '12345678', 'source_url' => 'https://tenant.test'];
        $a = $this->postJson('/api/notify/service-request', $p)->assertOk();
        $this->assertDatabaseHas('service_requests', ['id' => $a->json('id'), 'license_id' => null]);
        $this->postJson('/api/notify/service-request', $p, ['X-Leget-Form-Context' => 'forged.signature'])->assertForbidden();
        $this->postJson('/api/notify/service-request', $p, $this->signed(time: time() - 600))->assertForbidden();
        $this->assertDatabaseCount('service_requests', 1);
    }

    public function test_public_manager_application_is_disabled(): void
    {
        $this->actingAs($this->manager, 'api')->postJson('/api/crm/sites/'.$this->site.'/apply', ['submission_key' => (string) Str::uuid()])->assertNotFound();
        $this->assertDatabaseCount('crm_memberships', 0);
        $this->assertDatabaseCount('service_requests', 0);
        $this->assertSame(Role::Client, $this->manager->fresh()->role);
    }

    public function test_offline_saves_once_survives_smtp_and_revoked_members_cannot_submit(): void
    {
        $this->manager->forceFill(['role' => Role::Manager])->save();
        DB::table('crm_memberships')->insert(['id' => (string) Str::ulid(), 'license_id' => $this->site, 'user_id' => $this->manager->id, 'status' => 'approved', 'created_at' => now(), 'updated_at' => now()]);
        $this->actingAs($this->manager, 'api');
        Mail::shouldReceive('send')->once()->andThrow(new \RuntimeException('offline'));
        $p = ['submission_key' => (string) Str::uuid(), 'name' => 'Offline client', 'phone' => '12345678', 'channel' => 'call', 'service_type' => 'contact'];
        $a = $this->postJson('/api/crm/sites/'.$this->site.'/offline', $p)->assertStatus(202);
        $this->postJson('/api/crm/sites/'.$this->site.'/offline', $p)->assertStatus(202)->assertJsonPath('id', $a->json('id'));
        $this->assertDatabaseHas('service_requests', ['id' => $a->json('id'), 'license_id' => $this->site, 'channel' => 'call', 'created_by' => $this->manager->id, 'mail_status' => 'pending']);
        $this->assertDatabaseCount('conversions', 0);
        DB::table('crm_memberships')->update(['status' => 'revoked']);
        $this->postJson('/api/crm/sites/'.$this->site.'/offline', $p)->assertForbidden();
    }

    public function test_other_roles_are_preserved_and_private_access_type_is_not_public(): void
    {
        $this->manager->forceFill(['role' => Role::Partner])->save();
        $this->actingAs($this->manager, 'api');
        Mail::shouldReceive('send')->never();
        $this->postJson('/api/crm/sites/'.$this->site.'/apply', ['submission_key' => (string) Str::uuid()])->assertNotFound();
        $this->postJson('/api/notify/service-request', ['service_type' => 'manager-access', 'name' => 'Test', 'phone' => '12345678'])->assertUnprocessable();
        $this->assertSame(Role::Partner, $this->manager->fresh()->role);
    }

    public function test_signed_visitors_do_not_share_the_gateways_throttle_quota(): void
    {
        Mail::shouldReceive('send')->times(11);
        $body = ['service_type' => 'contact', 'name' => 'Quota', 'phone' => '12345678'];
        for ($i = 0; $i < 10; $i++) {
            $this->postJson('/api/notify/service-request', $body, $this->signed(ip: '192.0.2.10'))->assertOk();
        }
        $this->postJson('/api/notify/service-request', $body, $this->signed(ip: '192.0.2.10'))->assertStatus(429);
        $this->postJson('/api/notify/service-request', $body, $this->signed(ip: '192.0.2.11'))->assertOk();
    }
}
