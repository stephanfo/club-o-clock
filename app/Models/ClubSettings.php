<?php

namespace App\Models;

use App\Support\ClubPalette;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

// ClubSettings — singleton (PRD §5.1, §4.17). Une seule ligne (id=1).
class ClubSettings extends Model
{
    protected $table = 'club_settings';

    /**
     * Cache par requête du singleton (NF §4.10.1 « temps quasi-constant ») : current() est appelé
     * en cascade dans QuotaService (weekBounds, releaseOwnQuota en boucle) et à chaque composant.
     * La ligne est immuable au sein d'une requête ; on évite ainsi les SELECT redondants. Invalidé
     * sur saved/deleted (l'admin qui modifie les réglages doit relire à jour) — cf. booted().
     */
    private static ?self $cached = null;

    /**
     * Présence des vignettes par taille, mémorisée le temps de la requête (cf. logoThumbUrl).
     *
     * @var array<int, bool>
     */
    private array $thumbExists = [];

    /**
     * Baseline affichée sous le nom du club sur l'écran de connexion, quand le club n'a rien saisi.
     *
     * Le défaut vit ICI et non en base (`tagline` est nullable, sans DEFAULT SQL) : une valeur
     * copiée en base à la création rendrait indistinguables « le club a écrit exactement cette
     * phrase » et « le club n'a rien saisi », et figerait la baseline des instances déjà
     * déployées. NULL = « non personnalisé » → l'app retombe sur cette constante.
     */
    public const DEFAULT_TAGLINE = 'Nage, pédale, cavale… fini le planning infernal !';

    protected $fillable = [
        'name', 'tagline', 'logo_path', 'primary_color', 'accent_color', 'info_color', 'timezone',
        'icon_192_path', 'icon_512_path', 'icon_apple_path',
        'invitation_link_days', 'season_rollover_at', 'season_start_month',
        'legal_publisher', 'legal_host', 'legal_director', 'legal_contact_email',
        'legal_source_url', 'legal_mail_provider',
        'notif_push_enabled', 'notif_email_enabled',
        'auth_magic_link_enabled', 'auth_google_enabled',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'invitation_link_days' => 'integer',
        'season_rollover_at' => 'datetime',
        'season_start_month' => 'integer',
        'notif_push_enabled' => 'boolean',
        'notif_email_enabled' => 'boolean',
        'auth_magic_link_enabled' => 'boolean',
        'auth_google_enabled' => 'boolean',
    ];

    /**
     * Le canal de notification est-il ouvert à l'échelle du club (§4.17) ? Interrupteur en amont
     * de la préférence individuelle (§4.15.3) : un canal coupé ici ne part pour personne, quelle
     * que soit la matrice. Un canal inconnu est refusé (fail-closed) plutôt que traité comme ouvert.
     *
     * Distinct de config('club.notifications.channels.*'), qui choisit le *transport* : ici on
     * décide *si* on envoie.
     */
    public function channelEnabled(string $channel): bool
    {
        return match ($channel) {
            'push' => $this->notif_push_enabled,
            'email' => $this->notif_email_enabled,
            default => false,
        };
    }

    /**
     * URL de la vignette carrée du logo pour un petit affichage (topbar, lockup) — générée à
     * l'upload par ClubBrandingService (plan open source OS2). $size doit être une des tailles
     * générées (64 ou 128) ; une taille absente retombe sur l'original (moins net, jamais cassé).
     */
    public function logoThumbUrl(int $size): string
    {
        if (! $this->logo_path) {
            return asset('img/logo-default.png');
        }

        $thumbPath = dirname($this->logo_path)."/thumb-{$size}.png";

        // Le stat disque est mémorisé sur l'instance (elle-même mise en cache par requête) : le
        // lockup est rendu sur chaque page authentifiée, et deux fois sur l'écran de connexion,
        // pour redécouvrir à chaque fois un fait figé à l'upload.
        $this->thumbExists[$size] ??= Storage::disk('public')->exists($thumbPath);

        return Storage::disk('public')->url($this->thumbExists[$size] ? $thumbPath : $this->logo_path);
    }

    /**
     * URL du logo en taille d'origine (logo du club, sinon logo neutre livré).
     *
     * À n'utiliser que là où la pleine résolution sert vraiment : pour un filigrane ou une
     * vignette, préférer logoThumbUrl(), sinon on télécharge jusqu'à 2 Mo pour un rendu de 150 px.
     */
    public function logoUrl(): string
    {
        return $this->logo_path
            ? Storage::disk('public')->url($this->logo_path)
            : asset('img/logo-default.png');
    }

