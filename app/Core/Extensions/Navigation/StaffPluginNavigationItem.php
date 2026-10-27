<?php

namespace App\Core\Extensions\Navigation;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Gate;

/**
 * Signed-in primary-nav contribution from a plugin (e.g. Recruitment) (#282 / #346).
 */
final readonly class StaffPluginNavigationItem
{
    /**
     * @param  string|list<string>  $routeIsPattern
     * @param  list<string>  $abilities  any-of permission check (ignored when $destination is set)
     * @param  (\Closure(Authenticatable): ?string)|null  $destination  home URL for the user, or null to hide the item
     */
    public function __construct(
        public string $label,
        public string $icon,
        public int $order,
        public string|array $routeIsPattern,
        public array $abilities,
        public bool $enabled,
        public string $homeRouteName,
        public ?string $fallbackRouteName = null,
        public ?string $homeAbility = null,
        public ?\Closure $destination = null,
    ) {}

    /**
     * @return list<string>
     */
    public function routePatterns(): array
    {
        return (array) $this->routeIsPattern;
    }

    public function homeUrlFor(Authenticatable $user): ?string
    {
        if ($this->destination !== null) {
            return ($this->destination)($user);
        }

        $gate = Gate::forUser($user);

        if (! $gate->any($this->abilities)) {
            return null;
        }

        $canUseHome = $this->homeAbility === null
            || $gate->allows($this->homeAbility)
            || $gate->allows(str_replace('.view-all', '.view-own', $this->homeAbility));

        return $canUseHome
            ? route($this->homeRouteName)
            : route($this->fallbackRouteName ?? $this->homeRouteName);
    }
}
