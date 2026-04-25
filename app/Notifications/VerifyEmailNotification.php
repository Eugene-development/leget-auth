<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;
use Illuminate\Notifications\Messages\MailMessage;

class VerifyEmailNotification extends Notification
{
    /**
     * Get the notification's delivery channels.
     */
    public function via($notifiable): array
    {
        return ['mail'];
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail($notifiable): MailMessage
    {
        $id   = $notifiable->getKey();
        $hash = sha1($notifiable->getEmailForVerification());

        // Use FRONTEND_URL from env (leget-front URL)
        $baseUrl = env('FRONTEND_URL', 'http://localhost:5173');

        // Build frontend verification URL
        $verificationUrl = rtrim($baseUrl, '/') . '/email-verify?' . http_build_query([
            'id'   => $id,
            'hash' => $hash,
        ]);

        return (new MailMessage)
            ->subject('Подтверждение Email — LEGET')
            ->view('emails.verify-email', [
                'verificationUrl' => $verificationUrl,
                'user'            => $notifiable,
            ]);
    }
}
