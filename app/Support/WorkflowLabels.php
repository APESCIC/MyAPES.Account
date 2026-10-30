<?php

namespace App\Support;

use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Title Case display labels for stored workflow values, backed by lang/en_GB/terms.php.
 *
 * Stored values stay snake_case; only the display changes. Unknown values fall back to a
 * headline of the stored value so screens never show raw snake_case.
 */
final class WorkflowLabels
{
    public const GROUPS = [
        'status',
        'priority',
        'case_type',
        'recruitment_category',
        'sex',
        'neutering_status',
        'ticket_area',
    ];

    public static function label(string $group, ?string $value): string
    {
        if (! in_array($group, self::GROUPS, true)) {
            throw new InvalidArgumentException("Unknown workflow label group [{$group}].");
        }

        if ($value === null || $value === '') {
            return '';
        }

        $key = "terms.{$group}.{$value}";

        return trans()->has($key) ? __($key) : Str::headline($value);
    }

    public static function status(?string $value): string
    {
        return self::label('status', $value);
    }

    public static function priority(?string $value): string
    {
        return self::label('priority', $value);
    }

    /**
     * @param  iterable<string>  $values
     * @return array<string, string> Stored value => label, in the given order.
     */
    public static function options(string $group, iterable $values): array
    {
        $options = [];

        foreach ($values as $value) {
            $options[$value] = self::label($group, $value);
        }

        return $options;
    }
}
