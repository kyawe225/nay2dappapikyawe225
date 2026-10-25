<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class PasswordRecoveryNotification extends Notification
{
    public function __construct(public string $token) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)->subject('Reset your password')
            ->line('Use this password reset token in the app:')
            ->line($this->token)
            ->line('This token expires in '.config('auth.passwords.users.expire').' minutes.')
            ->line('If you did not request a password reset, you can ignore this message.');
    }
}
