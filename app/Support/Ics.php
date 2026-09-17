<?php

namespace App\Support;

use App\Models\ClubSettings;
use App\Models\Session;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Export agenda iCalendar (RFC 5545, #39, PRD §4.21). Génération maison : le format utile tient en
 * quelques propriétés, et une dépendance de plus ne s'installe pas sans composer sur le poste.
 *
 * Un seul constructeur de VEVENT pour les deux gestes — le .ics ponctuel de la fiche séance et le
 * flux d'abonnement — afin qu'une même séance ait le même rendu dans l'un et l'autre.
 */
class Ics
{
    /** « La veille au soir » : 20 h, heure du club, la veille du jour de la séance. */
    public const RAPPEL_VEILLE = 'veille';

    /** @param list<string> $evenements VEVENT déjà construits par event() */
    public static function calendar(array $evenements, ?string $nom = null): string
    {
        $lignes = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//club-o-clock//Agenda//FR',
            'CALSCALE:GREGORIAN',
            'METHOD:PUBLISH',
        ];
        if ($nom !== null) {
            $lignes[] = 'X-WR-CALNAME:'.self::texte($nom);
        }

        $corps = implode("\r\n", array_map(self::plier(...), $lignes))."\r\n";

        return $corps.implode('', $evenements)."END:VCALENDAR\r\n";
    }

    /**
     * Un VEVENT pour la séance, vue par une personne donnée.
     *
     * L'UID porte la personne : un coach dont l'enfant est inscrit à la même séance reçoit deux
     * événements dans le même flux, et un UID commun ferait écraser l'un par l'autre.
     *
     * Une séance annulée garde sa place, avec trois marqueurs cumulés : le préfixe du titre (seul
     * rendu vraiment portable), STATUS:CANCELLED (pour les clients qui l'honorent) et
     * TRANSP:TRANSPARENT (le créneau ne compte plus comme occupé). Jamais de rappel dessus.
     *
     * @param  int|string|null  $rappel  minutes avant le début, self::RAPPEL_VEILLE, ou null
     */
    public static function event(Session $session, User $pour, string $prefixe = '', int|string|null $rappel = null): string
    {
        $annulee = $session->isCancelled();
        $titre = ($annulee ? 'Annulé — ' : '').$prefixe.$session->title;
        $url = route('sessions.show', $session);
        $hote = parse_url((string) config('app.url'), PHP_URL_HOST) ?: 'club-o-clock';

        $lignes = [
            'BEGIN:VEVENT',
            'UID:seance-'.$session->id.'-u'.$pour->id.'@'.$hote,
            'DTSTAMP:'.self::utc(Carbon::now()),
            'DTSTART:'.self::utc($session->start_at),
            'DTEND:'.self::utc($session->endsAt()),
            // Les clients ne remplacent un événement déjà importé que si SEQUENCE augmente.
            'SEQUENCE:'.$session->updated_at->timestamp,
            'SUMMARY:'.self::texte($titre),
            'STATUS:'.($annulee ? 'CANCELLED' : 'CONFIRMED'),
            'TRANSP:'.($annulee ? 'TRANSPARENT' : 'OPAQUE'),
        ];

        $lieu = self::lieu($session);
        if ($lieu !== null) {
            $lignes[] = 'LOCATION:'.self::texte($lieu);
        }

        // Minimisation : l'URL peut fuiter (agenda partagé), donc ni inscrits ni contenu — le lien.
        $lignes[] = 'URL:'.$url;
        $lignes[] = 'DESCRIPTION:'.self::texte('Détails et inscriptions : '.$url);

        if (! $annulee && $rappel !== null) {
            array_push($lignes, 'BEGIN:VALARM', 'ACTION:DISPLAY', 'DESCRIPTION:'.self::texte($titre),
                'TRIGGER'.self::declencheur($session, $rappel), 'END:VALARM');
        }

        $lignes[] = 'END:VEVENT';

        return implode("\r\n", array_map(self::plier(...), $lignes))."\r\n";
    }

    /** Nom du fichier téléchargé : date locale du club + titre, sans caractère exotique. */
    public static function filename(Session $session): string
    {
        $date = $session->start_at->copy()->setTimezone(ClubSettings::current()->timezone)->format('Y-m-d');
        $slug = Str::slug($session->title) ?: 'seance';

        return "seance-{$date}-{$slug}.ics";
    }

    private static function lieu(Session $session): ?string
    {
        $morceaux = array_filter([$session->placeLabel(), $session->location?->address]);

        return $morceaux === [] ? null : implode(', ', array_unique($morceaux));
    }

    private static function declencheur(Session $session, int|string $rappel): string
    {
        if ($rappel === self::RAPPEL_VEILLE) {
            // Absolu en UTC : la veille à 20 h heure du club, quel que soit le fuseau de l'appareil.
            $tz = ClubSettings::current()->timezone;
            $veille = $session->start_at->copy()->setTimezone($tz)->subDay()->setTime(20, 0);

            return ';VALUE=DATE-TIME:'.self::utc($veille);
        }

        return ':-PT'.(int) $rappel.'M';
    }

    private static function utc(Carbon $instant): string
    {
        return $instant->copy()->utc()->format('Ymd\THis\Z');
    }

    /** Échappement d'une valeur TEXT (RFC 5545 §3.3.11). */
    private static function texte(string $valeur): string
    {
        return str_replace(['\\', ';', ',', "\r\n", "\n", "\r"], ['\\\\', '\;', '\,', '\n', '\n', '\n'], $valeur);
    }

    /** Pliage à 75 octets (RFC 5545 §3.1), sans couper un caractère UTF-8 multi-octets. */
    private static function plier(string $ligne): string
    {
        $morceaux = [];
        $limite = 75;
        while (strlen($ligne) > $limite) {
            $coupe = mb_strcut($ligne, 0, $limite, 'UTF-8');
            $morceaux[] = $coupe;
            $ligne = substr($ligne, strlen($coupe));
            $limite = 74; // la ligne de continuation commence par une espace
        }
        $morceaux[] = $ligne;

        return implode("\r\n ", $morceaux);
    }
}
