<?php

namespace App\Support\Navigation;

final readonly class NavGroup
{
    /**
     * @param  list<NavItem>  $items
     */
    public function __construct(
        public string $key,
        public string $heading,
        public array $items,
    ) {}

    public function isEmpty(): bool
    {
        return $this->items === [];
    }
}
