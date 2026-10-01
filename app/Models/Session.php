<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

// Entité Session (séance) — table unique polymorphe à discriminator `kind` (PRD §4.7, §5.1).
class Session extends Model
{
    public const KINDS = ['training', 'competition', 'club_event'];

    /** Notification d'ouverture des inscriptions (#105) : avance sur l'heure connue, heure par défaut sinon. */
    public const OPENING_NOTIFY_LEAD_MIN = 15;

    public const OPENING_NOTIFY_HOUR = 9;

    /** Jours pendant lesquels des inscriptions ouvertes se disent « ouvertes » (puis « peut-être complet »). */
    public const OPENING_RECENT_DAYS = 3;

    /** Fenêtre d'affichage à l'accueil : de J-14 avant l'ouverture à J+3 après (#105). */
    public const OPENING_HOME_BEFORE_DAYS = 14;

    protected $fillable = [
        'kind', 'title', 'discipline_id', 'start_at', 'duration_min',
        'location_id', 'location_text', 'capacity', 'visibility',
        // Intervenant extérieur (#38) — training uniquement, non nominatif.
        'external_staff_label',
        // Adresse ponctuelle géocodée (#37) — alternative exclusive au lieu favori.
        'ad_hoc_address', 'ad_hoc_latitude', 'ad_hoc_longitude',
        'created_by', 'source_template_id',
        // training
        'quota_tag_id', 'content_markdown', 'content_attachment_path',
        // competition
        'event_type_id', 'distance', 'external_url', 'photos_album_url',
        // ouverture des inscriptions officielles (#105)
        'registration_opens_at', 'registration_opens_has_time',
        // état d'envoi de sa notification, recalculé par le formulaire quand l'ouverture change
        'registration_opening_notified_at',
        // club_event
        'agenda',
        // parcours : les URLs OpenRunner restent par-séance, le GPX vit dans GpxRoute (§4.20).
        'route_openrunner_embed_url', 'route_openrunner_public_url', 'route_id',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'duration_min' => 'integer',
        'capacity' => 'integer',
        'cancelled_at' => 'datetime',
        'quota_released_at' => 'datetime',
        'registration_opens_has_time' => 'boolean',
        'registration_opening_notified_at' => 'datetime',
        'ad_hoc_latitude' => 'decimal:7',
        'ad_hoc_longitude' => 'decimal:7',
    ];

    /**
     * `start_at` porte une heure de séance SAISIE en heure locale du club (ex. 19:30 Paris).
     * Le cast 'datetime' natif sérialise le wall-clock de l'objet Carbon *sans* le ramener à
     * config('app.timezone'), donc un Carbon en +02:00 était stocké « 19:30 » puis relu comme
     * 19:30 UTC → +2 h à l'affichage. On force la conversion UTC à l'écriture, et on relit en UTC
     * (les vues font ->setTimezone($tz) pour l'affichage). Point de vérité unique pour toute écriture.
     *
     * @return Attribute<Carbon|null, Carbon|string|null>
     */
    protected function startAt(): Attribute
    {
        return Attribute::make(
            get: fn ($value) => $value === null ? null : Carbon::parse($value, 'UTC'),
            set: fn ($value) => $value === null ? null : Carbon::parse($value)->utc(),
        );
    }

    /**
     * Même piège et même parade que `start_at` : l'ouverture des inscriptions (#105) est un instant,
     * stocké en UTC quel que soit le fuseau du Carbon reçu.
     *
     * @return Attribute<Carbon|null, Carbon|string|null>
     */
    protected function registrationOpensAt(): Attribute
    {
        return Attribute::make(
            get: fn ($value) => $value === null ? null : Carbon::parse($value, 'UTC'),
            set: fn ($value) => $value === null ? null : Carbon::parse($value)->utc(),
        );
    }

    /** Ouverture des inscriptions en heure club (null si non renseignée). */
    public function registrationOpensLocal(): ?Carbon
    {
        return $this->registration_opens_at?->copy()->setTimezone(ClubSettings::current()->timezone);
    }

    /**
     * Instant d'envoi de la notification d'ouverture (#105, §4.15.2) : 15 min avant l'heure quand
     * elle est connue (le temps d'ouvrir le site de l'organisateur), sinon 9 h heure club le jour même.
     */
    public function registrationOpeningNotifyAt(): ?Carbon
    {
        $local = $this->registrationOpensLocal();
        if ($local === null) {
            return null;
        }

        return ($this->registration_opens_has_time
            ? $local->copy()->subMinutes(self::OPENING_NOTIFY_LEAD_MIN)
            : $local->copy()->startOfDay()->setTime(self::OPENING_NOTIFY_HOUR, 0))->utc();
    }

