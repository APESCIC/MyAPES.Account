<?php

namespace App\Core\Secrets;

/**
 * One managed secret in Admin → Keys and secrets (#810): its storage key,
 * the optional .env fallback, and human-facing labels.
 */
final class SecretDefinition
{
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly ?string $env = null,
        public readonly string $description = '',
    ) {}
}
