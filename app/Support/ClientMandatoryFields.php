<?php

namespace App\Support;

use App\Core\Accounts\User;
use App\Core\Accounts\UserProfile;
use Illuminate\Database\Eloquent\Builder;

/**
 * Shared mandatory client identity fields for data-quality lists (#914).
 */
final class ClientMandatoryFields
{
    /**
     * @return list<string>
     */
    public static function keys(): array
    {
        return [
            'first_name',
            'last_name',
            'date_of_birth',
            'address_line_1',
            'town_city',
            'postcode',
            'mobile_number',
        ];
    }

    /**
     * @param  Builder<User>  $query
     * @return Builder<User>
     */
    public static function scopeMissingDetails(Builder $query): Builder
    {
        return $query->where(function (Builder $outer): void {
            $outer->whereDoesntHave('profile')
                ->orWhereHas('profile', function (Builder $profile): void {
                    $profile->where(function (Builder $missing): void {
                        foreach (self::keys() as $key) {
                            $missing->orWhereNull($key)->orWhere($key, '');
                        }
                    });
                });
        });
    }

    /**
     * @return list<string>
     */
    public static function missingFor(?UserProfile $profile): array
    {
        if ($profile === null) {
            return self::keys();
        }

        $missing = [];

        foreach (self::keys() as $key) {
            $value = $profile->{$key};

            if ($value === null || (is_string($value) && trim($value) === '')) {
                $missing[] = $key;
            }
        }

        return $missing;
    }

    public static function isIncomplete(User $user): bool
    {
        return self::missingFor($user->profile) !== [];
    }
}
