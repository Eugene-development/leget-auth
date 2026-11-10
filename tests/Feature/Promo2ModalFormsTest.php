<?php

namespace Tests\Feature;

use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\FormTestCase;

class Promo2ModalFormsTest extends FormTestCase
{
    public static function forms(): array
    {
        return array_map(fn ($kind) => [$kind], ['consultation', 'showroom', 'home-designer', 'kitchen-project', 'wardrobe-project', 'design-project', 'estimate', 'action-consultation', 'partnership', 'careers']);
    }

    private function payload(string $kind): array
    {
        $payload = [
            'form_id' => 'promo2-modal-'.$kind,
            'service_type' => config('forms.promo2_modals')[$kind]['type'],
            'submission_key' => (string) Str::uuid(),
            'name' => 'Посетитель', 'phone' => '+79990000000', 'email' => 'client@example.com',
            'city' => 'Москва', 'message' => 'Размеры 2400 × 600, светлые фасады',
            'source_url' => 'https://tenant.example/kitchens', 'consent' => '1',
            'request_subject' => 'Выбранная акция или проект',
        ];
        if ($kind === 'showroom') {
            $payload += ['showroom' => 'Салон на Тестовой, 7', 'visit_time' => 'В субботу после 12:00'];
        }
        if ($kind === 'home-designer') {
            $payload += ['object_address' => 'Москва, Тестовая улица, 7', 'visit_time' => 'В субботу после 12:00'];
        }
        if ($kind === 'partnership') {
            $payload['designer_type'] = 'studio';
        }
        if ($kind === 'careers') {
            $payload['position'] = 'Дизайнер';
        }

        return $payload;
    }

    private function tenant(): void
    {
        $owner = DB::table('users')->insertGetId(['name' => 'Owner', 'email' => 'owner@example.com', 'password' => 'unused']);
        DB::table('licenses')->insert(['id' => 'promo2-test', 'domain' => 'tenant.example', 'user_id' => $owner]);
        $this->withHeaders($this->signedFormContext('tenant.example'));
    }

    #[DataProvider('forms')]
    public function test_modal_persists_fields_and_outbox_before_delivery_and_retry(string $kind): void
    {
        $this->tenant();
        $payload = $this->payload($kind);
        Mail::shouldReceive('send')->once()->withArgs(function ($view, $data, $callback) use ($payload, $kind) {
            $this->assertSame(0, DB::transactionLevel());
            $this->assertSame(config('forms.promo2_modals')[$kind]['title'], $data['form_title']);
            $this->assertSame($payload['request_subject'], $data['display_details']['Тема обращения']);
            $this->assertSame($payload['message'], $data['client_message']);
            if ($kind === 'showroom') {
                $this->assertSame($payload['showroom'], $data['display_details']['Предпочтительный салон']);
            }
            if ($kind === 'partnership') {
                $this->assertSame('Студия', $data['display_details']['Формат работы дизайнера']);
            }
            $message = \Mockery::mock();
            $message->shouldReceive('to')->once()->with('owner@example.com')->andReturnSelf();
            $message->shouldReceive('subject')->once()->andReturnSelf();
            $message->shouldReceive('replyTo')->once()->with('client@example.com');
            $callback($message);

            return $view === 'emails.form-submission';
        });
        $response = $this->postJson('/api/notify/service-request', $payload)->assertStatus(202);
        $id = $response->json('id');
        $this->assertNotEmpty($id);
        $this->assertDatabaseHas('service_requests', ['id' => $id, 'license_id' => 'promo2-test', 'form_id' => $payload['form_id'], 'service_type' => $payload['service_type'], 'name' => $payload['name'], 'phone' => $payload['phone'], 'email' => $payload['email'], 'city' => $payload['city'], 'source_url' => $payload['source_url'], 'message' => $payload['message'], 'mail_status' => 'pending', 'mail_attempts' => 0]);
        $this->assertDatabaseHas('service_request_mail_deliveries', ['service_request_id' => $id, 'recipient_email' => 'owner@example.com', 'status' => 'pending']);
        $details = json_decode(DB::table('service_requests')->where('id', $id)->value('details'), true);
        foreach (['consent', 'request_subject', 'showroom', 'visit_time', 'object_address', 'designer_type', 'position'] as $field) {
            if (isset($payload[$field])) {
                $this->assertEquals($payload[$field], $details[$field]);
            }
        }
        $this->postJson('/api/notify/service-request', $payload)->assertStatus(202)->assertJsonPath('id', $id);
        $this->drainFormMail();
        $this->postJson('/api/notify/service-request', $payload)->assertOk()->assertJsonPath('id', $id);
        $this->assertDatabaseCount('service_requests', 1);
        $this->assertDatabaseCount('conversions', $kind === 'careers' ? 0 : 1);
    }

    public function test_smtp_failure_preserves_request_and_retry_sends_without_duplicate(): void
    {
        $this->tenant();
        $payload = $this->payload('showroom');
        Mail::shouldReceive('send')->once()->andThrow(new \RuntimeException('SMTP unavailable'));
        $id = $this->postJson('/api/notify/service-request', $payload)->assertStatus(202)->json('id');
        $this->drainFormMail();
        $this->assertDatabaseHas('service_requests', ['id' => $id, 'mail_status' => 'pending', 'mail_attempts' => 1]);
        $this->postJson('/api/notify/service-request', $payload)->assertStatus(202)->assertJsonPath('id', $id);
        Mail::shouldReceive('send')->once();
        $this->travel(3)->minutes();
        $this->artisan('forms:retry-mail')->assertSuccessful();
        $this->assertDatabaseHas('service_requests', ['id' => $id, 'mail_status' => 'sent', 'mail_attempts' => 2]);
        $this->assertDatabaseCount('service_requests', 1);
    }

    public function test_invalid_or_mismatched_fields_are_rejected(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);
        Mail::shouldReceive('send')->never();
        $payload = $this->payload('showroom');
        foreach ([['consent' => '0'], ['phone' => 'bad'], ['city' => ''], ['service_type' => 'contact'], ['object_address' => 'Unexpected address'], ['request_subject' => str_repeat('x', 256)], ['form_id' => 'promo2-modal-unknown'], ['form_id' => ['invalid']], ['submission_key' => null]] as $override) {
            $this->postJson('/api/notify/service-request', array_replace($payload, $override))->assertUnprocessable();
        }
        $this->postJson('/api/notify/service-request', array_replace($this->payload('home-designer'), ['object_address' => '']))->assertUnprocessable()->assertJsonValidationErrors('object_address');
        $this->postJson('/api/notify/service-request', array_replace($this->payload('partnership'), ['designer_type' => 'unknown']))->assertUnprocessable()->assertJsonValidationErrors('designer_type');
        $this->postJson('/api/notify/service-request', array_replace($this->payload('careers'), ['email' => '']))->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->assertDatabaseCount('service_requests', 0);
    }
}
