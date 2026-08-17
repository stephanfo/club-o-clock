<?php

namespace App\Mail;

use App\Models\ClubSettings;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

// Email transactionnel générique d'une notif d'outbox (J8.6). Contenu dérivé du type par
// NotificationRenderer (titre = libellé, corps = description, bouton vers le lien profond).
class OutboxNotificationMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $title,
        public string $body,
        public string $actionUrl,
    ) {}

    public function envelope(): Envelope
    {
        // Expéditeur explicite (nom du club en base) : config('mail.from.name') est figé par
        // config:cache en prod (plan open source OS2, personnalisation d'instance).
        return new Envelope(
            from: new Address((string) config('mail.from.address'), ClubSettings::current()->name),
            subject: $this->title,
        );
    }

    public function content(): Content
    {
        return new Content(markdown: 'emails.notification');
    }
}
