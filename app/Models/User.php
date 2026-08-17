<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * Rôles cumulables (PRD §5.1). Le statut parent garant est une relation, pas un rôle.
     */
    public const ROLES = ['athlete', 'coach', 'admin'];

    /**
     * Tampon RGPD minimum bloquant avant suppression définitive (PRD §4.3), en jours.
     * Délai *minimum* (pas maximum) : le compte reste éligible indéfiniment après J+7.
     */
    public const DELETION_BUFFER_DAYS = 7;

    /**
     * Attributs mass-assignables. Explicitement listés : roles/flags exposés mais protégés
     * par validation amont. anonymized_at / deletion_requested_at NON fillable (posés par
     * des flows serveur dédiés, jamais par un formulaire).
     *
     * @var list<string>
     */
    protected $fillable = [
        'first_name',
        'last_name',
        'email',
        'password',
        'dob',
        'roles',
        'is_active',
        'athlete_access_suspended',
        'is_minor',
        'guardian_id',
        'guardianship_linked_at',
    ];

    /**
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'email_verified_at' => 'datetime',
        'password' => 'hashed',
        'dob' => 'date',
        'roles' => 'array',
        'is_active' => 'boolean',
        'athlete_access_suspended' => 'boolean',
        'is_minor' => 'boolean',
        'guardianship_linked_at' => 'datetime',
        'deletion_requested_at' => 'datetime',
        'anonymized_at' => 'datetime',
    ];

    // --- Rôles ---

    public function hasRole(string $role): bool
    {
        return in_array($role, $this->roles ?? [], true);
    }

    public function isAdmin(): bool
    {
        return $this->hasRole('admin');
    }

    /**
     * Ajoute un rôle au cumul, sans doublon et sans sauvegarder.
     *
     * @return bool true si le rôle a été ajouté, false s'il était déjà présent
     */
    public function grantRole(string $role): bool
    {
        if ($this->hasRole($role)) {
            return false;
        }

        $this->roles = array_values(array_unique([...$this->roles ?? [], $role]));

        return true;
    }

    /**
     * Recherche par email, insensible à la casse.
     *
     * Point de passage unique des quatre chemins d'authentification (mot de passe, lien magique
     * ×2, OAuth) et de l'amorçage CLI : la normalisation de l'email est une règle de sécurité, un
     * chemin qui l'oublierait créerait un compte parallèle ou raterait une garde. Si elle évolue
     * (repli des accents, colonne indexée en minuscules pour éviter le LOWER() non sargable), elle
     * n'a qu'un endroit à changer.
     */
    public static function findByEmail(?string $email): ?self
    {
        $email = mb_strtolower(trim((string) $email));

        if ($email === '') {
            return null;
        }

        return static::query()->whereRaw('LOWER(email) = ?', [$email])->first();
    }

    // --- Parent garant (PRD §4.2) ---

    /**
     * Le parent garant de cet utilisateur (0..1).
     *
     * @return BelongsTo<User, $this>
     */
    public function guardian(): BelongsTo
    {
        return $this->belongsTo(self::class, 'guardian_id');
    }

    /**
     * Les enfants dont cet utilisateur est garant.
     *
     * @return HasMany<User, $this>
     */
    public function wards(): HasMany
    {
        return $this->hasMany(self::class, 'guardian_id');
    }

    // --- Relations métier ---

    /** @return BelongsToMany<Category, $this> */
    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(Category::class, 'user_category')
            ->withPivot('is_primary');
    }

    /** @return BelongsToMany<Qualification, $this> */
    public function qualifications(): BelongsToMany
    {
        return $this->belongsToMany(Qualification::class, 'user_qualification')
            ->withPivot('expires_at', 'attributed_at', 'attributed_by');
    }

    /** Catégorie principale (pivot is_primary) si rattachée — sinon null (cas limite §4.5). */
    public function primaryCategory(): ?Category
    {
        return $this->categories->firstWhere('pivot.is_primary', true);
    }

    /** Surclassements = catégories rattachées non-principales (§4.5). */
    public function surclassements(): Collection
    {
        return $this->categories->where('pivot.is_primary', false)->values();
    }

    /**
     * A-t-il au moins une catégorie active (non archivée) rattachée ? (§4.5) Une catégorie archivée
     * ne compte pas : elle a disparu des sélections mais reste sur les rattachements historiques.
     * Un athlète sans catégorie active ne peut s'inscrire à aucune séance (§4.5, cas limite).
     */
    public function hasActiveCategory(): bool
    {
        return $this->categories->whereNull('archived_at')->isNotEmpty();
    }

    /**
     * Le ciblage de $session inclut-il cet athlète ? (§4.5) Vrai si la séance ne cible aucune
     * catégorie (= ouverte à toutes, même sémantique que scopeVisibleToCategories et que les
     * notifications) ou si elle cible une catégorie active de l'athlète. Sert de garde serveur à
     * l'inscription (défense en profondeur — la fiche reste atteignable par URL directe). NE
     * vérifie PAS que l'athlète a une catégorie active : ce cas (§4.5 « aucune séance ») est
     * porté par hasActiveCategory(), à combiner dans les gardes.
     */
    public function isTargetedBy(Session $session): bool
    {
        if ($session->categories->isEmpty()) {
            return true; // Séance sans ciblage = ouverte à toutes les catégories.
        }

        $mine = $this->categories->whereNull('archived_at')->pluck('id');

        return $session->categories->pluck('id')->intersect($mine)->isNotEmpty();
    }

    /**
     * Séances où cet utilisateur est inscrit comme encadrant (coaches[], §4.11).
     *
     * @return BelongsToMany<Session, $this>
     */
    public function coachSessions(): BelongsToMany
    {
        return $this->belongsToMany(Session::class, 'session_coach');
    }

    public function fullName(): string
    {
        return trim("{$this->first_name} {$this->last_name}");
    }

    /** @return HasMany<Registration, $this> */
    public function registrations(): HasMany
    {
        return $this->hasMany(Registration::class);
    }

    /** @return HasMany<AuthIdentity, $this> */
    public function authIdentities(): HasMany
    {
        return $this->hasMany(AuthIdentity::class);
    }

    /** @return HasOne<NotificationPreferences, $this> */
    public function notificationPreferences(): HasOne
    {
        return $this->hasOne(NotificationPreferences::class);
    }

    /**
     * Abonnements Web Push, un par appareil (J8.6).
     *
     * @return HasMany<PushSubscription, $this>
     */
    public function pushSubscriptions(): HasMany
    {
        return $this->hasMany(PushSubscription::class);
    }

    // --- Cycle de vie : suppression RGPD (PRD §4.3) ---

    /** Une demande de suppression est en cours (dans le tampon ou au-delà), compte non encore anonymisé. */
    public function isDeletionPending(): bool
    {
        return $this->deletion_requested_at !== null && $this->anonymized_at === null;
    }

    /** Date à partir de laquelle la suppression définitive devient possible (J+7), ou null. */
    public function deletionEligibleAt(): ?Carbon
    {
        return $this->deletion_requested_at?->copy()->addDays(self::DELETION_BUFFER_DAYS);
    }

    /**
     * Le tampon de 7 j est écoulé → suppression définitive autorisée. Cette garde est
     * appliquée côté UI ET côté serveur (§4.3) : ne jamais confirmer une suppression sans elle.
     */
    public function isDeletionEligible(): bool
    {
        $at = $this->deletionEligibleAt();

        return $this->isDeletionPending() && $at !== null && Carbon::now()->greaterThanOrEqualTo($at);
    }

    /** Scope : comptes avec une demande de suppression en cours (tampon ou au-delà). */
    public function scopeDeletionPending(Builder $query): Builder
    {
        return $query->whereNull('anonymized_at')->whereNotNull('deletion_requested_at');
    }

    /** Scope : comptes dont le tampon 7 j est écoulé — éligibles à la suppression définitive. */
    public function scopeDeletionEligible(Builder $query): Builder
    {
        return $query->deletionPending()
            ->where('deletion_requested_at', '<=', Carbon::now()->subDays(self::DELETION_BUFFER_DAYS));
    }
}
