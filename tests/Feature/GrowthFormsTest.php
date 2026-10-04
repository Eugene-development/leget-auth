<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\FormTestCase;

class GrowthFormsTest extends FormTestCase
{
    private string $site;

    private string $otherSite;

    private string $token;

    private string $version;

    protected function setUp(): void
    {
        parent::setUp();
        (require base_path('../leget-db/database/migrations/2026_10_03_100001_growth_selections_and_estimates.php'))->up();
        config(['forms.context_secret' => str_repeat('s', 32)]);
        $owner = DB::table('users')->insertGetId(['name' => 'Owner', 'email' => 'owner@example.com', 'password' => 'unused']);
        $this->site = (string) Str::ulid();
        $this->otherSite = (string) Str::ulid();
        $this->token = str_repeat('a', 48);
        $this->version = (string) Str::uuid();
        DB::table('licenses')->insert([['id' => $this->site, 'domain' => 'tenant.example', 'user_id' => $owner], ['id' => $this->otherSite, 'domain' => 'other.example', 'user_id' => $owner]]);
        DB::table('project_selections')->insert(['id' => (string) Str::ulid(), 'license_id' => $this->site, 'token' => $this->token, 'edit_token_hash' => str_repeat('b', 64), 'creation_key' => (string) Str::uuid(), 'payload_hash' => str_repeat('c', 64), 'title' => 'Кухня Анны', 'item_refs' => json_encode(['project:public-one', 'material:category:quartz']), 'expires_at' => now()->addDays(30), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('kitchen_estimate_settings')->insert(['license_id' => $this->site, 'enabled' => true, 'version' => $this->version, 'rules' => json_encode(['base_min' => 10000, 'base_max' => 15000, 'equipment_min' => 5000, 'equipment_max' => 7000, 'layouts' => ['straight' => 1, 'l' => 1.2, 'u' => 1.3, 'island' => 1.4], 'materials' => [['key' => 'paint', 'label' => 'Эмаль', 'multiplier' => 2]], 'note' => 'Тестовые условия']), 'created_at' => now(), 'updated_at' => now()]);
    }

    public function test_selection_is_saved_once_with_site_recipient_and_mail_failure_can_retry(): void
    {
        Mail::shouldReceive('send')->once()->andThrow(new \RuntimeException('SMTP unavailable'));
        $payload = $this->payload('selection-estimate') + ['selection_token' => $this->token, 'attribution' => json_encode(['utm_source' => 'direct', 'utm_medium' => 'cpc', 'utm_campaign' => 'kitchen', 'yclid' => '123456', 'metrika_client_id' => '456789'])];
        $response = $this->withHeaders($this->siteHeaders())->postJson('/api/notify/service-request', $payload)->assertStatus(202)->assertJsonPath('mail_status', 'pending');
        $this->postJson('/api/notify/service-request', $payload)->assertStatus(202)->assertJsonPath('id', $response->json('id'));
        $row = DB::table('service_requests')->where('id', $response->json('id'))->first();
        $details = json_decode($row->details, true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('Кухня Анны', $details['selection']['title']);
        $this->assertSame('123456', $details['attribution']['yclid']);
        $this->assertSame($this->site, $row->license_id);
        $this->assertSame('owner@example.com', $row->recipient_email);
        $this->assertDatabaseCount('service_requests', 1);
        $this->assertDatabaseCount('conversions', 1);
        Mail::shouldReceive('send')->once()->withArgs(function ($view, $data) {
            return $data['display_details']['Подборка'] === 'Кухня Анны';
        });
        $this->travel(3)->minutes();
        $this->artisan('forms:retry-mail')->assertSuccessful();
        $this->assertDatabaseHas('service_requests', ['id' => $row->id, 'mail_status' => 'sent', 'mail_attempts' => 2]);
    }

    public function test_foreign_revoked_and_unsigned_selection_context_never_accept_or_mail(): void
    {
        Mail::shouldReceive('send')->never();
        $payload = $this->payload('selection-estimate') + ['selection_token' => $this->token];
        $this->postJson('/api/notify/service-request', $payload)->assertUnprocessable();
        $this->withHeaders($this->siteHeaders('other.example'))->postJson('/api/notify/service-request', $payload)->assertUnprocessable();
        DB::table('project_selections')->where('token', $this->token)->update(['revoked_at' => now()]);
        $this->withHeaders($this->siteHeaders())->postJson('/api/notify/service-request', $payload)->assertUnprocessable();
        $this->assertDatabaseCount('service_requests', 0);
    }

    public function test_kitchen_inputs_are_preserved_and_range_is_calculated_on_server(): void
    {
        Mail::shouldReceive('send')->once();
        $payload = $this->payload('kitchen-estimate') + ['estimate_version' => $this->version, 'estimate_inputs' => json_encode(['run_cm' => 300, 'layout' => 'l', 'material' => 'paint', 'equipment' => 1]), 'min' => 1, 'max' => 2];
        $response = $this->withHeaders($this->siteHeaders())->postJson('/api/notify/service-request', $payload)->assertOk();
        $details = json_decode(DB::table('service_requests')->where('id', $response->json('id'))->value('details'), true);
        $this->assertSame(300, $details['estimate']['inputs']['run_cm']);
        $this->assertSame(77000, $details['estimate']['min']);
        $this->assertSame(115000, $details['estimate']['max']);
        $this->assertSame('Эмаль', $details['estimate']['material_label']);
        DB::table('kitchen_estimate_settings')->where('license_id', $this->site)->update(['version' => (string) Str::uuid()]);
        // Already accepted request stays idempotent even after a rule change.
        $this->postJson('/api/notify/service-request', $payload)->assertOk()->assertJsonPath('id', $response->json('id'));
        $payload['submission_key'] = (string) Str::uuid();
        $this->postJson('/api/notify/service-request', $payload)->assertUnprocessable();
        $this->assertDatabaseCount('service_requests', 1);
    }

    public function test_attribution_whitelist_rejects_form_contents_and_non_numeric_ids(): void
    {
        Mail::shouldReceive('send')->never();
        foreach ([['name' => 'Private'], ['yclid' => 'not-digits'], ['utm_campaign' => str_repeat('x', 121)]] as $invalid) {
            $this->postJson('/api/notify/service-request', $this->payload('consultation') + ['attribution' => json_encode($invalid)])->assertUnprocessable();
        }
        $this->assertDatabaseCount('service_requests', 0);
    }

    public function test_order_question_is_unified_request_with_untrusted_reference_only(): void
    {
        Mail::shouldReceive('send')->once();
        $response = $this->postJson('/api/notify/service-request', $this->payload('order-question') + ['order_id' => 'customer-visible-order', 'order_title' => 'Кухня', 'email' => 'client@example.com'])->assertOk();
        $row = DB::table('service_requests')->where('id', $response->json('id'))->first();
        $this->assertSame(['form_title' => 'Тестовая форма', 'order_id' => 'customer-visible-order', 'order_title' => 'Кухня'], json_decode($row->details, true));
        $this->assertDatabaseCount('conversions', 0);
        $this->postJson('/api/notify/service-request', array_replace($this->payload('order-question'), ['message' => '']))->assertUnprocessable();
    }

    private function payload(string $type): array
    {
        return ['service_type' => $type, 'form_id' => $type, 'form_title' => 'Тестовая форма', 'name' => 'Анна', 'phone' => '+79990000000', 'message' => 'Нужен расчёт', 'submission_key' => (string) Str::uuid()];
    }

    private function siteHeaders(string $domain = 'tenant.example'): array
    {
        $payload = rtrim(strtr(base64_encode(json_encode(['domain' => $domain, 'timestamp' => time()])), '+/', '-_'), '=');

        return ['X-Leget-Form-Context' => $payload.'.'.hash_hmac('sha256', $payload, config('forms.context_secret'))];
    }
}
