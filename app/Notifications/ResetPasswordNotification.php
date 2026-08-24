<?php

namespace App\Notifications;

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * Email de réinitialisation de mot de passe (PRD §4.1.1), en français.
 *
 * Pourquoi cette classe : c'est le SEUL mail sortant que Laravel compose lui-même, et il partait
 * entièrement en anglais (« Hello! », « Reset Password », « This password reset link will expire
 * in :count minutes. »). Ses chaînes sont à clé JSON, or `lang/fr/` ne contient que des fichiers
 * PHP — rien ne pouvait les traduire. On rédige donc le message plutôt que d'ajouter au fr.json
 * des phrases de vendor qu'une montée de version peut réécrire sous nos pieds.
 *
 * Branchée par User::sendPasswordResetNotification() : c'est le point d'extension prévu, et il
 * garde le texte des emails dans app/Notifications/ avec les autres (MagicLinkNotification).
 * Tutoiement et ton alignés sur eux.
 */
class ResetPasswordNotification extends ResetPassword
{
    public function toMail(mixed $notifiable): MailMessage
    {
        $minutes = config('auth.passwords.'.config('auth.defaults.passwords').'.expire');

        return (new MailMessage)
            ->subject('Réinitialiser ton mot de passe')
            ->greeting('Bonjour,')
            ->line('Tu reçois cet email parce qu\'une réinitialisation de mot de passe a été demandée pour ton compte.')
            ->action('Choisir un nouveau mot de passe', $this->resetUrl($notifiable))
            ->line('Ce lien expire dans '.$minutes.' minutes.')
            ->line('Si tu n\'es pas à l\'origine de cette demande, ignore cet email : ton mot de passe reste inchangé.');
    }
}
