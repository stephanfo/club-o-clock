<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// auth_identities — linking d'un compte OAuth externe (cadrage §14.1). Google seul en V1.
class AuthIdentity extends Model
{
    protected $fillable = ['user_id', 'provider', 'provider_uid', 'email_at_link', 'linked_at'];

    /** @var array<string, string> */
    protected $casts = ['linked_at' => 'datetime'];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
