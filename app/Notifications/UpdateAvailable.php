<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class UpdateAvailable extends Notification
{
    public function __construct(public string $version, public string $current) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)->subject("Version {$this->version} is available")
            ->line("A new version of the site software is available ({$this->current} → {$this->version}).")
            ->line('Updates often include security fixes. Review the release notes and update from the admin.')
            ->action('Open Updates', route('admin.updates.index'));
    }
}
