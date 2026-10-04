<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Notifications\Channels\PasswordResetMailChannel;
use App\Services\PasswordRecoveryToken;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Arr;
use Illuminate\Support\ConfigurationUrlParser;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use RuntimeException;

final class ResetPasswordNotification extends Notification implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public int $timeout = 30;

    public array $backoff = [60, 300, 900, 1800];

    public function __construct(#[\SensitiveParameter] public readonly string $token)
    {
        $this->onConnection('password-recovery')->onQueue('password-recovery');
    }

    public function via(object $notifiable): array
    {
        return [PasswordResetMailChannel::class];
    }

    public function shouldSend(object $notifiable, string $channel): bool
    {
        // A delayed retry must not deliver links superseded or consumed meanwhile.
        return Password::broker('users')->tokenExists($notifiable, $this->token);
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mailer = (string) config('auth.password_reset.mailer', 'smtp');
        $this->assertPrivateMailer($mailer);

        $url = rtrim((string) config('auth.password_reset.frontend_url'), '/').'/reset-password?'.http_build_query([
            'email' => $notifiable->getEmailForPasswordReset(),
            'token' => app(PasswordRecoveryToken::class)->sign($notifiable->getEmailForPasswordReset(), $this->token),
        ], '', '&', PHP_QUERY_RFC3986);

        return (new MailMessage)
            ->mailer($mailer)
            ->subject('Восстановление пароля — LEGET')
            ->view('emails.reset-password', [
                'resetUrl' => $url,
                'expiresIn' => (int) config('auth.passwords.users.expire', 60),
            ]);
    }

    /** A log transport would expose the bearer reset link, including in failover. */
    private function assertPrivateMailer(string $mailer, array $visited = []): void
    {
        if (in_array($mailer, $visited, true)) {
            throw new RuntimeException('Cyclic password reset mailer configuration.');
        }

        $config = config('mail.driver') ? config('mail') : config('mail.mailers.'.$mailer, []);
        // Match MailManager's effective configuration: MAIL_URL can replace SMTP
        // with a log transport, including inside failover/round-robin children.
        if (isset($config['url'])) {
            $config = array_merge($config, (new ConfigurationUrlParser)->parseConfiguration($config));
            $config['transport'] = Arr::pull($config, 'driver');
        }
        $transport = strtolower(Str::camel((string) ($config['transport'] ?? config('mail.driver'))));
        if ($transport === 'log') {
            throw new RuntimeException('Password reset mail must not be logged.');
        }

        if (in_array($transport, ['failover', 'roundrobin'], true)) {
            foreach ($config['mailers'] ?? [] as $child) {
                $this->assertPrivateMailer($child, [...$visited, $mailer]);
            }
        }
    }
}
