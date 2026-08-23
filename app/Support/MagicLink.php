<?php

namespace App\Support;

use App\Models\MagicLinkToken;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

// Magic link maison (PRD §4.1.1, cadrage §7.4). Token aléatoire fort STOCKÉ HASHÉ,
// TTL 15 min, usage unique. Le token en clair ne vit que dans l'URL envoyée par email.
//
// Un CODE À 6 CHIFFRES accompagne le lien, porté par la même ligne : ce sont les deux faces d'une
// même autorisation, consommer l'une brûle l'autre. Il existe pour un cas que le lien ne sait pas
// traiter — une PWA installée sur iOS a un pot de cookies distinct de Safari, donc un lien cliqué
// dans Mail ouvre la session dans Safari et laisse l'application déconnectée. Le code, lui, se
// saisit DANS l'application, où le cookie doit être posé.
class MagicLink
{
    public const TTL_MINUTES = 15;

    /** Essais autorisés avant de brûler le jeton. 5 essais sur 10⁶ = 5 chances sur un million. */
    public const MAX_CODE_ATTEMPTS = 5;

    /**
     * Génère un token, persiste son hash, renvoie l'URL signée à envoyer par email.
     * Le token clair n'est jamais stocké.
     */
    public static function createUrlFor(string $email): string
    {
        return self::issue($email)['url'];
    }

    /**
     * Émet une autorisation de connexion : une URL ET un code, sur une seule ligne.
     *
     * @return array{url:string,code:string} les deux formes en clair, à ne transmettre que par email
     */
    public static function issue(string $email): array
    {
        $token = Str::random(64);
        // random_int et non rand()/mt_rand()/Str::random : générateur cryptographique, seul
        // acceptable pour un secret d'accès aussi court.
        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        MagicLinkToken::create([
            'email' => mb_strtolower($email),
            'token_hash' => hash('sha256', $token),
            'code_hash' => self::hashCode($code),
            'expires_at' => Carbon::now()->addMinutes(self::TTL_MINUTES),
        ]);

        return [
            'url' => URL::route('magic-link.consume', ['token' => $token]),
            'code' => $code,
        ];
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

    /**
     * Consomme un code à 6 chiffres pour un email donné. Renvoie l'email si valide, sinon null —
     * un seul retour pour tous les échecs, l'appelant ne peut donc pas distinguer « email inconnu »
     * de « code faux » et l'écran ne peut pas fuir cette information.
     *
     * L'email est OBLIGATOIRE et sert de clé de recherche. Sans lui, un attaquant testerait 10⁶
     * combinaisons contre TOUS les jetons vivants à la fois : la probabilité de toucher quelqu'un
     * croîtrait avec le nombre d'utilisateurs, au lieu de rester bornée par jeton.
     */
    public static function consumeCode(string $email, string $code): ?string
    {
        $email = mb_strtolower(trim($email));
        $code = trim($code);

        $record = MagicLinkToken::where('email', $email)
            ->whereNotNull('code_hash')
            ->whereNull('consumed_at')
            ->where('expires_at', '>', Carbon::now())
            ->where('code_attempts', '<', self::MAX_CODE_ATTEMPTS)
            ->latest('id')
            ->first();

        if (! $record) {
            return null;
        }

        // hash_equals : comparaison en temps constant, pour ne pas laisser fuir par la durée de
        // réponse combien de caractères de tête sont corrects.
        if (! hash_equals((string) $record->code_hash, self::hashCode($code))) {
            // L'échec compte, et au 5e le jeton est brûlé — le compteur par IP ne suffit pas, il se
            // contourne ; celui-ci est attaché au secret lui-même.
            $record->increment('code_attempts');

            if ($record->code_attempts >= self::MAX_CODE_ATTEMPTS) {
                $record->forceFill(['consumed_at' => Carbon::now()])->save();
            }

            return null;
        }

        // Même UPDATE conditionnel atomique que le lien : un seul appelant peut gagner la course.
        $claimed = MagicLinkToken::whereKey($record->id)
            ->whereNull('consumed_at')
            ->where('expires_at', '>', Carbon::now())
            ->update(['consumed_at' => Carbon::now()]);

        return $claimed === 1 ? $record->email : null;
    }

    /**
     * HMAC plutôt que sha256 nu : un sha256 de 6 chiffres se renverse par force brute exhaustive en
     * quelques microsecondes, donc un dump de base livrerait tous les codes en clair. Le HMAC oblige
     * l'attaquant hors-ligne à détenir AUSSI APP_KEY. La protection en ligne, elle, reste le TTL de
     * 15 min et le compteur d'essais — ce hachage ne fait que borner les dégâts d'une fuite de base.
     */
    private static function hashCode(string $code): string
    {
        return hash_hmac('sha256', $code, (string) config('app.key'));
    }
}
