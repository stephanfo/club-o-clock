<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// Page d'information (note club) : bon d'achat, code partenaire, info générale.
// Visibilité par niveau cumulatif (all|coach|admin), épinglable en bannière d'accueil.
// Édition admin uniquement (Gate manage-information-pages). Soft-delete admin (archived_at).
class InformationPage extends Model
{
    /** Niveaux de visibilité, du plus large au plus restreint. */
    public const VISIBILITIES = ['all', 'coach', 'admin'];

    protected $fillable = [
        'title', 'content_markdown', 'visibility', 'pinned', 'position',
        'created_by', 'archived_at', 'archived_by',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'pinned' => 'boolean',
        'archived_at' => 'datetime',
    ];

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }

    /** Libellé FR du niveau de visibilité (pas d'i18n centralisé dans le projet). */
    public function visibilityLabel(): string
    {
        return match ($this->visibility) {
            'admin' => 'Admin uniquement',
            'coach' => 'Coachs et admin',
            default => 'Tous les adhérents',
        };
    }

    /** Pages non archivées (liste publique). */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('archived_at');
    }

    /**
     * Ordre d'affichage canonique : ordre manuel (position) seul, puis date de création
     * en filet de sécurité (positions égales / non initialisées). L'épinglage ne joue AUCUN
     * rôle dans le tri — il décide seulement quelles pages apparaissent en bannière d'accueil.
     * Utilisé côté /infos, dans la liste admin ET pour l'empilement des bannières.
     */
    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('position')->latest();
    }

    /**
     * Filtre selon le niveau de visibilité par rapport au regardeur.
     * admin → tout ; coach → all+coach ; athlète → all uniquement.
     * Le regardeur est TOUJOURS auth()->user() (pas le sujet parent/enfant).
     */
    public function scopeVisibleTo(Builder $query, ?User $user): Builder
    {
        if ($user?->isAdmin()) {
            return $query;
        }

        $levels = $user?->hasRole('coach') ? ['all', 'coach'] : ['all'];

        return $query->whereIn('visibility', $levels);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return BelongsTo<User, $this> */
    public function archiver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'archived_by');
    }
}
