<?php

namespace App\Notifications;

use App\Support\MagicLink;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

// Email de lien de connexion (PRD §4.1.1). Envoi inline (latence-sensible, cadrage §7.14).
class MagicLinkNotification extends Notification
{
    use Queueable;

    public function __construct(public string $url) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('Ton lien de connexion'))
            ->greeting(__('Bonjour,'))
            ->line(__('Clique sur le bouton ci-dessous pour te connecter. Ce lien expire dans :minutes minutes et ne peut servir qu\'une fois.', ['minutes' => MagicLink::TTL_MINUTES]))
            ->action(__('Me connecter'), $this->url)
            ->line(__('Si tu n\'es pas à l\'origine de cette demande, ignore cet email.'));
    }
}
