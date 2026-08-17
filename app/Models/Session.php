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

    protected $fillable = [
        'kind', 'title', 'discipline_id', 'start_at', 'duration_min',
        'location_id', 'location_text', 'capacity', 'visibility',
        'created_by', 'source_template_id',
        // training
        'quota_tag_id', 'content_markdown', 'content_attachment_path',
        // competition
        'event_type_id', 'distance', 'external_url', 'photos_album_url',
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

    public function isCancelled(): bool
    {
        return $this->cancelled_at !== null;
    }

    /** Séance commencée → inscriptions/désinscriptions bloquées sur les 3 kind (§4.9.1). */
    public function hasStarted(): bool
    {
        return $this->start_at->isPast();
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
