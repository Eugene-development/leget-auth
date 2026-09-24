<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\FormTestCase;

class GlassMirrorsFormTest extends FormTestCase
{
    private function payload(): array
    {
        return [
            'service_type' => 'glass-mirrors', 'form_id' => 'promo1-glass-mirrors',
            'submission_key' => (string) Str::uuid(), 'form_title' => 'Обсудим ваш проект',
            'name' => 'TEST — Стекло', 'phone' => '+79990000000', 'city' => 'Москва',
            'message' => 'Зеркало 800 × 1800 мм, с подсветкой',
            'source_url' => 'https://glass.test/steklo-i-zerkala',
        ];
    }

    private function siteContext(): array
    {
        $owner = DB::table('users')->insertGetId(['name' => 'Owner', 'email' => 'glass-owner@example.com', 'password' => 'unused']);
        DB::table('licenses')->insert(['id' => 'glass-site', 'domain' => 'glass.test', 'user_id' => $owner]);
        config(['forms.context_secret' => str_repeat('s', 64)]);
        $context = rtrim(strtr(base64_encode(json_encode(['domain' => 'glass.test', 'timestamp' => time()])), '+/', '-_'), '=');

        return ['X-Leget-Form-Context' => $context.'.'.hash_hmac('sha256', $context, config('forms.context_secret'))];
    }

    public function test_saved_for_crm_and_mail_sent_to_owner_after_commit(): void
    {
        $headers = $this->siteContext();
        $payload = $this->payload();
        Mail::shouldReceive('send')->once()->withArgs(function ($view, $data, $callback) use ($payload) {
            $this->assertSame(0, DB::transactionLevel());
            $this->assertDatabaseCount('service_requests', 1);
            $this->assertSame('Стекло и Зеркала', $data['service_type_label']);
            $this->assertSame($payload['message'], $data['client_message']);
            $this->assertSame($payload['form_title'], $data['form_title']);
            $message = \Mockery::mock();
            $message->shouldReceive('to')->once()->with('glass-owner@example.com')->andReturnSelf();
            $message->shouldReceive('subject')->once()->with('LEGET — Обсудим ваш проект')->andReturnSelf();
            $callback($message);

            return $view === 'emails.form-submission';
        });
        $id = $this->post('/api/notify/service-request', $payload, $headers)->assertOk()->assertJsonPath('mail_status', 'sent')->json('id');
        $this->assertNotEmpty($id);
        $this->assertDatabaseHas('service_requests', [
            'id' => $id, 'license_id' => 'glass-site', 'channel' => 'online', 'status' => 'new',
            'service_type' => 'glass-mirrors', 'form_id' => $payload['form_id'],
            'name' => $payload['name'], 'phone' => $payload['phone'], 'city' => $payload['city'],
            'message' => $payload['message'], 'source_url' => $payload['source_url'],
            'recipient_email' => 'glass-owner@example.com', 'mail_status' => 'sent', 'mail_attempts' => 1,
        ]);
        $this->post('/api/notify/service-request', $payload, $headers)->assertOk()->assertJsonPath('id', $id);
        $this->assertDatabaseCount('service_requests', 1);
        $this->assertDatabaseCount('conversions', 1);
    }

    public function test_smtp_failure_keeps_request_and_retry_does_not_duplicate(): void
    {
        $headers = $this->siteContext();
        $payload = $this->payload();
        Mail::shouldReceive('send')->once()->andThrow(new \RuntimeException('SMTP offline'));
        $id = $this->post('/api/notify/service-request', $payload, $headers)->assertStatus(202)->assertJsonPath('mail_status', 'pending')->json('id');
        $this->post('/api/notify/service-request', $payload, $headers)->assertStatus(202)->assertJsonPath('id', $id);
        $this->assertDatabaseHas('service_requests', ['id' => $id, 'license_id' => 'glass-site', 'status' => 'new']);
        Mail::shouldReceive('send')->once();
        $this->travel(3)->minutes();
        $this->artisan('forms:retry-mail')->assertSuccessful();
        $this->assertDatabaseHas('service_requests', ['id' => $id, 'mail_status' => 'sent', 'mail_attempts' => 2]);
        $this->assertDatabaseCount('service_requests', 1);
        $this->assertDatabaseCount('conversions', 1);
    }

    public function test_invalid_contact_is_rejected_without_mail_or_record(): void
    {
        Mail::shouldReceive('send')->never();
        $this->postJson('/api/notify/service-request', array_replace($this->payload(), ['phone' => 'invalid']))
            ->assertUnprocessable()->assertJsonValidationErrors('phone');
        $this->postJson('/api/notify/service-request', array_replace($this->payload(), ['name' => '']))
            ->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->postJson('/api/notify/service-request', array_replace($this->payload(), ['message' => str_repeat('a', 2001)]))
            ->assertUnprocessable()->assertJsonValidationErrors('message');
        $this->assertDatabaseCount('service_requests', 0);
    }
}
