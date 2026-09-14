<?php

namespace Tests\Feature;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Mockery;
use Tests\TestCase;

class InstallmentNotificationTest extends TestCase
{
    public function test_installment_application_sends_three_validated_attachments(): void
    {
        $message = Mockery::mock();
        $message->shouldReceive('to')->once()->andReturnSelf();
        $message->shouldReceive('subject')->once()->andReturnSelf();
        $message->shouldReceive('attach')->times(3)->andReturnSelf();

        Mail::shouldReceive('send')->once()->withArgs(function ($view, $data, $callback) use ($message) {
            $callback($message);

            return $view === 'emails.service-request'
                && $data['service_type_label'] === 'Рассрочка'
                && str_contains($data['client_message'], 'Место работы: ООО Мебель');
        });

        $this->post('/api/notify/service-request', $this->validPayload())
            ->assertOk()
            ->assertJson(['success' => true]);
    }

    public function test_installment_application_rejects_missing_or_unsafe_files(): void
    {
        Mail::shouldReceive('send')->never();

        $payload = $this->validPayload();
        unset($payload['passport_registration']);
        $payload['client_photo'] = UploadedFile::fake()->create(
            'photo.svg',
            10,
            'image/svg+xml'
        );

        $this->post('/api/notify/service-request', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['passport_registration', 'client_photo']);
    }

    public function test_installment_application_does_not_report_success_when_attachments_cannot_be_emailed(): void
    {
        Mail::shouldReceive('send')->once()->andThrow(new \RuntimeException('Mail unavailable'));

        $this->post('/api/notify/service-request', $this->validPayload())
            ->assertStatus(500)
            ->assertJson(['success' => false]);
    }

    /** @return array<string, mixed> */
    private function validPayload(): array
    {
        return [
            'service_type' => 'installment',
            'name' => 'Иванов Иван Иванович',
            'phone' => '+79991234567',
            'message' => "Срок: 12 месяцев\nМесто работы: ООО Мебель\nЕжемесячный доход: 80 000 ₽",
            'city' => 'Москва',
            'passport_main' => UploadedFile::fake()->image('passport-main.jpg'),
            'passport_registration' => UploadedFile::fake()->image('passport-registration.jpg'),
            'client_photo' => UploadedFile::fake()->image('client-photo.jpg'),
        ];
    }
}
