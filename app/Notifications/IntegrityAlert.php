<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class IntegrityAlert extends Notification
{
    /** @param  list<array{type: string, id: int, title: string}>  $items */
    public function __construct(public array $items) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)->subject('Security alert: published content changed outside the CMS')
            ->line('Published content no longer matches its recorded fingerprint. It may have been edited directly in the database.');

        foreach (array_slice($this->items, 0, 10) as $i) {
            $mail->line("• {$i['type']} #{$i['id']}: {$i['title']}");
        }

        return $mail->line('Open Security in the admin to review, restore a previous revision, or confirm the change.')
            ->action('Open Security', route('admin.security.index'));
    }
}
