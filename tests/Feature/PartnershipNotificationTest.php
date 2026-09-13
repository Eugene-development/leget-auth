<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class PartnershipNotificationTest extends TestCase
{
    private function supplier(array $overrides = []): array
    {
        return array_merge([
            'service_type' => 'partnership',
            'partnership_status' => 'supplier',
            'name' => 'Анна',
            'company' => 'Фабрика',
        ], $overrides);
    }

    public function test_supplier_can_leave_only_email_and_all_details_reach_mail(): void
    {
        Mail::shouldReceive('send')->once()->withArgs(function ($view, $data, $callback) {
            return $view === 'emails.service-request'
                && $data['client_email'] === 'partner@example.com'
                && $data['company'] === 'Фабрика'
                && $data['client_name'] === 'Анна'
                && $data['phone'] === null
                && $data['partnership_status'] === 'Вы фабрика или поставщик';
        });
        $this->postJson('/api/notify/service-request', $this->supplier(['email' => 'partner@example.com']))
            ->assertOk()->assertJson(['success' => true]);
    }

    public function test_supplier_can_leave_only_phone_or_both_contacts(): void
    {
        Mail::shouldReceive('send')->twice();
        $this->postJson('/api/notify/service-request', $this->supplier(['phone' => '+79991234567']))->assertOk();
        $this->postJson('/api/notify/service-request', $this->supplier(['phone' => '+79991234567', 'email' => 'partner@example.com']))->assertOk();
    }

    public function test_supplier_needs_at_least_one_valid_contact(): void
    {
        Mail::shouldReceive('send')->never();
        $this->postJson('/api/notify/service-request', $this->supplier())
            ->assertUnprocessable()->assertJsonValidationErrors(['phone', 'email']);
        $this->postJson('/api/notify/service-request', $this->supplier(['email' => 'invalid']))
            ->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->postJson('/api/notify/service-request', $this->supplier(['phone' => '+']))
            ->assertUnprocessable()->assertJsonValidationErrors('phone');
    }

    public function test_referral_includes_city_comment_and_requires_phone(): void
    {
        Mail::shouldReceive('send')->once()->withArgs(fn ($view, $data, $callback) =>
            $data['city'] === 'Москва' && $data['client_message'] === 'Хочу сотрудничать'
            && $data['partnership_status'] === 'Вы приводите клиентов');
        $payload = ['service_type' => 'partnership', 'partnership_status' => 'referral', 'name' => 'Иван', 'city' => 'Москва', 'message' => 'Хочу сотрудничать'];
        $this->postJson('/api/notify/service-request', $payload + ['email' => 'partner@example.com'])
            ->assertUnprocessable()->assertJsonValidationErrors('phone');
        $this->postJson('/api/notify/service-request', $payload + ['phone' => '+79991234567'])->assertOk();
    }

    public function test_other_services_still_require_phone(): void
    {
        Mail::shouldReceive('send')->never();
        $this->postJson('/api/notify/service-request', ['service_type' => 'consultation', 'name' => 'Иван', 'email' => 'partner@example.com', 'partnership_status' => 'supplier'])
            ->assertUnprocessable()->assertJsonValidationErrors('phone');
    }

    public function test_mail_failure_does_not_report_success(): void
    {
        Mail::shouldReceive('send')->once()->andThrow(new \RuntimeException('Mail unavailable'));
        $this->postJson('/api/notify/service-request', $this->supplier(['email' => 'partner@example.com']))
            ->assertStatus(500)->assertJson(['success' => false]);
    }
}
