<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\FormTestCase;

class CatalogServiceFormsTest extends FormTestCase
{
    public function test_site_consultation_saves_object_details_and_retries_mail_without_a_duplicate(): void
    {
        $owner = DB::table('users')->insertGetId(['name' => 'Owner', 'email' => 'owner@example.com', 'password' => 'unused']);
        DB::table('licenses')->insert(['id' => 'site-consultation-test', 'domain' => 'tenant.example', 'user_id' => $owner]);
        $payload = [
            'service_type' => 'consultation',
            'form_id' => 'promo1-contacts-site-consultation',
            'submission_key' => (string) Str::uuid(),
            'form_title' => 'Заявка на встречу',
            'name' => 'TEST CLIENT',
            'phone' => '+79990000000',
            'city' => 'Москва',
            'object_address' => 'г. Москва, ул. Тестовая, д. 7',
            'visit_time' => 'Во вторник после 15:00',
            'message' => 'Обсудить кухню',
            'source_url' => 'https://tenant.example/contacts',
        ];

        Mail::shouldReceive('send')->once()->withArgs(function ($view, $data, $callback) {
            $this->assertSame(0, DB::transactionLevel());
            $this->assertSame('г. Москва, ул. Тестовая, д. 7', $data['display_details']['Адрес объекта']);
            $this->assertSame('Во вторник после 15:00', $data['display_details']['Удобное время встречи']);
            $message = \Mockery::mock();
            $message->shouldReceive('to')->once()->with('owner@example.com')->andReturnSelf();
            $message->shouldReceive('subject')->once()->with('LEGET — Заявка на встречу')->andReturnSelf();
            $callback($message);

            return $view === 'emails.form-submission';
        })->andThrow(new \RuntimeException('SMTP unavailable'));

        $response = $this->postJson('/api/notify/service-request', $payload)
            ->assertStatus(202)->assertJsonPath('mail_status', 'pending');
        $id = $response->json('id');
        $this->assertDatabaseHas('service_requests', [
            'id' => $id, 'service_type' => 'consultation', 'form_id' => $payload['form_id'],
            'recipient_email' => 'owner@example.com', 'message' => $payload['message'],
        ]);
        $details = json_decode(DB::table('service_requests')->where('id', $id)->value('details'), true);
        $this->assertSame($payload['object_address'], $details['object_address']);
        $this->assertSame($payload['visit_time'], $details['visit_time']);
        $this->postJson('/api/notify/service-request', $payload)->assertStatus(202)->assertJsonPath('id', $id);
        Mail::shouldReceive('send')->once();
        $this->travel(3)->minutes();
        $this->artisan('forms:retry-mail')->assertSuccessful();
        $this->assertDatabaseHas('service_requests', ['id' => $id, 'mail_status' => 'sent', 'mail_attempts' => 2]);
        $this->assertDatabaseCount('service_requests', 1);
        $this->assertDatabaseCount('conversions', 1);
    }

    public function test_site_consultation_requires_an_address_and_rejects_fields_on_other_forms(): void
    {
        Mail::shouldReceive('send')->never();
        $payload = [
            'service_type' => 'consultation', 'form_id' => 'promo1-contacts-site-consultation',
            'name' => 'TEST', 'phone' => '+79990000000',
        ];
        $this->postJson('/api/notify/service-request', $payload)
            ->assertUnprocessable()->assertJsonValidationErrors('object_address');
        $this->postJson('/api/notify/service-request', $payload + ['object_address' => 'Адрес'])
            ->assertUnprocessable()->assertJsonValidationErrors('object_address');
        $this->postJson('/api/notify/service-request', array_replace($payload, [
            'service_type' => 'measurement', 'object_address' => 'г. Москва, ул. Тестовая, 7',
        ]))->assertUnprocessable()->assertJsonValidationErrors('service_type');
        $this->postJson('/api/notify/service-request', array_replace($payload, [
            'form_id' => 'service-hero-consultation', 'object_address' => 'г. Москва, ул. Тестовая, 7',
        ]))->assertUnprocessable()->assertJsonValidationErrors('object_address');
        $this->assertDatabaseCount('service_requests', 0);
    }