    /**
     * Icônes PWA : variante → [colonne, fichier de repli livré dans public/icons/].
     *
     * Le repli est versionné (cadrage §7.16) : une instance qui n'a rien téléversé reste
     * installable en PWA, et la démo publique montre le produit sans porter le branding d'un club.
     *
     * @var array<string, array{0: string, 1: string}>
     */
    public const PWA_ICONS = [
        'icon_192' => ['icon_192_path', 'icons/icon-192.png'],
        'icon_512' => ['icon_512_path', 'icons/icon-512.png'],
        'icon_apple' => ['icon_apple_path', 'icons/apple-touch-icon.png'],
    ];

    /**
     * URL d'une icône PWA : celle du club si elle a été téléversée, sinon celle livrée.
     *
     * Le chemin stocké contient un segment aléatoire renouvelé à chaque téléversement : l'URL
     * change donc avec le fichier, ce qui suffit à contourner le cache HTTP. Un écran d'accueil
     * DÉJÀ installé garde en revanche l'ancienne icône jusqu'à réinstallation — limite des PWA,
     * documentée dans INSTALL, pas contournable côté serveur.
     */
    public function pwaIconUrl(string $variant): string
    {
        [$column, $fallback] = self::PWA_ICONS[$variant]
            ?? throw new \InvalidArgumentException("Icône PWA inconnue : {$variant}");

        $path = $this->{$column};

        return $path
            ? Storage::disk('public')->url($path)
            : asset($fallback);
    }

    /**
     * Champs de mentions légales dépendant de l'instance (constat n°11 de la revue open source).
     * Le logiciel ne peut pas les connaître : ils identifient l'association qui exploite le site.
     *
     * @var array<int, string>
     */
    public const LEGAL_FIELDS = [
        'legal_publisher', 'legal_host', 'legal_director',
        'legal_contact_email', 'legal_source_url', 'legal_mail_provider',
    ];

    /** true tant qu'au moins une mention légale obligatoire n'est pas renseignée. */
    public function legalNoticeIncomplete(): bool
    {
        foreach (self::LEGAL_FIELDS as $field) {
            if (blank($this->{$field})) {
                return true;
            }
        }

        return false;
    }

    /** Baseline à afficher : celle du club si elle est renseignée, sinon celle du produit. */
    public function effectiveTagline(): string
    {
        return filled($this->tagline) ? $this->tagline : self::DEFAULT_TAGLINE;
    }

    /** Début de la saison sportive en cours (1er du mois de bascule précédant ou égal à $on). */
    public function seasonStart(?Carbon $on = null): Carbon
    {
        $on = ($on ?? Carbon::now())->copy()->setTimezone($this->timezone ?: 'Europe/Paris');
        $month = $this->season_start_month ?: 9;
        $year = $on->month >= $month ? $on->year : $on->year - 1;

        return Carbon::create($year, $month, 1, 0, 0, 0, $this->timezone ?: 'Europe/Paris');
    }

    /**
     * Rappel passif (§4.5) : dès le 1er sept, tant que la bascule de la saison en cours n'a pas
     * été déclenchée (season_rollover_at antérieur au début de saison, ou jamais posé).
     */
    public function needsRolloverReminder(?Carbon $on = null): bool
    {
        return $this->season_rollover_at === null
            || $this->season_rollover_at->lt($this->seasonStart($on));
    }

    /**
     * Accès au singleton (créé si absent, avec les défauts FR). Renvoie la 1re ligne sans épingler
     * id=1 : `id` n'est pas mass-assignable, donc firstOrCreate(['id'=>1]) laissait l'autoincrement
     * choisir l'id et créait des doublons dès que le compteur dérivait (rollbacks de tests).
     */
    public static function current(): self
    {
        return static::$cached ??= static::query()->orderBy('id')->firstOr(fn () => static::create([
            'name' => 'Club',
            'timezone' => 'Europe/Paris',
            'invitation_link_days' => 30,
            'season_start_month' => 9,
            // Répétés ici malgré leur DEFAULT SQL : l'instance renvoyée par create() ne relit pas
            // la base, ses attributs non fournis resteraient null jusqu'au prochain refresh — et
            // channelEnabled() / les propriétés typées du formulaire admin exigent des booléens.
            'notif_push_enabled' => true,
            'notif_email_enabled' => true,
            'auth_magic_link_enabled' => true,
            'auth_google_enabled' => true,
        ]));
    }

    /** Vide le cache du singleton (mutation des réglages, ou isolation entre tests). */
    public static function flushCache(): void
    {
        static::$cached = null;
        // Le CSS de palette est dérivé de ces colonnes : le laisser survivre à une modification
        // servirait l'ancienne palette jusqu'à expiration (rememberForever = jamais).
        Cache::forget(ClubPalette::CACHE_KEY);
    }

    protected static function booted(): void
    {
        // Toute écriture/suppression du singleton périme le cache : la prochaine current() relit la DB.
        static::saved(fn () => static::flushCache());
        static::deleted(fn () => static::flushCache());
    }
}
