<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class AccountLockedNotification extends Notification
{
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $minutes = config('cms.lock.minutes');

        return (new MailMessage)
            ->subject('Your account was temporarily locked')
            ->line("Too many failed sign-in attempts. The account is locked for {$minutes} minutes.")
            ->line('If this was not you, reset your password after the lock ends.');
    }
}