    public static function forms(): array
    {
        $cases = [];
        foreach (['consultation', 'design-project', 'furniture-project', 'assembly', 'measurement'] as $type) {
            $cases['hero-'.$type] = [$type, 'service-hero-'.$type, '/'.$type];
            $cases['drawer-'.$type] = [$type, 'service-order-'.$type, '/'.$type];
        }
        $cases['countertop'] = ['countertop-estimate', 'promo1-countertop-estimate', '/stoleshnica'];
        $cases['appliances'] = ['appliance-selection', 'promo1-appliance-selection', '/bytovaya-tehnika'];
        $cases['plumbing'] = ['plumbing-selection', 'promo1-plumbing-selection', '/santehnika'];

        return $cases;
    }

    #[DataProvider('forms')]
    public function test_form_persists_all_fields_and_mails_after_commit(string $type, string $form, string $path): void
    {
        $owner = DB::table('users')->insertGetId(['name' => 'Owner', 'email' => 'owner@example.com', 'password' => 'unused']);
        DB::table('licenses')->insert(['id' => 'catalog-test', 'domain' => 'tenant.example', 'user_id' => $owner]);
        $payload = [
            'service_type' => $type, 'form_id' => $form,
            'submission_key' => (string) Str::uuid(), 'form_title' => 'TEST — '.$type,
            'name' => 'TEST FORM', 'phone' => '+79990000000',
            'city' => 'Тестовый город', 'source_url' => 'https://tenant.example'.$path,
        ];
        if ($type === 'countertop-estimate') {
            $payload['dimensions'] = ['2400 × 600 мм', '1200 × 650 мм'];
        } elseif (! str_starts_with($form, 'service-hero-')) {
            $payload['message'] = 'TEST пожелания к заказу';
        }

        Mail::shouldReceive('send')->once()->withArgs(function ($view, $data, $callback) use ($payload) {
            $this->assertSame(0, DB::transactionLevel(), 'Email must follow commit');
            $this->assertSame($payload['form_title'], $data['form_title']);
            $this->assertSame($payload['name'], $data['client_name']);
            $this->assertSame($payload['phone'], $data['phone']);
            $this->assertSame($payload['city'], $data['city']);
            $this->assertSame($payload['source_url'], $data['source_url']);
            $this->assertSame($payload['message'] ?? null, $data['client_message']);
            if (isset($payload['dimensions'])) {
                $this->assertSame("1. 2400 × 600 мм\n2. 1200 × 650 мм", $data['display_details']['Размеры']);
            }
            $message = \Mockery::mock();
            $message->shouldReceive('to')->once()->with('owner@example.com')->andReturnSelf();
            $message->shouldReceive('subject')->once()->with('LEGET — '.$payload['form_title'])->andReturnSelf();
            $callback($message);

            return $view === 'emails.form-submission';
        });
        $response = $this->post('/api/notify/service-request', $payload)->assertOk()->assertJsonPath('mail_status', 'sent');
        $id = $response->json('id');
        $this->assertNotEmpty($id);
        $this->assertDatabaseHas('service_requests', [
            'id' => $id, 'service_type' => $type, 'form_id' => $form,
            'name' => $payload['name'], 'phone' => $payload['phone'], 'city' => $payload['city'],
            'message' => $payload['message'] ?? null, 'source_url' => $payload['source_url'],
            'recipient_email' => 'owner@example.com', 'mail_attempts' => 1, 'mail_status' => 'sent',
        ]);
        $details = json_decode(DB::table('service_requests')->where('id', $id)->value('details'), true);
        $this->assertSame($payload['form_title'], $details['form_title']);
        $this->assertSame($payload['dimensions'] ?? null, $details['dimensions'] ?? null);
        $this->post('/api/notify/service-request', $payload)->assertOk()->assertJsonPath('id', $id);
        $this->assertDatabaseCount('service_requests', 1);
        $this->assertDatabaseCount('conversions', 1);
    }
}
