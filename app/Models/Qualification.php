<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

// Catalogue Qualification coach (PRD §5.1, §4.11.3).
class Qualification extends Model
{
    protected $fillable = ['label', 'code', 'sort_order', 'archived_at'];

    /** @var array<string, string> */
    protected $casts = ['archived_at' => 'datetime'];

    /**
     * Coachs détenteurs de cette qualif (M:N — pour le comptage de références §4.6).
     *
     * @return BelongsToMany<User, $this>
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'user_qualification')
            ->withPivot('expires_at', 'attributed_at', 'attributed_by');
    }
}
