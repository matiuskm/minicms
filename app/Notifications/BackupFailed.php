<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class BackupFailed extends Notification
{
    public function __construct(public string $reason) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)->subject('The scheduled backup failed')
            ->line('The last backup did not complete, so the newest restore point is older than expected.')
            ->line('Reason: '.mb_substr($this->reason, 0, 300));
    }
}
