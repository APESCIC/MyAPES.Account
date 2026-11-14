<?php

namespace App\Support\Navigation;

/**
 * One flat workspace link (#384). Gating happens before an item is built, so
 * every NavItem the builder returns is one the user may open.
 */
final readonly class NavItem
{
    /**
     * @param  list<string>  $activePatterns  route-name patterns that make this item current
     * @param  list<string>  $keywords  extra words the command palette matches on
     * @param  array<string, string>  $activeQuery  request values that must also match, e.g. account_type
     * @param  int|null  $badge  optional count shown beside the label (hidden when null or 0)
     */
    public function __construct(
        public string $key,
        public string $label,
        public string $url,
        public string $icon,
        public array $activePatterns = [],
        public array $keywords = [],
        public array $activeQuery = [],
        public ?int $badge = null,
    ) {}

    /**
     * @param  list<string>  $patterns
     */
    public function withDestination(string $url, array $patterns): self
    {
        return new self(
            $this->key,
            $this->label,
            $url,
            $this->icon,
            array_values(array_unique([...$this->activePatterns, ...$patterns])),
            $this->keywords,
            $this->activeQuery,
            $this->badge,
        );
    }

    public function withBadge(?int $badge): self
    {
        return new self(
            $this->key,
            $this->label,
            $this->url,
            $this->icon,
            $this->activePatterns,
            $this->keywords,
            $this->activeQuery,
            $badge !== null && $badge > 0 ? $badge : null,
        );
    }
}
