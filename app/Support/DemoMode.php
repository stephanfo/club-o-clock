<?php

namespace App\Support;

use App\Notifications\Channels\LogChannel;
use Illuminate\Contracts\Config\Repository;

/**
 * Instance de démonstration publique (plan open source OS7).
 *
 * L'écran de connexion d'une démo affiche ses propres identifiants : n'importe quel
 * visiteur y agit en admin, et peut donc créer un adhérent avec l'adresse email de son
 * choix, puis déclencher une invitation. Le mode ne se contente pas de DOCUMENTER ses
 * garde-fous dans le .env — il les IMPOSE : une erreur de configuration ne doit pas
 * pouvoir envoyer un vrai message à une vraie personne depuis une instance ouverte.
 */
final class DemoMode
{
    public static function enabled(): bool
    {
        return (bool) config('club.demo.enabled', false);
    }

    /**
     * Écrase la configuration d'envoi, quelle que soit celle du .env.
     *
     * Appelé depuis `register()` : ni le gestionnaire de mail ni les canaux de
     * notification ne sont encore résolus à ce moment, l'écrasement est donc total.
     * `mail.default` neutralise l'email au niveau du transport (rien ne peut sortir,
     * même par un chemin d'envoi qu'on aurait oublié) ; les deux canaux sont ramenés
     * sur LogChannel pour que l'outbox reste démontrable sans réseau.
     */
    public static function enforce(Repository $config): void
    {
        $config->set('mail.default', 'log');
        $config->set('club.notifications.channels.push', LogChannel::class);
        $config->set('club.notifications.channels.email', LogChannel::class);
    }

    /**
     * Le lien magique est-il utilisable ici ? Non en démo, et ce n'est pas un choix
     * d'ergonomie : c'est la CONSÉQUENCE directe du mailer neutralisé ci-dessus.
     *
     * Laissé actif, il promettait « un lien t'attend dans ta boîte mail » alors que
     * l'email part dans storage/logs et n'arrive jamais — et c'était l'onglet coché par
     * défaut, donc l'impasse était le tout premier geste du visiteur.
     *
     * Le garde-fou ne peut pas passer par enforce() : la disponibilité du lien magique
     * est une colonne de club_settings, pas une clé de config. Il s'applique donc au
     * point de lecture (AuthMethodService), seule source consommée par l'écran de
     * connexion et les contrôleurs d'auth.
     *
     * Le mot de passe, lui, n'a jamais d'interrupteur : la démo garde toujours sa voie
     * d'entrée, et le cas « lien magique coupé » est déjà géré et testé (§4.17).
     */
    public static function magicLinkUsable(): bool
    {
        return ! self::enabled();
    }
}
