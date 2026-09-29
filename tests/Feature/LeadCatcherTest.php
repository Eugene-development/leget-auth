<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\FormTestCase;

class LeadCatcherTest extends FormTestCase
{
    private function payload(): array
    {
        return ['form_id' => 'promo1-lead-catcher', 'service_type' => 'consultation',
            'phone' => '+79990000000', 'consent' => '1', 'submission_key' => (string) Str::uuid(),
            'source_url' => 'https://tenant.example/catalog'];
    }

    public function test_phone_only_request_is_committed_before_mail_and_retry_is_idempotent(): void
    {
        $owner = DB::table('users')->insertGetId(['name' => 'Owner', 'email' => 'owner@example.com', 'password' => 'unused']);
        DB::table('licenses')->insert(['id' => 'lead-site', 'domain' => 'tenant.example', 'user_id' => $owner]);
        Mail::shouldReceive('send')->once()->withArgs(function ($view, $data, $callback) {
            $this->assertSame(0, DB::transactionLevel());
            $this->assertDatabaseHas('service_requests', ['id' => $data['request_id'], 'recipient_email' => 'owner@example.com']);
            $this->assertSame('Получите бесплатную консультацию', $data['form_title']);
            $this->assertSame('Получено', $data['display_details']['Согласие на обработку персональных данных']);
            return true;
        });
        $payload = $this->payload();
        $id = $this->postJson('/api/notify/service-request', $payload)->assertOk()->json('id');
        $this->postJson('/api/notify/service-request', $payload)->assertOk()->assertJsonPath('id', $id);
        $this->assertDatabaseHas('service_requests', ['id' => $id, 'name' => '', 'phone' => '+79990000000']);
        $this->assertDatabaseCount('service_requests', 1);
        $this->assertDatabaseCount('conversions', 1);
    }

    public function test_consent_phone_and_type_are_required_and_other_forms_still_require_name(): void
    {
        Mail::shouldReceive('send')->never();
        foreach ([['consent' => null], ['consent' => '0'], ['phone' => 'abc'], ['phone' => '+7999'], ['service_type' => 'assembly'], ['form_id' => 'service-order']] as $change) {
            $this->postJson('/api/notify/service-request', array_replace($this->payload(), $change))->assertUnprocessable();
        }
        $this->assertDatabaseCount('service_requests', 0);
    }

    public function test_smtp_failure_is_accepted_and_retried_without_duplicate(): void
    {
        Mail::shouldReceive('send')->once()->andThrow(new \RuntimeException('SMTP unavailable'));
        $payload = $this->payload();
        $id = $this->postJson('/api/notify/service-request', $payload)->assertStatus(202)->assertJsonPath('mail_status', 'pending')->json('id');
        $this->postJson('/api/notify/service-request', $payload)->assertStatus(202)->assertJsonPath('id', $id);
        Mail::shouldReceive('send')->once();
        $this->travel(3)->minutes();
        $this->artisan('forms:retry-mail')->assertSuccessful();
        $this->assertDatabaseHas('service_requests', ['id' => $id, 'mail_status' => 'sent']);
        $this->assertDatabaseCount('service_requests', 1);
    }

    public function test_database_failure_never_sends_mail(): void
    {
        Mail::shouldReceive('send')->never();
        Schema::drop('conversions');
        $this->postJson('/api/notify/service-request', $this->payload())->assertStatus(500);
        $this->assertDatabaseCount('service_requests', 0);
    }
}
