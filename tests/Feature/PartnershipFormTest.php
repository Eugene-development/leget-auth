<?php

namespace Tests\Feature;

use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\FormTestCase;

class PartnershipFormTest extends FormTestCase
{
    public static function statuses(): array
    {
        return [
            ['referral', 'Вы приводите клиентов', false],
            ['manufacturer', 'Вы фабрика изготовитель', true],
            ['supplier', 'Вы поставщик', true],
            ['other', 'Иное', false],
        ];
    }

    #[DataProvider('statuses')]
    public function test_status_survives_storage_mail_failure_and_retry(string $status, string $label, bool $business): void
    {
        $payload = [
            'service_type' => 'partnership', 'form_id' => 'promo1-partnership',
            'submission_key' => (string) Str::uuid(), 'partnership_status' => $status,
            'name' => 'Тестовый партнёр', 'phone' => $business ? null : '+79990000000',
            'email' => $business ? 'partner@example.com' : null,
            'company' => $business ? 'Тестовая компания' : null,
            'city' => $business ? null : 'Москва', 'message' => 'Сотрудничество',
        ];
        Mail::shouldReceive('send')->once()->andThrow(new \RuntimeException('SMTP unavailable'));
        $response = $this->postJson('/api/notify/service-request', $payload)
            ->assertStatus(202)->assertJsonPath('mail_status', 'pending');
        $id = $response->json('id');
        $row = DB::table('service_requests')->where('id', $id)->first();
        $this->assertSame($status, json_decode($row->details, true)['partnership_status']);
        foreach (['name', 'email', 'city', 'message'] as $field) {
            $this->assertSame($payload[$field], $row->$field);
        }
        $this->assertSame($payload['phone'] ?? '', $row->phone);
        $this->assertSame($payload['company'], json_decode($row->details, true)['company']);
        $this->postJson('/api/notify/service-request', $payload)->assertStatus(202)->assertJsonPath('id', $id);
        Mail::shouldReceive('send')->once()->withArgs(function ($view, $data, $callback) use ($label, $id) {
            $this->assertSame(0, DB::transactionLevel());
            $this->assertDatabaseHas('service_requests', ['id' => $id, 'mail_status' => 'sending']);
            $this->assertSame($label, $data['display_details']['Статус отправителя']);
            $this->assertSame('Сотрудничество', $data['client_message']);
            $this->assertStringContainsString($label, view($view, $data)->render());
            $message = \Mockery::mock();
            $message->shouldReceive('to')->once()->with('info@novostroy.org')->andReturnSelf();
            $message->shouldReceive('subject')->once()->andReturnSelf();
            $message->shouldReceive('replyTo')->zeroOrMoreTimes()->with('partner@example.com');
            $callback($message);

            return true;
        });
        $this->travel(3)->minutes();
        $this->artisan('forms:retry-mail')->assertSuccessful();
        $this->postJson('/api/notify/service-request', $payload)->assertOk()->assertJsonPath('id', $id);
        $this->assertDatabaseCount('service_requests', 1);
        $this->assertDatabaseHas('service_requests', ['id' => $id, 'mail_status' => 'sent']);
    }

    public function test_invalid_status_and_missing_business_contacts_are_rejected(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);
        Mail::shouldReceive('send')->never();
        $payload = ['service_type' => 'partnership', 'form_id' => 'promo1-partnership', 'name' => 'Test', 'phone' => '+79990000000'];
        foreach ([null, '', 'unknown'] as $status) {
            $this->postJson('/api/notify/service-request', $payload + ['partnership_status' => $status])
                ->assertUnprocessable()->assertJsonValidationErrors('partnership_status');
        }
        foreach (['manufacturer', 'supplier'] as $status) {
            $this->postJson('/api/notify/service-request', $payload + ['partnership_status' => $status])
                ->assertUnprocessable()->assertJsonValidationErrors('company');
            $this->postJson('/api/notify/service-request', array_replace($payload, [
                'partnership_status' => $status, 'company' => 'Test', 'phone' => null,
            ]))->assertUnprocessable()->assertJsonValidationErrors(['phone', 'email']);
        }
        $this->assertDatabaseCount('service_requests', 0);
    }
}
