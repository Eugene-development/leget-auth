<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\PasswordRecovery;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/** Identical encrypted enqueue for existing and unknown addresses. */
final class RequestPasswordRecovery implements ShouldBeEncrypted, ShouldQueue
{
    use Dispatchable, Queueable;

    public int $tries = 5;

    public int $timeout = 30;

    public array $backoff = [60, 300, 900, 1800];

    public function __construct(#[\SensitiveParameter] public readonly string $email)
    {
        $this->onConnection('password-recovery')->onQueue('password-recovery');
    }

    public function handle(PasswordRecovery $recovery): void
    {
        try {
            $recovery->issueLink($this->email);
        } catch (Throwable $e) {
            Log::error('Password recovery request processing failed.', ['exception' => $e::class]);
            throw new RuntimeException('Password recovery request could not be processed.');
        }
    }
}
