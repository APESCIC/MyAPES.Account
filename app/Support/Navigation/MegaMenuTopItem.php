<?php

namespace App\Support\Navigation;

/**
 * One top-bar module (or Clients / Admin) trigger and its mega menu (#885).
 *
 * {@see $groups} are plugin columns (or Admin regroupings), each a {@see NavGroup}
 * of action links. Overview is {@see $url} and is also the first item of the
 * first group when the builder adds it.
 */
final readonly class MegaMenuTopItem
{
    /**
     * @param  list<NavGroup>  $groups
     * @param  list<string>  $activePatterns
     */
    public function __construct(
        public string $key,
        public string $label,
        public string $icon,
        public string $url,
        public array $groups,
        public array $activePatterns = [],
        public ?string $description = null,
        /** Full service name for aria-labels when {@see $label} is a short trigger (#1045). */
        public ?string $fullLabel = null,
    ) {}

    public function title(): string
    {
        return $this->fullLabel ?? $this->label;
    }

    /**
     * @return list<NavItem>
     */
    public function items(): array
    {
        return array_merge(...array_map(
            static fn (NavGroup $group): array => $group->items,
            $this->groups,
        ));
    }

    public function isEmpty(): bool
    {
        foreach ($this->groups as $group) {
            if ($group->key === 'overview') {
                continue;
            }

            if (! $group->isEmpty()) {
                return false;
            }
        }

        return true;
    }
}
