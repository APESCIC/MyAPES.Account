<?php

namespace App\Core\Push;

/**
 * Same-origin deep link for a push notification (#799).
 *
 * {@see PushDeepLinkCatalogue} documents which named routes enforce auth and record policies.
 */
final readonly class PushTarget
{
    /**
     * @param  array<string, mixed>  $routeParameters
     */
    public function __construct(
        public string $routeName,
        public array $routeParameters = [],
        public ?string $referenceLabel = null,
        public ?string $serviceLabel = null,
    ) {}

    public function referenceForBody(): string
    {
        if ($this->referenceLabel === null || $this->referenceLabel === '') {
            return '';
        }

        return ltrim($this->referenceLabel, '#');
    }
}