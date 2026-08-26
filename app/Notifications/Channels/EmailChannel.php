<?php

namespace App\Notifications\Channels;

use App\Mail\OutboxNotificationMail;
use App\Models\NotificationOutbox;
use App\Notifications\NotificationRenderer;
use App\Notifications\NotificationType;
use Illuminate\Support\Facades\Mail;

// Livraison réelle du canal « email » (J8.6, cadrage §6.3). Rend la ligne en email transactionnel
// et l'expédie via le mailer configuré (MAIL_MAILER : « log » par défaut, « brevo » en prod). Une
// erreur de transport remonte au drain (try/catch global) qui programme le retry/backoff.
class EmailChannel implements NotificationChannel
{
    public function __construct(private NotificationRenderer $renderer) {}

    public function send(NotificationOutbox $line): bool
    {
        $user = $line->user;

        // Pas d'adresse (mineur P1, compte anonymisé) : rien à envoyer, inutile de retenter.
        // Le dispatcher filtre déjà à l'émission ; garde-fou si l'email a disparu depuis.
        if ($user === null || $user->email === null) {
            return true;
        }

        $content = $this->renderer->render($line);

        // Le pied de page dépend du type : une invitation traverse l'interrupteur et la pause,
        // et n'est pas réglable au profil — le pied de page « notifications » y serait mensonger.
        $transactional = NotificationType::tryFrom($line->type)?->transactional() ?? false;

        Mail::to($user->email)->send(
            new OutboxNotificationMail($content['title'], $content['body'], $content['url'], $transactional),
        );

        return true;
    }
}
