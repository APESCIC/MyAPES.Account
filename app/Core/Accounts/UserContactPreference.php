<?php

namespace App\Core\Accounts;

use Illuminate\Database\Eloquent\Attributes\Cast;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['user_id', 'calls', 'sms', 'whatsapp', 'telegram', 'email', 'marketing_email', 'policy_version', 'confirmed_at'])]
class UserContactPreference extends Model
{
    protected $attributes = [
        'calls' => false,
        'sms' => false,
        'whatsapp' => false,
        'telegram' => false,
        'email' => false,
        'marketing_email' => false,
    ];

    #[Cast]
    protected function casts(): array
    {
        return [
            'calls' => 'boolean',
            'sms' => 'boolean',
            'whatsapp' => 'boolean',
            'telegram' => 'boolean',
            'email' => 'boolean',
            'marketing_email' => 'boolean',
            'confirmed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
