<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\FormTestCase;

class PhonePromoTest extends FormTestCase
{
    private function payload(string $phone = '+79990000000'): array
    {
        return ['service_type' => 'promo', 'form_id' => 'service-order-promo',
            'phone' => $phone, 'submission_key' => (string) Str::uuid()];
    }

    public function test_phone_only_issues_one_code_and_mail_contains_it(): void
    {
        $payload = $this->payload();
        $first = $this->postJson('/api/notify/service-request', $payload)->assertStatus(202);
        $promo = DB::table('promo_codes')->first();
        $this->assertEquals(2, $promo->discount_value);
        $this->assertSame('activated', $promo->status);
        $this->assertSame('+79990000000', $promo->client_phone);
        $this->assertNull($promo->client_id);
        $this->assertDatabaseCount('users', 0);
        $this->assertNull($first->json('code'));
        Mail::shouldReceive('send')->once()->withArgs(function ($view, $data, $callback) use ($promo) {
            $html = view($view, $data)->render();
            $this->assertStringContainsString($promo->code, $html);
            $this->assertStringContainsString('2%', $html);
            $this->assertStringContainsString('Отправьте этот промокод', $html);

            return true;
        });
        $this->drainFormMail();
        $this->postJson('/api/notify/service-request', $payload)->assertSuccessful()->assertJsonPath('id', $first->json('id'));
        $this->postJson('/api/notify/service-request', $this->payload())->assertSuccessful();
        $this->assertDatabaseCount('promo_codes', 1);
        $this->postJson('/api/notify/service-request', $this->payload('+79990000001'))->assertSuccessful();
        $this->assertDatabaseCount('promo_codes', 2);
    }

    public function test_phone_codes_are_scoped_to_the_trusted_site_and_ignore_client_discount(): void
    {
        $owner = DB::table('users')->insertGetId(['name' => 'Owner', 'email' => 'owner@example.test', 'password' => 'unused']);
        foreach (['site-a.example', 'site-b.example'] as $domain) {
            DB::table('licenses')->insert(['id' => $domain, 'domain' => $domain, 'user_id' => $owner]);
            $this->withHeaders($this->signedFormContext($domain));
            $this->postJson('/api/notify/service-request', $this->payload() + [
                'discount_value' => '99', 'promo_code' => 'CHOSEN', 'license_id' => 'attacker',
            ])->assertSuccessful();
        }
        $this->assertDatabaseCount('promo_codes', 2);
        $this->assertSame(2, DB::table('promo_codes')->where('discount_value', 2)->count());
        $this->assertSame(2, DB::table('promo_codes')->distinct()->count('code'));
    }

    public function test_outbox_failure_rolls_back_code_as_well_as_request(): void
    {
        DB::statement('DROP TABLE service_request_mail_deliveries');
        $this->postJson('/api/notify/service-request', $this->payload())->assertStatus(500);
        $this->assertDatabaseCount('promo_codes', 0);
        $this->assertDatabaseCount('service_requests', 0);
        $this->assertDatabaseCount('promo_code_events', 0);
    }

    public function test_mail_failure_retries_the_original_code(): void
    {
        $response = $this->postJson('/api/notify/service-request', $this->payload())->assertStatus(202);
        $code = DB::table('promo_codes')->value('code');
        Mail::shouldReceive('send')->once()->andThrow(new \RuntimeException('SMTP unavailable'));
        $this->drainFormMail();
        $this->assertDatabaseHas('service_requests', ['id' => $response->json('id'), 'mail_status' => 'pending']);
        Mail::shouldReceive('send')->once()->withArgs(function ($view, $data) use ($code) {
            $this->assertSame($code, $data['details']['promo']['code']);

            return true;
        });
        $this->travel(3)->minutes();
        $this->drainFormMail();
        $this->assertDatabaseCount('promo_codes', 1);
        $this->assertDatabaseHas('service_requests', ['id' => $response->json('id'), 'mail_status' => 'sent']);
    }

    public function test_bad_phone_is_rejected_and_issuance_failure_rolls_back_request(): void
    {
        $this->postJson('/api/notify/service-request', $this->payload('bad'))->assertUnprocessable();
        DB::statement('DROP TABLE promo_code_events');
        $this->postJson('/api/notify/service-request', $this->payload())->assertStatus(500);
        $this->assertDatabaseCount('service_requests', 0);
        $this->assertDatabaseCount('promo_codes', 0);
        $this->assertDatabaseCount('service_request_mail_deliveries', 0);
    }
}
