<?php

namespace App\Notifications\Channels;

// Issue d'un envoi par un canal (#97). Trois issues et non un booléen : « personne à qui
// l'envoyer » n'est ni une livraison ni un échec à retenter. Le confondre avec `sent` faisait
// lire « envoyée » pour un push parti vers aucun appareil.
enum DeliveryOutcome
{
    /** Accepté par le service push ou le serveur mail. */
    case Delivered;

    /** Échec transitoire : le drain retentera (backoff). */
    case Retry;

    /** Aucun destinataire joignable (aucun appareil abonné, pas d'adresse) : terminal, sans retry. */
    case NoTarget;
}
