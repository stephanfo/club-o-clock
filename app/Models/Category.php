<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

// Catégorie d'âge (PRD §5.1, §4.5). Bornes inclusives, pas de chevauchement entre actives.
class Category extends Model
{
    protected $fillable = ['label', 'age_min', 'age_max', 'sort_order', 'archived_at'];

    /** @var array<string, string> */
    protected $casts = [
        'age_min' => 'integer',
        'age_max' => 'integer',
        'archived_at' => 'datetime',
    ];

    /** @return BelongsToMany<User, $this> */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'user_category')->withPivot('is_primary');
    }

    /**
     * Séances ciblant cette catégorie (M:N — pour le comptage de références §4.6).
     *
     * @return BelongsToMany<Session, $this>
     */
    public function sessions(): BelongsToMany
    {
        return $this->belongsToMany(Session::class, 'session_category');
    }

    /**
     * Modèles ciblant cette catégorie (M:N template_category — comptage de réfs §4.6).
     *
     * @return BelongsToMany<SessionTemplate, $this>
     */
    public function templates(): BelongsToMany
    {
        return $this->belongsToMany(SessionTemplate::class, 'template_category');
    }
}