    /**
     * État des inscriptions officielles (#105) : 'today' (ouvrent aujourd'hui), 'upcoming' (plus
     * tard), 'open' (ouvertes depuis au plus 3 jours), 'maybe_full' (au-delà : la course a pu se
     * remplir). Null sans date, sur une course annulée ou déjà partie. Sans heure, l'ouverture vaut
     * toute la journée : « aujourd'hui » jusqu'à minuit, « ouvertes » le lendemain.
     */
    public function registrationState(?Carbon $now = null): ?string
    {
        $opens = $this->registrationOpensLocal();
        if ($opens === null || $this->isCancelled() || $this->hasStarted()) {
            return null;
        }

        $now = ($now ?? Carbon::now())->copy()->setTimezone($opens->getTimezone());
        $today = $now->isSameDay($opens);

        if ($today && (! $this->registration_opens_has_time || $now->lt($opens))) {
            return 'today';
        }
        if ($now->lt($opens)) {
            return 'upcoming';
        }

        return $now->lt($opens->copy()->startOfDay()->addDays(self::OPENING_RECENT_DAYS + 1)) ? 'open' : 'maybe_full';
    }

    /**
     * Contexte de séance figé dans le payload d'une notification (§4.15.2), pour que le push et
     * l'email disent QUELLE séance sans rien charger à l'envoi (cadrage §7.14 : le drain ne fait
     * aucune requête, et une séance supprimée entre l'émission et l'envoi ne casse rien).
     *
     * `start_at` part en ISO8601 UTC, jamais en wall-clock : c'est le piège documenté ci-dessus,
     * et le rendu applique le fuseau du club à l'affichage.
     *
     * @return array{session_id:int,session_title:string,session_start_at:string}
     */
    public function payloadNotification(): array
    {
        return [
            'session_id' => $this->id,
            'session_title' => $this->title,
            'session_start_at' => $this->start_at->toIso8601String(),
        ];
    }

    public function isCancelled(): bool
    {
        return $this->cancelled_at !== null;
    }

    /**
     * Quota débloqué par le coach (#66, §4.10.4) : jusqu'à la séance, le quota ne bloque plus
     * l'inscription et le mécanisme A pioche aussi dans la file `quota_exceeded`. N'a de sens que
     * sur une séance taguée : sans tag, il n'y a pas de quota à débloquer.
     */
    public function isQuotaReleased(): bool
    {
        return $this->quota_released_at !== null && $this->quota_tag_id !== null;
    }

    /** Séance commencée → inscriptions/désinscriptions bloquées sur les 3 kind (§4.9.1). */
    public function hasStarted(): bool
    {
        return $this->start_at->isPast();
    }

    /** Fin du créneau = début + durée. Borne de l'annulation (§4.7) et pendant de hasStarted(). */
    public function endsAt(): Carbon
    {
        return $this->start_at->copy()->addMinutes($this->duration_min);
    }

    /** Créneau terminé → la séance a eu lieu : elle n'est plus annulable (§4.7). */
    public function hasEnded(): bool
    {
        return $this->endsAt()->isPast();
    }

    /** Inscrits actifs (sur la collection chargée si dispo, sinon requête). */
    public function participatingCount(): int
    {
        return $this->registrations->where('status', 'participating')->count();
    }

    /** Capacité atteinte ? Une capacité nulle = pas de limite → jamais pleine (§4.9.3). */
    public function isFull(): bool
    {
        return $this->capacity !== null && $this->participatingCount() >= $this->capacity;
    }

    /** L'inscription (tous statuts) de cet utilisateur, ou null. */
    public function registrationFor(User $user): ?Registration
    {
        return $this->registrations->firstWhere('user_id', $user->id);
    }

    /**
     * Classe couleur du design (liseré scard, dot, tint) : la discipline quand elle est
     * renseignée, sinon repli sur le `kind` (compétition / événement club / préparation).
     * Point de vérité unique — les vues ne recomposent pas cette formule.
     */
    public function colorClass(): string
    {
        return $this->discipline?->colorClass()
            ?? match ($this->kind) {
                'competition' => 'competition',
                'club_event' => 'event',
                default => 'prep',
            };
    }

    /**
     * Statut d'inscription du sujet consulté (parent → enfant, §4.2) sur cette séance :
     * 'participating' | 'waitlist' | null. `cancelled` est assimilé à « pas inscrit ».
     * Lit la collection `registrations` déjà chargée → pas de requête supplémentaire en liste.
     */
    public function statusFor(?int $userId): ?string
    {
        if (! $userId) {
            return null;
        }

        $status = $this->registrations->firstWhere('user_id', $userId)?->status;

        return in_array($status, ['participating', 'waitlist'], true) ? $status : null;
    }

    // --- Relations ---

    /** @return BelongsTo<Discipline, $this> */
    public function discipline(): BelongsTo
    {
        return $this->belongsTo(Discipline::class);
    }

    /** @return BelongsTo<EventType, $this> */
    public function eventType(): BelongsTo
    {
        return $this->belongsTo(EventType::class);
    }

    /** @return BelongsTo<QuotaTag, $this> */
    public function quotaTag(): BelongsTo
    {
        return $this->belongsTo(QuotaTag::class);
    }

