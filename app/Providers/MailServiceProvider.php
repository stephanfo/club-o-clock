<?php

namespace App\Providers;

use Illuminate\Support\Facades\Mail;
use Illuminate\Support\ServiceProvider;
use Symfony\Component\Mailer\Bridge\Brevo\Transport\BrevoApiTransport;

// Enregistre le transport mail « brevo » (email transactionnel UE par API HTTP, cadrage §6.3).
// Laravel ne fournit pas Brevo en natif : on branche le bridge symfony/brevo-mailer au mail manager.
// Inactif tant que MAIL_MAILER ≠ brevo (défaut « log ») — la bascule prod = 1 var d'env + BREVO_API_KEY.
class MailServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Mail::extend('brevo', function (array $config) {
            return new BrevoApiTransport((string) config('services.brevo.key'));
        });
    }
}
