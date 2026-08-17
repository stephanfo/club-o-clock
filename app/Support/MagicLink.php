<?php

namespace App\Support;

use App\Models\MagicLinkToken;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

// Magic link maison (PRD §4.1.1, cadrage §7.4). Token aléatoire fort STOCKÉ HASHÉ,
// TTL 15 min, usage unique. Le token en clair ne vit que dans l'URL envoyée par email.
class MagicLink
{
    public const TTL_MINUTES = 15;

    /**
     * Génère un token, persiste son hash, renvoie l'URL signée à envoyer par email.
     * Le token clair n'est jamais stocké.
     */
    public static function createUrlFor(string $email): string
    {
        $token = Str::random(64);

        MagicLinkToken::create([
            'email' => mb_strtolower($email),
            'token_hash' => hash('sha256', $token),
            'expires_at' => Carbon::now()->addMinutes(self::TTL_MINUTES),
        ]);

        return URL::route('magic-link.consume', ['token' => $token]);
    }

    /**
     * Consomme un token clair : renvoie l'email cible si valide (non expiré, non consommé),
     * sinon null. Usage unique : marque consumed_at à la consommation.
     */
    public static function consume(string $token): ?string
    {
        $record = MagicLinkToken::where('token_hash', hash('sha256', $token))->first();

        if (! $record) {
            return null;
        }

        // Usage unique ATOMIQUE : pose consumed_at via un UPDATE conditionnel (non consommé + non
        // expiré). Un seul appelant concurrent peut gagner la course (affected = 1) ; les requêtes
        // parallèles avec le même token repartent bredouilles.
        $claimed = MagicLinkToken::whereKey($record->id)
            ->whereNull('consumed_at')
            ->where('expires_at', '>', Carbon::now())
            ->update(['consumed_at' => Carbon::now()]);

        return $claimed === 1 ? $record->email : null;
    }
}