    /** @return BelongsTo<Location, $this> */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    /**
     * Libellé du lieu de la séance (#37) — point de vérité unique de l'affichage, partagé par les
     * six vues qui montrent un lieu (carte de séance, plan de semaine, accueil ×2, apéro, fiche).
     *
     * Avant #37 ces vues faisaient `location_text ?: location?->name` : le texte libre *remplaçait*
     * le nom du lieu. Le champ libre redevenant une simple précision, l'ordre s'inverse — mais le
     * dernier terme garde la RÉTROCOMPATIBILITÉ : les `location_text` déjà saisis qui contiennent
     * en réalité une adresse continuent de s'afficher exactement comme avant. Aucune migration de
     * données, aucun texte existant perdu ni réinterprété.
     */
    public function placeLabel(): ?string
    {
        $favori = $this->location;

        return $favori !== null ? $favori->name : ($this->ad_hoc_address ?? $this->location_text);
    }

    /**
     * Coordonnées de la séance (#37) : celles du lieu favori, sinon celles de l'adresse ponctuelle.
     * Météo (SessionShow, weather:refresh) et carte (fiche-infos) passent par ici sans jamais
     * savoir lequel des deux cas elles traitent.
     *
     * @return array{lat: float, lng: float}|null
     */
    public function coordinates(): ?array
    {
        $favori = $this->location;
        if ($favori !== null) {
            // Un lieu favori non géocodé ne retombe PAS sur l'adresse ponctuelle : les deux sont
            // exclusifs, et les coordonnées d'un autre lieu seraient un mensonge, pas un repli.
            return $favori->latitude === null || $favori->longitude === null
                ? null
                : ['lat' => (float) $favori->latitude, 'lng' => (float) $favori->longitude];
        }

        return $this->ad_hoc_latitude === null || $this->ad_hoc_longitude === null
            ? null
            : ['lat' => (float) $this->ad_hoc_latitude, 'lng' => (float) $this->ad_hoc_longitude];
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Parcours attaché (§4.20). 0 ou 1 par séance ; un parcours sert N séances.
     * Détacher une séance ne supprime jamais le parcours ni son fichier : il est partagé.
     *
     * @return BelongsTo<GpxRoute, $this>
     */
    public function gpxRoute(): BelongsTo
    {
        return $this->belongsTo(GpxRoute::class, 'route_id');
    }

    /** @return BelongsToMany<Category, $this> */
    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(Category::class, 'session_category');
    }

    /**
     * Filtrage par catégorie (§4.5) : ne conserve que les séances dont le ciblage inclut au moins
     * une des catégories du sujet, ou **sans aucun ciblage** (= ouvertes à toutes les catégories,
     * même sémantique que SessionNotificationService). Deux échappatoires : le staff (coach/admin)
     * voit tout ; un sujet sans catégorie active bénéficie du fallback ouvert (voit tout). No-op si
     * $subject est null.
     */
    public function scopeVisibleToCategories($query, ?User $subject)
    {
        if (! $subject || $subject->hasRole('coach') || $subject->hasRole('admin')) {
            return $query;
        }

        // Seules les catégories ACTIVES comptent (cohérent avec User::hasActiveCategory /
        // isTargetedBy) : une catégorie archivée reste sur les rattachements historiques
        // mais ne pilote plus ni visibilité ni inscription.
        $categoryIds = $subject->categories->whereNull('archived_at')->pluck('id');
        if ($categoryIds->isEmpty()) {
            return $query; // Fallback ouvert : aucune catégorie active attribuée.
        }

        return $query->where(fn ($q) => $q
            ->whereHas('categories', fn ($c) => $c->whereIn('categories.id', $categoryIds))
            ->orDoesntHave('categories'));
    }

    /** @return BelongsToMany<User, $this> */
    public function coaches(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'session_coach');
    }

    /** @return HasMany<Registration, $this> */
    public function registrations(): HasMany
    {
        return $this->hasMany(Registration::class);
    }

    /** @return HasMany<Debrief, $this> */
    public function debriefs(): HasMany
    {
        return $this->hasMany(Debrief::class);
    }

    /** @return HasMany<AperoFlag, $this> */
    public function aperoFlags(): HasMany
    {
        return $this->hasMany(AperoFlag::class);
    }

    /** Flags apéro actifs (non garés) — base des affichages planning/fiche/home (§4.14.5). */
    public function activeAperoFlags(): HasMany
    {
        return $this->aperoFlags()->whereNull('parked_at');
    }

    /** Au moins un payeur apéro actif ? Lit la relation chargée si dispo, sinon requête. */
    public function hasApero(): bool
    {
        if ($this->relationLoaded('activeAperoFlags')) {
            return $this->activeAperoFlags->isNotEmpty();
        }
        if ($this->relationLoaded('aperoFlags')) {
            return $this->aperoFlags->whereNull('parked_at')->isNotEmpty();
        }

        return $this->activeAperoFlags()->exists();
    }
}
