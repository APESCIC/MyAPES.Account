<?php

namespace App\Modules;

final readonly class ModuleNavigationDefinition
{
    /**
     * @param  string|null  $requiredAbility  plugin ability (e.g. `view-all`) required to show the hub tile; null accepts view-own or view-all
     */
    public function __construct(
        public string $label,
        public string $routeName,
        public string $icon,
        public int $order,
        public ?string $requiredAbility = null,
    ) {}
}
