<?php

declare(strict_types=1);

namespace App\Notifications\Channels;

use App\Notifications\ResetPasswordNotification;
use Illuminate\Notifications\Channels\MailChannel;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

final class PasswordResetMailChannel
{
    public function __construct(private MailChannel $mail) {}

    public function send(object $notifiable, #[\SensitiveParameter] ResetPasswordNotification $notification): mixed
    {
        try {
            return $this->mail->send($notifiable, $notification);
        } catch (Throwable $e) {
            // SMTP failures may contain the rendered bearer link. Keep raw exceptions
            // out of queue logs and failed_jobs; retry only a sanitized exception.
            Log::error('Password recovery mail delivery failed.', ['exception' => $e::class]);
            throw new RuntimeException('Password recovery email delivery failed.');
        }
    }
}
