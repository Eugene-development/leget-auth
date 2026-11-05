<?php

namespace Tests;

use App\Services\FormMailDelivery;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

abstract class FormTestCase extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['forms.recipient' => 'info@novostroy.org', 'forms.recipient_override' => null, 'forms.attachment_disk' => 'form-tests']);
        Storage::fake('form-tests');
        if (! Schema::hasTable('licenses')) {
            Schema::create('licenses', function (Blueprint $table) {
                $table->string('id')->primary();
                $table->string('domain');
                $table->unsignedBigInteger('user_id');
            });
        }
        if (! Schema::hasTable('service_requests')) {
            foreach (['2026_05_21_000001_create_service_requests_table.php', '2026_08_08_000001_create_conversions_table.php', '2026_09_15_120000_unify_form_submissions.php', '2026_09_23_180000_create_crm_tables.php', '2026_09_23_180100_snapshot_crm_template_metadata.php', '2026_11_03_120000_create_service_request_mail_deliveries.php'] as $file) {
                (require base_path('../leget-db/database/migrations/'.$file))->up();
            }
        }
        if (! Schema::hasTable('promo_codes')) {
            foreach (['2026_08_30_100001_create_ad_attributions_table.php', '2026_08_30_100002_create_promo_codes_table.php', '2026_08_30_100004_create_promo_code_events_table.php', '2026_11_04_120000_allow_phone_promo_codes.php'] as $file) {
                (require base_path('../leget-db/database/migrations/'.$file))->up();
            }
        }
    }

    protected function signedFormContext(string $domain): array
    {
        config(['forms.context_secret' => str_repeat('s', 64)]);
        $payload = rtrim(strtr(base64_encode(json_encode(['domain' => $domain, 'timestamp' => time()])), '+/', '-_'), '=');

        return ['X-Leget-Form-Context' => $payload.'.'.hash_hmac('sha256', $payload, config('forms.context_secret'))];
    }

    protected function drainFormMail(): void
    {
        $delivery = app(FormMailDelivery::class);
        for ($i = 0; $i < 1000 && $delivery->processOne(); $i++) {
        }
    }
}
