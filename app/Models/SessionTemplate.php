<?php

namespace App\Models;

use Database\Factories\SessionTemplateFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

// Entité SessionTemplate (générateur, PRD §5.1, §4.8). Produit des Session indépendantes.
class SessionTemplate extends Model
{
    /** @use HasFactory<SessionTemplateFactory> */
    use HasFactory;

    protected $fillable = [
        'label', 'kind', 'discipline_id', 'day_of_week', 'start_time_of_day', 'duration_min',
        'location_id', 'location_text', 'capacity', 'quota_tag_id',
        'generation_start_date', 'generation_end_date', 'created_by', 'status',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'day_of_week' => 'integer',
        'duration_min' => 'integer',
        'capacity' => 'integer',
        'generation_start_date' => 'date',
        'generation_end_date' => 'date',
    ];

    /** @return BelongsTo<Discipline, $this> */
    public function discipline(): BelongsTo
    {
        return $this->belongsTo(Discipline::class);
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

    /** @return BelongsToMany<Category, $this> */
    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(Category::class, 'template_category');
    }

    /** @return BelongsToMany<User, $this> */
    public function defaultCoaches(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'template_coach');
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Séances générées par ce modèle (lien audit-only via source_template_id — §4.8).
     *
     * @return HasMany<Session, $this>
     */
    public function sessions(): HasMany
    {
        return $this->hasMany(Session::class, 'source_template_id');
    }

    /** Modèle actif (non archivé) ? (§4.8 status). */
    public function isArchived(): bool
    {
        return $this->status === 'archived';
    }
}
