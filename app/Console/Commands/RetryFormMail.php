<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\FormMailDelivery;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class RetryFormMail extends Command
{
    protected $signature = 'forms:retry-mail {--limit=100} {--id=}';

    protected $description = 'Retry pending application emails; recover interrupted deliveries';

    public function handle(FormMailDelivery $delivery): int
    {
        if ($id = $this->option('id')) {
            if (! Str::isUlid($id)) {
                $this->error('Invalid request id');

                return self::FAILURE;
            }
            DB::table('service_requests')->where('id', $id)->whereIn('mail_status', ['pending', 'failed'])
                ->update(['mail_status' => 'pending', 'mail_retry_at' => now()]);
            $delivery->deliver($id);
            $status = DB::table('service_requests')->where('id', $id)->value('mail_status');
            $this->info('Mail status: '.($status ?? 'not found'));

            return $status === 'sent' ? self::SUCCESS : self::FAILURE;
        }
        DB::table('service_requests')->where('mail_status', 'sending')->where('mail_locked_at', '<', now()->subMinutes(10))
            ->where('mail_attempts', '>=', config('forms.max_attempts'))
            ->update(['mail_status' => 'failed', 'mail_locked_at' => null, 'mail_error' => 'InterruptedDelivery']);
        DB::table('service_requests')->where('mail_status', 'sending')->where('mail_locked_at', '<', now()->subMinutes(10))
            ->update(['mail_status' => 'pending', 'mail_retry_at' => now(), 'mail_locked_at' => null]);
        $ids = DB::table('service_requests')->where('mail_status', 'pending')->where('mail_retry_at', '<=', now())
            ->orderBy('created_at')->limit(max(1, min(1000, (int) $this->option('limit'))))->pluck('id');
        foreach ($ids as $id) {
            $delivery->deliver($id);
        }
        $failed = DB::table('service_requests')->where('mail_status', 'failed')->count();
        $this->info('Processed: '.$ids->count().'; permanently failed: '.$failed);

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
