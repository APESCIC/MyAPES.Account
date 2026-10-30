<?php

namespace App\Support\Navigation;

/**
 * Command palette action beyond plain navigation (#389).
 */
final readonly class QuickAction
{
    /**
     * @param  list<string>  $keywords
     */
    public function __construct(
        public string $key,
        public string $label,
        public string $url,
        public string $icon,
        public string $context = '',
        public array $keywords = [],
    ) {}
}
