<?php

namespace App\Models;

use App\Support\Trombi\ConsentType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// Registre des accords (PRD §5.1 `Consent`, cadrage §7.18). AJOUT SEUL : un retrait pose
// `revoked_at` sur la ligne active, un nouvel accord crée une ligne. L'historique est la preuve.
// Seul ConsentService y écrit.
class Consent extends Model
{
    public $timestamps = false;

    protected $fillable = ['user_id', 'type', 'version', 'granted_at', 'granted_by', 'source', 'revoked_at', 'revoked_by'];

    /** @var array<string, string> */
    protected $casts = [
        'type' => ConsentType::class,
        'version' => 'integer',
        'granted_at' => 'datetime',
        'revoked_at' => 'datetime',
    ];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<User, $this> */
    public function grantor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'granted_by');
    }

    /** Ligne non révoquée ET sur la version courante du texte de son type. */
    public function isActive(): bool
    {
        return $this->revoked_at === null && $this->version === $this->type->version();
    }

    /**
     * Lignes non révoquées. La version se juge en PHP (isActive) : elle diffère par type.
     *
     * @param  Builder<Consent>  $query
     */
    public function scopeUnrevoked(Builder $query): void
    {
        $query->whereNull('revoked_at');
    }
}
