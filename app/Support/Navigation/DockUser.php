<?php

namespace App\Support\Navigation;

/**
 * Signed-in identity shown in the rail's user dock (#415).
 */
final readonly class DockUser
{
    /**
     * @param  array{groups: list<array{key: string, label: string}>, stacks: list<array{key: string, label: string}>}|null  $qaPicker
     */
    public function __construct(
        public string $name,
        public string $initials,
        public string $roleLabel,
        public ?string $qaRole,
        public ?string $qaProfile = null,
        public ?array $qaPicker = null,
        public bool $qaConfirm = false,
        public ?string $qaReturnTo = null,
    ) {}
}
