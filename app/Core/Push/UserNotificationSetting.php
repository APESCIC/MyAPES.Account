<?php

namespace App\Core\Push;

use App\Core\Accounts\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'user_id',
    'quiet_hours_enabled',
    'quiet_hours_start',
    'quiet_hours_end',
    'quiet_hours_timezone',
])]
class UserNotificationSetting extends Model
{
    protected function casts(): array
    {
        return [
            'quiet_hours_enabled' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
