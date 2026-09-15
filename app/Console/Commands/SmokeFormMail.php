<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Http\Controllers\NotificationController;
use App\Services\SmartCaptchaService;
use Illuminate\Console\Command;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Ramsey\Uuid\Uuid;

/** Explicit operator-only smoke test. Never accessible as an HTTP route. */
final class SmokeFormMail extends Command
{
    protected $signature = 'forms:smoke-mail {--recipient=} {--run=} {--form=}';

    protected $description = 'Send synthetic form submissions to the explicitly confirmed ADMIN_EMAIL';

    public function handle(NotificationController $controller, SmartCaptchaService $captcha): int
    {
        $recipient = $this->option('recipient');
        if (! $recipient || $recipient !== config('forms.recipient') || ! filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
            $this->error('Specify --recipient exactly matching ADMIN_EMAIL.');

            return self::FAILURE;
        }
        $run = $this->option('run') ?: (string) Str::uuid();
        if (! Str::isUuid($run)) {
            $this->error('--run must be a UUID');

            return self::FAILURE;
        }
        if (config('mail.default') !== 'smtp') {
            $this->error('SMTP mailer required');

            return self::FAILURE;
        }
        // Scoped only to this CLI process: no public captcha or recipient settings are changed.
        config(['forms.recipient_override' => $recipient, 'services.smartcaptcha.secret' => null]);
        $forms = [
            'service-order' => 'consultation',
            'service-hero-consultation' => 'consultation',
            'service-hero-design-project' => 'design-project',
            'service-hero-furniture-project' => 'furniture-project',
            'service-hero-assembly' => 'assembly',
            'service-hero-measurement' => 'measurement',
            'promo1-installment' => 'installment',
            'promo1-vacancy' => 'vacancy',
            'promo1-partnership' => 'partnership',
            'promo1-warranty' => 'warranty',
            'promo2-careers' => 'careers',
            'promo2-designers' => 'partnership',
            'promo1-contact' => 'contact',
            'promo2-contact' => 'contact',
            'promo3-contact' => 'contact',
            'promo3-vacancy' => 'vacancy',
            'promo1-footer-subscription' => 'subscription',
            'platform-contact' => 'contact',
            'platform-footer-subscription' => 'subscription',
        ];
        if ($this->option('form')) {
            $forms = array_intersect_key($forms, [$this->option('form') => true]);
            if (! $forms) {
                $this->error('Unknown form');

                return self::FAILURE;
            }
        }
        $this->line('Run: '.$run.'; recipient: '.$recipient);
        $failed = false;
        foreach ($forms as $form => $type) {
            $data = [
                'submission_key' => (string) Uuid::uuid5($run, $form),
                'form_id' => $form, 'service_type' => $type,
                'name' => 'TEST LEGET — не обрабатывать', 'phone' => '+79990000000',
                'email' => 'test@example.com', 'city' => 'ТЕСТ',
                'message' => 'Тестовое обращение для проверки оформления и доставки письма. Это искусственные данные, связываться с клиентом не нужно.',
                'source_url' => 'https://forms-test.invalid/'.$form,
            ];
            if ($type === 'subscription') {
                unset($data['name'], $data['phone'], $data['city'], $data['message']);
            }
            if ($type === 'warranty') {
                $data['contract_number'] = 'TEST-NO-CONTRACT';
            }
            $files = [];
            if ($type === 'installment') {
                foreach (['passport_main', 'passport_registration', 'client_photo'] as $field) {
                    $files[$field] = $this->image();
                }
            }
            if ($type === 'warranty') {
                $files['photos'] = [$this->image()];
            }
            $request = Request::create('/api/notify/service-request', 'POST', $data, [], $files, ['HTTP_ACCEPT' => 'application/json', 'REMOTE_ADDR' => '127.0.0.1']);
            $response = $form === 'platform-contact'
                ? $controller->sendContactNotification($request, $captcha)
                : $controller->sendServiceRequestNotification($request);
            $result = $response->getData(true);
            $id = $result['id'] ?? null;
            $status = $result['mail_status'] ?? 'rejected';
            if ($id) {
                // Keep test receipts, but do not inflate real customer conversions.
                DB::table('service_requests')->where('id', $id)->where('submission_key', $data['submission_key'])->update(['status' => 'cancelled']);
                DB::table('conversions')->where('service_request_id', $id)->delete();
            }
            $this->line(json_encode(['form' => $form, 'id' => $id, 'http' => $response->getStatusCode(), 'mail_status' => $status, 'validation_fields' => array_keys($result['errors'] ?? [])], JSON_UNESCAPED_UNICODE));
            $failed = $failed || $status !== 'sent';
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    private function image(): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'leget-form-test-');
        $image = imagecreatetruecolor(280, 60);
        imagefill($image, 0, 0, imagecolorallocate($image, 255, 255, 255));
        imagestring($image, 5, 12, 20, 'TEST - NOT A DOCUMENT', imagecolorallocate($image, 0, 0, 0));
        imagepng($image, $path);
        register_shutdown_function(static fn () => is_file($path) ? unlink($path) : null);

        return new UploadedFile($path, 'TEST-NOT-A-DOCUMENT.png', 'image/png', null, true);
    }
}
