<?php

namespace App\Core\Extensions\Plugins;

/**
 * One action inside a module mega menu, declared by a plugin (#885).
 *
 * Audience is {@see self::AUDIENCE_STAFF} or {@see self::AUDIENCE_PUBLIC}.
 * An optional {@see $anchor} (for example `#create`) is appended to the URL.
 */
final readonly class PluginMenuAction
{
    public const AUDIENCE_STAFF = 'staff';

    public const AUDIENCE_PUBLIC = 'public';

    public function __construct(
        public string $label,
        public string $routeName,
        public string $icon,
        public int $order,
        public string $audience,
        public ?string $moduleSlug = null,
        public ?string $requiredAbility = null,
        public ?string $anchor = null,
        public ?string $description = null,
        public ?string $labelKey = null,
        public ?string $descriptionKey = null,
    ) {}

    public function url(): string
    {
        $url = route($this->routeName);

        if ($this->anchor === null || $this->anchor === '') {
            return $url;
        }

        $fragment = str_starts_with($this->anchor, '#') ? $this->anchor : '#'.$this->anchor;

        return $url.$fragment;
    }
}
