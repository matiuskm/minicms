<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** A short "this happened on your account" email (FR-69). Contains no secrets, only what changed and when. */
class SecurityNotice extends Notification
{
    /** @param  list<string>  $lines */
    public function __construct(public string $subject, public array $lines) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)->subject($this->subject);
        foreach ($this->lines as $line) {
            $mail->line($line);
        }

        return $mail->line('If this was not you, reset your password and contact an administrator.');
    }
}
