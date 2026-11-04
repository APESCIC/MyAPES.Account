<?php

namespace App\Core\Accounts;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A staff record of a phone call with a public account holder (#479).
 * Only staff roles and administrators can see these; never the caller.
 */
#[Fillable(['caller_id', 'staff_id', 'direction', 'summary', 'called_at'])]
class CallLog extends Model
{
    public const DIRECTION_INBOUND = 'inbound';

    public const DIRECTION_OUTBOUND = 'outbound';

    public const DIRECTIONS = [self::DIRECTION_INBOUND, self::DIRECTION_OUTBOUND];

    protected function casts(): array
    {
        return [
            'called_at' => 'datetime',
        ];
    }

    public function caller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'caller_id');
    }

    public function staff(): BelongsTo
    {
        return $this->belongsTo(User::class, 'staff_id');
    }
}
