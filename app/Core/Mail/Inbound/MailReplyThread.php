<?php

namespace App\Core\Mail\Inbound;

use App\Core\Accounts\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The Message-ID of one ticket or case email and who it went to (#578), so a
 * reply's In-Reply-To or References header can be matched back to the record.
 */
#[Fillable([
    'message_id',
    'record_prefix',
    'record_id',
    'user_id',
    'created_at',
])]
class MailReplyThread extends Model
{
    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'record_id' => 'integer',
            'user_id' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
