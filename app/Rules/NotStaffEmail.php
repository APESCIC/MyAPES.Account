<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

/**
 * Keep staff email domains out of public accounts (#513).
 *
 * Emails already held by any account, staff included, are refused by
 * AvailablePublicEmail with a generic message, so this rule only checks the
 * configured staff domains and their subdomains.
 */
class NotStaffEmail implements ValidationRule
{
    /**
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! str_contains($value, '@')) {
            return;
        }

        if (self::isStaffDomain($value)) {
            $fail(__('auth.register.staff_email_domain'));
        }
    }

    public static function isStaffDomain(string $email): bool
    {
        $domain = strtolower(trim((string) substr(strrchr($email, '@') ?: '', 1)));

        if ($domain === '') {
            return false;
        }

        foreach ((array) config('myapes.accounts.staff_email_domains', []) as $staffDomain) {
            $staffDomain = ltrim(strtolower(trim((string) $staffDomain)), '@.');

            if ($staffDomain !== '' && ($domain === $staffDomain || str_ends_with($domain, '.'.$staffDomain))) {
                return true;
            }
        }

        return false;
    }
}
