<?php

namespace App\Support\Navigation;

/**
 * Signed-in identity shown in the rail's user dock (#415).
 */
final readonly class DockUser
{
    public function __construct(
        public string $name,
        public string $initials,
        public string $roleLabel,
        public ?string $qaRole,
    ) {}
}
