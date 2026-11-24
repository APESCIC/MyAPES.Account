<?php

namespace App\Core\Accounts;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'user_id',
    'preferred_name',
    'first_name',
    'last_name',
    'date_of_birth',
    'phone',
    'organization',
    'support_needs',
    'avatar_path',
    'address_line_1',
    'address_line_2',
    'town_city',
    'county',
    'postcode',
    'country',
    'mobile_number',
    'landline_number',
    'whatsapp_number',
    'telegram_username',
])]
class UserProfile extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'date_of_birth' => 'date',
        ];
    }

    public function effectiveWhatsappNumber(): ?string
    {
        return $this->whatsapp_number ?: $this->mobile_number;
    }

    public function hasAmbiguousNameSplit(): bool
    {
        $first = is_string($this->first_name) ? trim($this->first_name) : '';
        $last = is_string($this->last_name) ? trim($this->last_name) : '';

        return $first === '' || $last === '';
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
