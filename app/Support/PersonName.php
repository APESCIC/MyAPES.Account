<?php

namespace App\Support;

/**
 * Split and compose display names for client identity fields (#910).
 */
final class PersonName
{
    /**
     * Split a display name into first and last name.
     *
     * Two or more whitespace-separated tokens: first token = first name,
     * remainder = last name. A single token or blank name is ambiguous —
     * both parts are null so staff can review them rather than guessing.
     *
     * @return array{first_name: ?string, last_name: ?string, ambiguous: bool}
     */
    public static function split(string $displayName): array
    {
        $trimmed = trim(preg_replace('/\s+/u', ' ', $displayName) ?? '');

        if ($trimmed === '') {
            return [
                'first_name' => null,
                'last_name' => null,
                'ambiguous' => true,
            ];
        }

        $parts = explode(' ', $trimmed, 2);

        if (count($parts) < 2 || trim($parts[1]) === '') {
            return [
                'first_name' => null,
                'last_name' => null,
                'ambiguous' => true,
            ];
        }

        return [
            'first_name' => $parts[0],
            'last_name' => trim($parts[1]),
            'ambiguous' => false,
        ];
    }

    public static function display(?string $firstName, ?string $lastName, ?string $fallback = null): string
    {
        $parts = array_values(array_filter([
            is_string($firstName) ? trim($firstName) : null,
            is_string($lastName) ? trim($lastName) : null,
        ], static fn (?string $part): bool => $part !== null && $part !== ''));

        if ($parts !== []) {
            return implode(' ', $parts);
        }

        return is_string($fallback) ? trim($fallback) : '';
    }
}
