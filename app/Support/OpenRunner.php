<?php

namespace App\Support;

// Validation stricte des liens OpenRunner Pro (PRD §4.13.1). On ne stocke QUE l'URL src (jamais le
// bloc iframe HTML brut → pas de XSS) ; la whitelist garantit que seul un embed OpenRunner légitime
// est accepté. Validation symétrique côté client (feedback), serveur faisant foi.
class OpenRunner
{
    private const HOST = 'www.openrunner.com';

    /**
     * URL d'embed valide : https + hôte EXACT www.openrunner.com + path EXACT /embed.html +
     * paramètre `code` présent et non vide (le token opaque chiffré généré par OR Pro).
     */
    public static function validEmbedUrl(?string $url): bool
    {
        $url = trim((string) $url);
        if ($url === '') {
            return false;
        }

        $parts = parse_url($url);
        if ($parts === false) {
            return false;
        }
        if (($parts['scheme'] ?? '') !== 'https') {
            return false;
        }
        if (strtolower($parts['host'] ?? '') !== self::HOST) {
            return false;
        }
        if (($parts['path'] ?? '') !== '/embed.html') {
            return false;
        }

        parse_str($parts['query'] ?? '', $query);

        return isset($query['code']) && trim((string) $query['code']) !== '';
    }

    /** URL publique valide : https + hôte www.openrunner.com (sans contrainte de path). */
    public static function validPublicUrl(?string $url): bool
    {
        $url = trim((string) $url);
        if ($url === '') {
            return false;
        }

        $parts = parse_url($url);
        if ($parts === false) {
            return false;
        }

        return ($parts['scheme'] ?? '') === 'https' && strtolower($parts['host'] ?? '') === self::HOST;
    }
}
