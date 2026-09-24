<?php

namespace Tests;

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
            foreach (['2026_05_21_000001_create_service_requests_table.php', '2026_08_08_000001_create_conversions_table.php', '2026_09_15_120000_unify_form_submissions.php', '2026_09_23_180000_create_crm_tables.php', '2026_09_23_180100_snapshot_crm_template_metadata.php'] as $file) {
                (require base_path('../leget-db/database/migrations/'.$file))->up();
            }
        }
    }
}
