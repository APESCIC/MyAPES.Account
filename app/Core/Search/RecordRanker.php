<?php

namespace App\Core\Search;

/**
 * Shared exact-first ranking for record search hits (#529).
 *
 * Lower scores rank first. Exact reference / microchip beats partial name/email/phone.
 */
final class RecordRanker
{
    public const EXACT_IDENTIFIER = 0;

    public const EXACT_FIELD = 10;

    public const STARTS_WITH = 20;

    public const CONTAINS = 40;

    public const NO_MATCH = 1000;

    /**
     * @param  list<string|null>  $partialFields
     */
    public static function bestScore(
        string $q,
        ?string $reference = null,
        ?string $microchip = null,
        array $partialFields = [],
        ?string $recordStatus = null,
        ?string $statusFilter = null,
    ): ?int {
        $needle = self::normalise($q);
        if ($needle === '') {
            return null;
        }

        if ($statusFilter !== null && $statusFilter !== '') {
            if (self::normalise((string) $recordStatus) !== self::normalise($statusFilter)) {
                return null;
            }
        }

        $exact = self::scoreExactReferenceOrMicrochip($needle, $reference, $microchip);
        if ($exact !== null) {
            return $exact;
        }

        $fieldScore = self::scoreFields($needle, $partialFields);

        if ($recordStatus !== null && self::normalise($recordStatus) === $needle) {
            $fieldScore = min($fieldScore, self::EXACT_FIELD);
        }

        return $fieldScore >= self::NO_MATCH ? null : $fieldScore;
    }

    public static function scoreExactReferenceOrMicrochip(
        string $q,
        ?string $reference = null,
        ?string $microchip = null,
    ): ?int {
        $needle = self::normalise($q);
        if ($needle === '') {
            return null;
        }

        $candidates = array_filter([
            self::normalise((string) $reference),
            self::normalise(ltrim((string) $reference, '#')),
            self::normalise((string) $microchip),
        ], static fn (string $value): bool => $value !== '');

        foreach ($candidates as $candidate) {
            if ($candidate === $needle) {
                return self::EXACT_IDENTIFIER;
            }
        }

        return null;
    }

    /**
     * @param  list<string|null>  $fields
     */
    public static function scoreFields(string $q, array $fields): int
    {
        $needle = self::normalise($q);
        if ($needle === '') {
            return self::NO_MATCH;
        }

        $best = self::NO_MATCH;

        foreach ($fields as $field) {
            $haystack = self::normalise((string) $field);
            if ($haystack === '') {
                continue;
            }

            if ($haystack === $needle) {
                $best = min($best, self::EXACT_FIELD);
            } elseif (str_starts_with($haystack, $needle)) {
                $best = min($best, self::STARTS_WITH);
            } elseif (str_contains($haystack, $needle)) {
                $best = min($best, self::CONTAINS);
            }
        }

        return $best;
    }

    public static function normalise(string $value): string
    {
        $folded = \Normalizer::normalize(trim($value), \Normalizer::FORM_D);
        if (! is_string($folded)) {
            $folded = trim($value);
        }

        $ascii = preg_replace('/\p{Mn}/u', '', $folded) ?? $folded;

        return mb_strtolower($ascii);
    }
}
