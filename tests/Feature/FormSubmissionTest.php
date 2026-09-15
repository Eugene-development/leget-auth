<?php

namespace Tests\Feature;

use App\Services\FormMailDelivery;
use App\Services\SmartCaptchaService;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\FormTestCase;

class FormSubmissionTest extends FormTestCase
{
    public function test_all_public_form_types_are_saved_and_mailed(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);
        $types = array_diff(array_keys(config('forms.types')), ['installment', 'partner-application']);
        Mail::shouldReceive('send')->times(count($types))->withArgs(function ($view, $data, $callback) {
            $this->assertDatabaseHas('service_requests', ['id' => $data['request_id'], 'mail_status' => 'sending']);

            return $view === 'emails.form-submission';
        });
        foreach ($types as $type) {
            $payload = $this->payload($type) + ['email' => 'test@example.com', 'contract_number' => 'TEST-001'];
            $response = $this->postJson('/api/notify/service-request', $payload)->assertOk()->assertJsonPath('mail_status', 'sent');
            $this->assertDatabaseHas('service_requests', ['id' => $response->json('id'), 'service_type' => $type, 'recipient_email' => 'info@novostroy.org']);
            $this->assertSame(in_array($type, config('forms.conversion_types'), true), DB::table('conversions')->where('service_request_id', $response->json('id'))->exists());
        }
    }

    public function test_retry_does_not_duplicate_and_changed_payload_cannot_reuse_key(): void
    {
        Mail::shouldReceive('send')->once();
        $payload = $this->payload() + ['submission_key' => (string) Str::uuid()];
        $first = $this->postJson('/api/notify/service-request', $payload)->assertOk();
        $this->postJson('/api/notify/service-request', $payload)->assertOk()->assertJsonPath('id', $first->json('id'));
        $this->postJson('/api/notify/service-request', array_replace($payload, ['name' => 'Other']))->assertUnprocessable();
        $this->assertDatabaseCount('service_requests', 1);
        $this->assertDatabaseCount('conversions', 1);
    }

    public function test_mail_failure_is_retried_without_losing_or_duplicating_application(): void
    {
        Mail::shouldReceive('send')->once()->andThrow(new \RuntimeException('SMTP unavailable'));
        $response = $this->postJson('/api/notify/service-request', $this->payload())->assertStatus(202)->assertJsonPath('mail_status', 'pending');
        $id = $response->json('id');
        $this->assertDatabaseHas('service_requests', ['id' => $id, 'mail_attempts' => 1, 'mail_error' => 'RuntimeException']);
        Mail::shouldReceive('send')->once();
        $this->travel(3)->minutes();
        $this->artisan('forms:retry-mail')->assertSuccessful();
        $this->assertDatabaseHas('service_requests', ['id' => $id, 'mail_status' => 'sent', 'mail_attempts' => 2]);
        app(FormMailDelivery::class)->deliver($id);
        $this->assertDatabaseCount('service_requests', 1);
    }

    public function test_database_failure_never_sends_email(): void
    {
        Mail::shouldReceive('send')->never();
        Schema::drop('conversions');
        $this->postJson('/api/notify/service-request', $this->payload())->assertStatus(500)->assertJsonPath('success', false);
        $this->assertDatabaseCount('service_requests', 0);
    }

    public function test_attachments_are_encrypted_and_can_be_used_after_http_upload_is_gone(): void
    {
        Mail::shouldReceive('send')->once()->andThrow(new \RuntimeException('offline'));
        $file = UploadedFile::fake()->image('private.jpg');
        $bytes = file_get_contents($file->getRealPath());
        $response = $this->post('/api/notify/service-request', $this->payload('warranty') + ['contract_number' => 'TEST', 'photos' => [$file]])->assertStatus(202);
        $attachment = DB::table('service_request_attachments')->first();
        $encrypted = Storage::disk($attachment->disk)->get($attachment->path);
        $this->assertNotSame($bytes, $encrypted);
        $this->assertSame($bytes, Crypt::decryptString($encrypted));
        unlink($file->getRealPath());
        Mail::shouldReceive('send')->once()->withArgs(function ($view, $data, $callback) use ($bytes) {
            $message = \Mockery::mock();
            $message->shouldReceive('to')->once()->with('info@novostroy.org')->andReturnSelf();
            $message->shouldReceive('subject')->once()->andReturnSelf();
            $message->shouldReceive('attachData')->once()->with($bytes, 'warranty-photo-1.jpg', ['mime' => 'image/jpeg'])->andReturnSelf();
            $callback($message);

            return true;
        });
        $this->travel(3)->minutes();
        $this->artisan('forms:retry-mail')->assertSuccessful();
        $this->assertDatabaseHas('service_requests', ['id' => $response->json('id'), 'mail_status' => 'sent']);
    }

    public function test_invalid_and_unexpected_uploads_are_rejected(): void
    {
        Mail::shouldReceive('send')->never();
        $this->postJson('/api/notify/service-request', $this->payload('unknown'))->assertUnprocessable();
        $this->post('/api/notify/service-request', $this->payload() + ['photos' => [UploadedFile::fake()->image('bad.jpg')]])->assertUnprocessable();
        $this->assertDatabaseCount('service_requests', 0);
    }

    public function test_platform_contact_preserves_message_and_requires_captcha(): void
    {
        $this->mock(SmartCaptchaService::class, function ($mock) {
            $mock->shouldReceive('clientIp')->twice()->andReturn('127.0.0.1');
            $mock->shouldReceive('verify')->with('bad', '127.0.0.1')->once()->andReturn(false);
            $mock->shouldReceive('verify')->with('good', '127.0.0.1')->once()->andReturn(true);
        });
        Mail::shouldReceive('send')->once();
        $payload = ['name' => 'TEST', 'email' => 'test@example.com', 'message' => 'TEST contact'];
        $this->postJson('/api/notify/contact', $payload + ['captcha_token' => 'bad'])->assertUnprocessable();
        $this->postJson('/api/notify/contact', $payload + ['captcha_token' => 'good'])->assertOk();
        $this->assertDatabaseHas('service_requests', ['service_type' => 'contact', 'message' => 'TEST contact']);
    }

    public function test_tenant_recipient_and_server_override_ignore_client_recipient(): void
    {
        $owner = DB::table('users')->insertGetId(['name' => 'Owner', 'email' => 'owner@example.com', 'password' => 'unused']);
        DB::table('licenses')->insert(['id' => 'test-license', 'domain' => 'tenant.example', 'user_id' => $owner]);
        Mail::shouldReceive('send')->twice();
        $payload = $this->payload() + ['source_url' => 'https://www.tenant.example/contact', 'recipient_email' => 'attacker@example.com'];
        $this->postJson('/api/notify/service-request', $payload)->assertOk();
        $this->assertDatabaseHas('service_requests', ['recipient_email' => 'owner@example.com', 'site_domain' => 'tenant.example']);
        config(['forms.recipient_override' => 'info@novostroy.org']);
        $this->postJson('/api/notify/service-request', $payload)->assertOk();
        $this->assertDatabaseHas('service_requests', ['recipient_email' => 'info@novostroy.org', 'site_domain' => 'tenant.example']);
    }

    public function test_subscription_accepts_only_email_and_contacts_accept_only_phone(): void
    {
        Mail::shouldReceive('send')->twice();
        $this->postJson('/api/notify/service-request', ['service_type' => 'subscription', 'email' => 'test@example.com'])->assertOk();
        $this->assertDatabaseHas('service_requests', ['service_type' => 'subscription', 'email' => 'test@example.com', 'phone' => '']);
        $this->postJson('/api/notify/service-request', ['service_type' => 'contact', 'name' => 'TEST', 'phone' => '+79990000000'])->assertOk();
        $this->assertDatabaseHas('service_requests', ['service_type' => 'contact', 'email' => null, 'phone' => '+79990000000']);
    }

    public function test_legacy_graphql_then_notify_adopts_existing_row_and_conversion(): void
    {
        $id = (string) Str::ulid();
        $payload = $this->payload();
        DB::table('service_requests')->insert($payload + ['id' => $id, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('conversions')->insert(['id' => (string) Str::ulid(), 'channel' => 'online', 'type' => 'consultation', 'name' => 'TEST', 'service_request_id' => $id]);
        Mail::shouldReceive('send')->once();
        $this->postJson('/api/notify/service-request', $payload)->assertOk()->assertJsonPath('id', $id);
        $this->assertDatabaseCount('service_requests', 1);
        $this->assertDatabaseCount('conversions', 1);
        $this->assertDatabaseHas('service_requests', ['id' => $id, 'mail_status' => 'sent']);
    }

    public function test_unvalidated_contact_attachments_cannot_bypass_file_rules(): void
    {
        $this->mock(SmartCaptchaService::class, function ($mock) {
            $mock->shouldReceive('clientIp')->andReturn('127.0.0.1');
            $mock->shouldReceive('verify')->andReturn(true);
        });
        Mail::shouldReceive('send')->never();
        $this->post('/api/notify/contact', ['name' => 'TEST', 'email' => 'test@example.com', 'message' => 'TEST', 'photos' => [UploadedFile::fake()->create('unsafe.svg', 10, 'image/svg+xml')]])
            ->assertUnprocessable()->assertJsonValidationErrors('photos');
        $this->assertDatabaseCount('service_request_attachments', 0);
    }

    public function test_exhausted_mail_retry_is_visible_and_operator_can_retry_by_id(): void
    {
        config(['forms.max_attempts' => 1]);
        Mail::shouldReceive('send')->once()->andThrow(new \RuntimeException('offline'));
        $response = $this->postJson('/api/notify/service-request', $this->payload())->assertStatus(202)->assertJsonPath('mail_status', 'failed');
        $this->artisan('forms:retry-mail')->assertFailed();
        Mail::shouldReceive('send')->once();
        $this->artisan('forms:retry-mail', ['--id' => $response->json('id')])->assertSuccessful();
        $this->artisan('forms:retry-mail', ['--id' => $response->json('id')])->assertSuccessful();
        $this->assertDatabaseHas('service_requests', ['id' => $response->json('id'), 'mail_status' => 'sent', 'mail_attempts' => 2]);
    }

    public function test_storage_failure_does_not_accept_or_email_an_incomplete_request(): void
    {
        Mail::shouldReceive('send')->never();
        $disk = \Mockery::mock();
        $disk->shouldReceive('put')->once()->andReturn(false);
        $disk->shouldReceive('delete')->once()->andReturn(true);
        Storage::shouldReceive('disk')->with('form-tests')->andReturn($disk);
        $this->post('/api/notify/service-request', $this->payload('warranty') + ['contract_number' => 'TEST', 'photos' => [UploadedFile::fake()->image('one.jpg')]])->assertStatus(500);
        $this->assertDatabaseCount('service_requests', 0);
    }

    private function payload(string $type = 'consultation'): array
    {
        return ['service_type' => $type, 'form_id' => 'test-'.$type, 'name' => 'TEST FORM', 'phone' => '+79990000000', 'message' => 'Synthetic test'];
    }
}
