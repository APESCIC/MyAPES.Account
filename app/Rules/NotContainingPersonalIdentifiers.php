<?php

namespace App\Rules;

use App\Core\Accounts\User;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Str;

/**
 * Rejects passwords that contain the user's name, username or email local-part (#941).
 */
final class NotContainingPersonalIdentifiers implements ValidationRule
{
    public function __construct(
        private readonly User $user,
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || $value === '') {
            return;
        }

        $haystack = Str::lower($value);
        $parts = array_filter([
            $this->user->username,
            $this->user->email ? Str::before($this->user->email, '@') : null,
            $this->user->name,
            $this->user->profile?->first_name,
            $this->user->profile?->last_name,
        ], static fn (?string $part): bool => is_string($part) && strlen($part) >= 3);

        foreach ($parts as $part) {
            if (str_contains($haystack, Str::lower($part))) {
                $fail(__('auth.forced_password.contains_personal'));

                return;
            }
        }
    }
}
