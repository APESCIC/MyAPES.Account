<?php

namespace App\Core\Extensions\Navigation;

use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Public (guest / signed-in) primary-nav contribution from a plugin (#282 / #287).
 */
final readonly class PublicNavigationItem
{
    /**
     * @param  (\Closure(Authenticatable): bool)|null  $visibleToSignedIn  null shows the item to every signed-in user
     */
    public function __construct(
        public string $label,
        public string $routeName,
        public string $icon,
        public int $order,
        public string $routeIsPattern,
        public bool $enabled,
        public ?\Closure $visibleToSignedIn = null,
    ) {}
}
