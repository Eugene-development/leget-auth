<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Mockery;
use Tests\FormTestCase;

class FormMailPresentationTest extends FormTestCase
{
    public function test_visible_heading_is_saved_and_rendered_without_technical_identifiers(): void
    {
        Mail::shouldReceive('send')->once()->withArgs(function ($view, $data, $callback) {
            $html = view($view, $data)->render();
            $this->assertSame('Кухня вашей мечты', $data['form_title']);
            $this->assertStringContainsString('Кухня вашей мечты', $html);
            $this->assertStringNotContainsString($data['request_id'], $html);
            $this->assertStringNotContainsString('promo1-contact', $html);
            $this->assertStringContainsString('&lt;script&gt;', $html);
            $this->assertStringNotContainsString('<script>', $html);
            $this->assertStringContainsString('background-color:#ffffff', $html);
            $message = Mockery::mock();
            $message->shouldReceive('to')->once()->with('info@novostroy.org')->andReturnSelf();
            $message->shouldReceive('subject')->once()->with('LEGET — Кухня вашей мечты')->andReturnSelf();
            $callback($message);

            return true;
        });
        $this->postJson('/api/notify/service-request', [
            'service_type' => 'contact', 'form_id' => 'promo1-contact',
            'form_title' => "<b>Кухня вашей мечты</b>\r\n", 'name' => '<script>test</script>',
            'phone' => '+79990000000', 'message' => '<script>message</script>',
        ])->assertOk()->assertJsonPath('mail_status', 'sent');
        $this->assertStringContainsString('form_title', DB::table('service_requests')->value('details'));
    }

    public function test_legacy_subscription_uses_its_russian_heading_and_omits_empty_sections(): void
    {
        Mail::shouldReceive('send')->once()->withArgs(function ($view, $data, $callback) {
            $html = view($view, $data)->render();
            $this->assertSame('Будь в курсе событий', $data['form_title']);
            $this->assertStringNotContainsString('platform-footer-subscription', $html);
            $this->assertStringNotContainsString('СООБЩЕНИЕ', $html);
            $this->assertStringContainsString('mailto:test@example.com', $html);

            return true;
        });
        $this->postJson('/api/notify/service-request', ['service_type' => 'subscription', 'form_id' => 'platform-footer-subscription', 'email' => 'test@example.com'])->assertOk();
    }

    public function test_warranty_details_are_labelled_in_russian(): void
    {
        Mail::shouldReceive('send')->once()->withArgs(function ($view, $data, $callback) {
            $html = view($view, $data)->render();
            $this->assertStringContainsString('Номер договора', $html);
            $this->assertStringNotContainsString('contract_number', $html);

            return true;
        });
        $this->postJson('/api/notify/service-request', ['service_type' => 'warranty', 'name' => 'Анна', 'contract_number' => 'Д-15'])->assertOk();
    }

    public function test_countertop_dimensions_are_labelled_numbered_and_escaped(): void
    {
        Mail::shouldReceive('send')->once()->withArgs(function ($view, $data, $callback) {
            $html = view($view, $data)->render();
            $this->assertStringContainsString('Размеры', $html);
            $this->assertStringContainsString('1. 2400 × 600 мм', $html);
            $this->assertStringContainsString('2. &lt;script&gt;', $html);
            $this->assertStringNotContainsString('<script>', $html);

            return true;
        });
        $this->postJson('/api/notify/service-request', [
            'service_type' => 'countertop-estimate',
            'form_id' => 'promo1-countertop-estimate',
            'name' => 'Анна',
            'phone' => '+79990000000',
            'dimensions' => ['2400 × 600 мм', '<script>'],
        ])->assertOk();
    }
}
