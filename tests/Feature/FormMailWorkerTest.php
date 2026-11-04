<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Services\FormMailDelivery;
use App\Services\FormMailOutbox;
use Illuminate\Mail\Message;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Symfony\Component\Mime\Email;
use Tests\FormTestCase;

class FormMailWorkerTest extends FormTestCase
{
    private function site(string $domain = 'tenant.test'): string
    {
        $owner = $this->user('owner-'.$domain.'@example.com', 'admin');
        $id = (string) Str::ulid();
        DB::table('licenses')->insert(['id' => $id, 'domain' => $domain, 'user_id' => $owner]);

        return $id;
    }

    private function user(string $email, string $role = 'manager'): int
    {
        return DB::table('users')->insertGetId(['name' => 'Synthetic user', 'email' => $email, 'password' => 'unused', 'role' => $role]);
    }

    private function manager(string $site, string $email, string $status = 'approved', string $role = 'manager'): int
    {
        $id = $this->user($email, $role);
        DB::table('crm_memberships')->insert(['id' => (string) Str::ulid(), 'license_id' => $site, 'user_id' => $id, 'status' => $status, 'created_at' => now(), 'updated_at' => now()]);

        return $id;
    }

    private function submit(?string $domain = null, array $extra = []): string
    {
        return $this->postJson('/api/notify/service-request', $extra + [
            'service_type' => 'consultation', 'name' => 'Synthetic visitor', 'phone' => '+79990000000',
            'submission_key' => (string) Str::uuid(),
        ], $domain ? $this->signedFormContext($domain) : [])->assertStatus(202)->assertJsonPath('mail_status', 'pending')->json('id');
    }

    private function captureMail(array &$addresses, ?string $reject = null): void
    {
        Mail::shouldReceive('send')->andReturnUsing(function ($view, $data, $callback) use (&$addresses, $reject) {
            $this->assertSame(0, DB::transactionLevel());
            $message = new Message(new Email);
            $callback($message);
            $email = $message->getSymfonyMessage();
            $this->assertCount(1, $email->getTo());
            $this->assertEmpty($email->getCc());
            $this->assertEmpty($email->getBcc());
            $recipient = $email->getTo()[0]->getAddress();
            $addresses[] = $recipient;
            if ($recipient === $reject) {
                throw new \RuntimeException('Synthetic SMTP failure');
            }
        });
    }

    public function test_http_only_commits_and_replay_cannot_enqueue_another_delivery(): void
    {
        Mail::shouldReceive('send')->never();
        $site = $this->site();
        $body = ['submission_key' => (string) Str::uuid(), 'source_url' => 'https://tenant.test', 'recipient_email' => 'forged@example.com', 'license_id' => $site];
        $id = $this->submit(extra: $body);
        $this->assertSame($id, $this->submit(extra: $body));
        $this->assertDatabaseHas('service_requests', ['id' => $id, 'license_id' => null, 'mail_attempts' => 0]);
        $this->assertDatabaseHas('service_request_mail_deliveries', ['service_request_id' => $id, 'kind' => 'admin', 'recipient_email' => 'info@novostroy.org', 'attempts' => 0]);
        $this->assertDatabaseCount('service_requests', 1);
        $this->assertDatabaseCount('service_request_mail_deliveries', 1);
    }

    public function test_partner_and_offline_http_handlers_never_send_email(): void
    {
        Mail::shouldReceive('send')->never();
        $site = $this->site();
        $manager = $this->manager($site, 'offline@example.com');
        $this->actingAs(User::findOrFail($manager), 'api')->postJson('/api/crm/sites/'.$site.'/offline', [
            'submission_key' => (string) Str::uuid(), 'service_type' => 'contact',
            'channel' => 'call', 'name' => 'Offline fixture', 'phone' => '123',
        ])->assertStatus(202)->assertJsonPath('mail_status', 'pending');
        $client = $this->user('partner@example.com', 'client');
        $this->actingAs(User::findOrFail($client), 'api')->postJson('/api/partner/apply', [
            'submission_key' => (string) Str::uuid(), 'partner_type' => 'designer',
        ])->assertStatus(202)->assertJsonPath('mail_status', 'pending');
        $this->assertDatabaseCount('service_requests', 2);
        $this->assertDatabaseCount('service_request_mail_deliveries', 2);
        $this->assertSame(0, DB::table('service_request_mail_deliveries')->sum('attempts'));
    }

