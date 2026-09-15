<?php

namespace Tests\Feature;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Mockery;
use Tests\FormTestCase;

class WarrantyNotificationTest extends FormTestCase
{
    public function test_warranty_application_sends_contract_and_up_to_three_photos(): void
    {
        $message = Mockery::mock();
        $message->shouldReceive('to')->once()->andReturnSelf();
        $message->shouldReceive('subject')->once()->andReturnSelf();
        $message->shouldReceive('attachData')->times(3)->andReturnSelf();

        Mail::shouldReceive('send')->once()->withArgs(function ($view, $data, $callback) use ($message) {
            $callback($message);

            return $view === 'emails.form-submission'
                && $data['service_type_label'] === 'Гарантийное обращение'
                && $data['client_name'] === 'Анна'
                && $data['contract_number'] === 'М-2026-001'
                && $data['client_message'] === 'Повреждена фасадная панель';
        });

        $this->post('/api/notify/service-request', $this->validPayload())
            ->assertOk()
            ->assertJson(['success' => true]);
    }

    public function test_warranty_application_allows_no_photos(): void
    {
        Mail::shouldReceive('send')->once();

        $payload = $this->validPayload();
        unset($payload['photos']);

        $this->post('/api/notify/service-request', $payload)
            ->assertOk()
            ->assertJson(['success' => true]);
    }

    public function test_warranty_application_rejects_missing_contract_too_many_or_unsafe_photos(): void
    {
        Mail::shouldReceive('send')->never();

        $payload = $this->validPayload();
        unset($payload['contract_number']);
        $payload['photos'] = [
            UploadedFile::fake()->image('one.jpg'),
            UploadedFile::fake()->image('two.png'),
            UploadedFile::fake()->image('three.webp'),
            UploadedFile::fake()->create('unsafe.svg', 10, 'image/svg+xml'),
        ];

        $this->post('/api/notify/service-request', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['contract_number', 'photos', 'photos.3']);
    }

    /** @return array<string, mixed> */
    private function validPayload(): array
    {
        return [
            'service_type' => 'warranty',
            'name' => 'Анна',
            'contract_number' => 'М-2026-001',
            'message' => 'Повреждена фасадная панель',
            'source_url' => 'https://example.test/guarantees',
            'photos' => [
                UploadedFile::fake()->image('one.jpg'),
                UploadedFile::fake()->image('two.png'),
                UploadedFile::fake()->image('three.webp'),
            ],
        ];
    }
}
