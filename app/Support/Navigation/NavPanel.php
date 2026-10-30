<?php

namespace App\Support\Navigation;

final readonly class NavPanel
{
    public const STAFF = 'staff';

    public const ADMIN = 'admin';

    public const PUBLIC = 'public';

    /**
     * @param  list<NavGroup>  $groups  only non-empty groups
     */
    public function __construct(
        public string $key,
        public string $label,
        public array $groups,
    ) {}

    /**
     * The panel home is its first visible item.
     */
    public function home(): ?NavItem
    {
        return $this->groups[0]->items[0] ?? null;
    }

    /**
     * @return list<NavItem>
     */
    public function items(): array
    {
        return array_merge(...array_map(static fn (NavGroup $group): array => $group->items, $this->groups));
    }
}