    public function test_outbox_write_failure_rolls_back_receipt_and_conversion(): void
    {
        Mail::shouldReceive('send')->never();
        Schema::drop('service_request_mail_deliveries');
        $this->postJson('/api/notify/service-request', ['service_type' => 'consultation', 'name' => 'Synthetic', 'phone' => '123'])->assertStatus(500);
        $this->assertDatabaseCount('service_requests', 0);
        $this->assertDatabaseCount('conversions', 0);
    }

    public function test_production_cannot_mark_a_log_transport_alias_as_sent(): void
    {
        $id = $this->submit();
        Mail::shouldReceive('send')->never();
        config(['mail.default' => 'smtp-looking-alias', 'mail.mailers.smtp-looking-alias.transport' => 'log']);
        $this->app->detectEnvironment(fn () => 'production');
        $this->drainFormMail();
        $this->assertDatabaseHas('service_requests', ['id' => $id, 'mail_status' => 'pending', 'mail_attempts' => 1]);
    }

    public function test_snapshot_contains_only_approved_managers_of_the_trusted_site(): void
    {
        $site = $this->site();
        $this->manager($site, 'one@example.com');
        $this->manager($site, 'two@example.com');
        $this->manager($site, 'pending@example.com', 'pending');
        $this->manager($site, 'revoked@example.com', 'revoked');
        $this->manager($site, 'client@example.com', role: 'client');
        $this->manager($this->site('other.test'), 'foreign@example.com');
        $id = $this->submit('tenant.test', ['source_url' => 'https://other.test', 'recipient_email' => 'forged@example.com']);
        $this->manager($site, 'late@example.com');
        $this->assertDatabaseCount('service_request_mail_deliveries', 2);
        $addresses = [];
        $this->captureMail($addresses);
        $this->artisan('forms:work-mail', ['--once' => true])->assertSuccessful();
        $this->assertEqualsCanonicalizing(['one@example.com', 'two@example.com'], $addresses);
        $this->assertDatabaseHas('service_requests', ['id' => $id, 'license_id' => $site, 'assigned_to' => null, 'mail_status' => 'sent']);
    }

    public function test_partial_failure_retries_only_unsent_recipient_and_uses_current_email(): void
    {
        $site = $this->site();
        $this->manager($site, 'one@example.com');
        $manager = $this->manager($site, 'two@example.com');
        $id = $this->submit('tenant.test');
        $addresses = [];
        $this->captureMail($addresses, 'two@example.com');
        $this->drainFormMail();
        $this->assertEqualsCanonicalizing(['one@example.com', 'two@example.com'], $addresses);
        $this->assertDatabaseHas('service_request_mail_deliveries', ['user_id' => $manager, 'status' => 'pending', 'attempts' => 1]);
        $this->assertDatabaseHas('service_requests', ['id' => $id, 'mail_status' => 'pending']);
        DB::table('users')->where('id', $manager)->update(['email' => 'updated@example.com']);
        $this->travel(3)->minutes();
        $this->drainFormMail();
        $this->assertSame(['one@example.com', 'two@example.com', 'updated@example.com'], $addresses);
        $this->assertDatabaseHas('service_requests', ['id' => $id, 'mail_status' => 'sent', 'mail_attempts' => 3]);
        $this->artisan('forms:retry-mail', ['--id' => $id])->assertSuccessful();
        $this->assertCount(3, $addresses);
    }

