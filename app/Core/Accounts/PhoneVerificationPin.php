<?php

namespace App\Core\Accounts;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A user's phone verification PIN (#476). Only per-position keyed hashes
 * are stored (decision D2), never the PIN or any digit.
 */
#[Fillable(['user_id', 'length', 'salt', 'digit_hashes', 'set_at'])]
#[Hidden(['salt', 'digit_hashes'])]
class PhoneVerificationPin extends Model
{
    protected function casts(): array
    {
        return [
            'length' => 'integer',
            'digit_hashes' => 'array',
            'set_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
