<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\FormMailDelivery;
use Illuminate\Console\Command;

final class WorkFormMail extends Command
{
    protected $signature = 'forms:work-mail {--once : Process at most 100 deliveries and exit} {--sleep=3 : Idle poll seconds}';

    protected $description = 'Deliver durable form emails with per-recipient retry and crash recovery';

    private bool $shouldStop = false;

    public function handle(FormMailDelivery $delivery): int
    {
        $this->shouldStop = false;
        if (function_exists('pcntl_async_signals')) {
            pcntl_async_signals(true);
            foreach ([SIGTERM, SIGINT] as $signal) {
                pcntl_signal($signal, function (): void {
                    $this->shouldStop = true;
                });
            }
        }
        try {
            $count = 0;
            do {
                $processed = $delivery->processOne();
                if ($this->option('once') && (! $processed || ++$count >= 100)) {
                    break;
                }
                if (! $processed && ! $this->shouldStop) {
                    sleep(max(1, min(30, (int) $this->option('sleep'))));
                }
            } while (! $this->shouldStop);
        } finally {
            if (function_exists('pcntl_signal')) {
                foreach ([SIGTERM, SIGINT] as $signal) {
                    pcntl_signal($signal, SIG_DFL);
                }
            }
        }

        return self::SUCCESS;
    }
}