    public function test_revoked_snapshot_falls_back_to_current_owner_without_sending_to_new_managers(): void
    {
        $site = $this->site();
        $this->manager($site, 'revoked@example.com');
        $id = $this->submit('tenant.test');
        DB::table('crm_memberships')->where('license_id', $site)->update(['status' => 'revoked']);
        $this->manager($site, 'new@example.com');
        $owner = $this->user('new-owner@example.com', 'admin');
        DB::table('licenses')->where('id', $site)->update(['user_id' => $owner]);
        $addresses = [];
        $this->captureMail($addresses);
        $this->drainFormMail();
        $this->assertSame(['new-owner@example.com'], $addresses);
        $this->assertDatabaseHas('service_request_mail_deliveries', ['service_request_id' => $id, 'kind' => 'manager', 'status' => 'skipped']);
        $this->assertDatabaseHas('service_requests', ['id' => $id, 'mail_status' => 'sent']);
    }

    public function test_revoking_remaining_manager_after_partial_success_does_not_email_owner(): void
    {
        $site = $this->site();
        $this->manager($site, 'one@example.com');
        $second = $this->manager($site, 'two@example.com');
        $id = $this->submit('tenant.test');
        $addresses = [];
        $this->captureMail($addresses);
        $this->assertTrue(app(FormMailDelivery::class)->processOne());
        DB::table('crm_memberships')->where('user_id', $second)->update(['status' => 'revoked']);
        $this->drainFormMail();
        $this->assertSame(['one@example.com'], $addresses);
        $this->assertDatabaseHas('service_requests', ['id' => $id, 'mail_status' => 'sent']);
    }

    public function test_owner_fallback_and_override_each_have_one_destination(): void
    {
        $site = $this->site();
        $first = $this->submit('tenant.test');
        $this->manager($site, 'manager@example.com');
        config(['forms.recipient_override' => 'override@example.com']);
        $second = $this->submit('tenant.test');
        config(['forms.recipient_override' => null]);
        $addresses = [];
        $this->captureMail($addresses);
        $this->drainFormMail();
        $this->assertSame(['owner-tenant.test@example.com', 'override@example.com'], $addresses);
        $this->assertDatabaseHas('service_request_mail_deliveries', ['service_request_id' => $first, 'kind' => 'owner', 'status' => 'sent']);
        $this->assertDatabaseHas('service_request_mail_deliveries', ['service_request_id' => $second, 'kind' => 'override', 'status' => 'sent']);
    }

    public function test_missing_mail_configuration_never_loses_receipt_and_can_be_repaired(): void
    {
        config(['forms.recipient' => null, 'forms.max_attempts' => 1]);
        $id = $this->submit();
        $this->assertDatabaseHas('service_requests', ['id' => $id, 'mail_status' => 'pending']);
        $addresses = [];
        $this->captureMail($addresses);
        $this->drainFormMail();
        $this->assertSame([], $addresses);
        $this->assertDatabaseHas('service_request_mail_deliveries', ['service_request_id' => $id, 'status' => 'failed', 'error_code' => 'FormRecipientInvalid']);
        $this->artisan('forms:retry-mail')->assertFailed();
        config(['forms.recipient' => 'repaired@example.com']);
        $this->artisan('forms:retry-mail', ['--id' => $id])->assertSuccessful();
        $this->assertSame(['repaired@example.com'], $addresses);
        $this->assertDatabaseHas('service_requests', ['id' => $id, 'mail_attempts' => 2, 'mail_status' => 'sent']);
    }

    public function test_claim_excludes_other_workers_and_stale_token_cannot_complete_new_lease(): void
    {
        $id = $this->submit();
        $outbox = app(FormMailOutbox::class);
        $first = $outbox->claim();
        $this->assertNull((new FormMailOutbox)->claim());
        $this->travel(11)->minutes();
        $second = (new FormMailOutbox)->claim();
        $this->assertNotSame($first->lock_token, $second->lock_token);
        $this->assertNull($outbox->recipient($first));
        $outbox->finish($first, 'sent');
        $this->assertDatabaseHas('service_request_mail_deliveries', ['id' => $first->id, 'status' => 'sending', 'lock_token' => $second->lock_token]);
        $outbox->finish($second, 'sent');
        $outbox->finish($first, 'failed', 'StaleWorker');
        $this->assertDatabaseHas('service_requests', ['id' => $id, 'mail_status' => 'sent', 'mail_attempts' => 2, 'mail_error' => null]);
    }

