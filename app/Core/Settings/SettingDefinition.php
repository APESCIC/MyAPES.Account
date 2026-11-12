<?php

namespace App\Core\Settings;

/**
 * One admin setting (#732): its type, whether it is secret, the .env
 * variable that overrides it, its default and its validation rules.
 */
final class SettingDefinition
{
    public const STRING = 'string';

    public const INTEGER = 'integer';

    public const BOOLEAN = 'boolean';

    /**
     * @param  list<mixed>  $rules  Laravel validation rules for a submitted value.
     */
    public function __construct(
        public readonly string $group,
        public readonly string $key,
        public readonly string $type = self::STRING,
        public readonly bool $secret = false,
        public readonly ?string $env = null,
        public readonly mixed $default = null,
        public readonly array $rules = [],
    ) {}

    public function cast(mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }

        return match ($this->type) {
            self::INTEGER => (int) $value,
            self::BOOLEAN => filter_var($value, FILTER_VALIDATE_BOOLEAN),
            default => (string) $value,
        };
    }

    public function serialise(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return match ($this->type) {
            self::BOOLEAN => filter_var($value, FILTER_VALIDATE_BOOLEAN) ? '1' : '0',
            default => (string) $value,
        };
    }
}
