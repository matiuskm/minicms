<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class InviteNotification extends Notification
{
    public function __construct(public string $token) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $url = route('invite.show', ['token' => $this->token, 'email' => $notifiable->email]);

        return (new MailMessage)
            ->subject('You have been invited to '.config('app.name'))
            ->line('An administrator created an account for you. Set your password to activate it.')
            ->action('Set password', $url)
            ->line('This link expires in 48 hours.');
    }
}
