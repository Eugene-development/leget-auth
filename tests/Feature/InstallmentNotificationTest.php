<?php

namespace Tests\Feature;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Mockery;
use Tests\FormTestCase;

class InstallmentNotificationTest extends FormTestCase
{
    public function test_installment_application_sends_three_validated_attachments(): void
    {
        $message = Mockery::mock();
        $message->shouldReceive('to')->once()->andReturnSelf();
        $message->shouldReceive('subject')->once()->andReturnSelf();
        $message->shouldReceive('attachData')->times(3)->andReturnSelf();

        Mail::shouldReceive('send')->once()->withArgs(function ($view, $data, $callback) use ($message) {
            $callback($message);

            return $view === 'emails.form-submission'
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

    public function test_installment_application_is_saved_for_retry_when_mail_fails(): void
    {
        Mail::shouldReceive('send')->once()->andThrow(new \RuntimeException('Mail unavailable'));

        $this->post('/api/notify/service-request', $this->validPayload())
            ->assertStatus(202)
            ->assertJson(['success' => true, 'mail_status' => 'pending']);
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
