<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\FormMailDelivery;
use App\Services\FormMailOutbox;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class RetryFormMail extends Command
{
    protected $signature = 'forms:retry-mail {--limit=100} {--id=}';

    protected $description = 'Process a bounded mail batch or retry unsent recipients of one submission';

    public function handle(FormMailDelivery $delivery, FormMailOutbox $outbox): int
    {
        if ($id = $this->option('id')) {
            if (! Str::isUlid($id)) {
                $this->error('Invalid request id');

                return self::FAILURE;
            }
            $outbox->retry($id);
            $delivery->deliver($id);
            $status = DB::table('service_requests')->where('id', $id)->value('mail_status');
            $this->info('Mail status: '.($status ?? 'not found'));

            return $status === 'sent' ? self::SUCCESS : self::FAILURE;
        }
        $limit = max(1, min(1000, (int) $this->option('limit')));
        $count = 0;
        while ($count < $limit && $delivery->processOne()) {
            $count++;
        }
        $failed = DB::table('service_requests')->where('mail_status', 'failed')->count();
        $this->info('Processed: '.$count.'; permanently failed: '.$failed);

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
