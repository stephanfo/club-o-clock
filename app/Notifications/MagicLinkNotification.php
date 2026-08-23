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

    public function __construct(public string $url, public ?string $code = null) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject(__('Ton lien de connexion'))
            ->greeting(__('Bonjour,'))
            ->line(__('Clique sur le bouton ci-dessous pour te connecter. Ce lien expire dans :minutes minutes et ne peut servir qu\'une fois.', ['minutes' => MagicLink::TTL_MINUTES]))
            ->action(__('Me connecter'), $this->url);

        // Le code sert quand le lien ne peut PAS ouvrir la bonne session : sur iPhone, une
        // application installée sur l'écran d'accueil ne partage pas ses cookies avec Safari, donc
        // le lien connecte Safari et laisse l'application déconnectée.
        if ($this->code !== null) {
            $mail->line(__('Depuis l\'application installée sur ton téléphone, saisis plutôt ce code : :code', ['code' => $this->code]));
        }

        return $mail->line(__('Si tu n\'es pas à l\'origine de cette demande, ignore cet email.'));
    }
}