    public function test_interrupted_final_attempt_becomes_failed_without_another_send(): void
    {
        config(['forms.max_attempts' => 1]);
        Mail::shouldReceive('send')->never();
        $id = $this->submit();
        app(FormMailOutbox::class)->claim();
        $this->travel(11)->minutes();
        $this->drainFormMail();
        $this->assertDatabaseHas('service_requests', ['id' => $id, 'mail_status' => 'failed', 'mail_attempts' => 1]);
        $this->assertDatabaseHas('service_request_mail_deliveries', ['service_request_id' => $id, 'status' => 'failed', 'error_code' => 'InterruptedDelivery']);
    }

    public function test_migration_preserves_legacy_pending_and_only_explicitly_retries_failed(): void
    {
        $migration = require base_path('../leget-db/database/migrations/2026_11_03_120000_create_service_request_mail_deliveries.php');
        $migration->down();
        $ids = [];
        foreach (['pending', 'sending', 'sent', 'legacy', 'failed'] as $status) {
            $ids[$status] = (string) Str::ulid();
            DB::table('service_requests')->insert(['id' => $ids[$status], 'service_type' => 'consultation', 'name' => 'Synthetic', 'phone' => '123', 'recipient_email' => 'historical@example.com', 'mail_status' => $status, 'mail_attempts' => 3, 'mail_retry_at' => now()->subMinute(), 'mail_locked_at' => now()->subMinutes(11), 'created_at' => now(), 'updated_at' => now()]);
        }
        $migration->up();
        $this->assertDatabaseCount('service_request_mail_deliveries', 2);
        $this->assertDatabaseHas('service_request_mail_deliveries', ['service_request_id' => $ids['pending'], 'recipient_email' => 'historical@example.com', 'attempts' => 3]);
        $addresses = [];
        $this->captureMail($addresses);
        $this->drainFormMail();
        $this->assertCount(2, $addresses);
        $this->assertDatabaseHas('service_requests', ['id' => $ids['pending'], 'mail_attempts' => 4, 'mail_status' => 'sent']);
        $this->assertDatabaseHas('service_requests', ['id' => $ids['failed'], 'mail_status' => 'failed']);
        $this->artisan('forms:retry-mail', ['--id' => $ids['failed']])->assertSuccessful();
        $this->assertCount(3, $addresses);
        $this->assertDatabaseHas('service_requests', ['id' => $ids['failed'], 'mail_attempts' => 4, 'mail_status' => 'sent']);
        $this->artisan('forms:retry-mail', ['--id' => $ids['legacy']])->assertFailed();
        $this->assertCount(3, $addresses);
    }

    public function test_once_is_bounded_and_does_not_retry_sent_deliveries(): void
    {
        $id = $this->submit();
        $prototype = (array) DB::table('service_request_mail_deliveries')->first();
        for ($i = 1; $i <= 100; $i++) {
            DB::table('service_request_mail_deliveries')->insert(array_replace($prototype, ['id' => (string) Str::ulid(), 'recipient_key' => 'batch:'.$i]));
        }
        Mail::shouldReceive('send')->times(101);
        $this->artisan('forms:work-mail', ['--once' => true])->assertSuccessful();
        $this->assertSame(100, DB::table('service_request_mail_deliveries')->where('status', 'sent')->count());
        $this->assertDatabaseHas('service_requests', ['id' => $id, 'mail_status' => 'pending']);
        $this->artisan('forms:work-mail', ['--once' => true])->assertSuccessful();
        $this->assertDatabaseHas('service_requests', ['id' => $id, 'mail_status' => 'sent', 'mail_attempts' => 101]);
    }
}
