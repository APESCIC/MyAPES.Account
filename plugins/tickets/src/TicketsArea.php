<?php

namespace Plugins\Tickets;

use InvalidArgumentException;

/**
 * Per-module presentation for the shared Tickets plugin (#289).
 */
final readonly class TicketsArea
{
    public function __construct(
        public string $moduleSlug,
        public string $routeNamePrefix,
        public bool $usesHierarchicalCategories,
        public bool $supportsAttachments,
        public bool $supportsDelete,
    ) {}

    public static function forModule(string $moduleSlug): self
    {
        return match ($moduleSlug) {
            'apes-cic' => new self(
                moduleSlug: 'apes-cic',
                routeNamePrefix: 'apes-cic.tickets',
                usesHierarchicalCategories: true,
                supportsAttachments: true,
                supportsDelete: true,
            ),
            'shelter-rescue' => new self(
                moduleSlug: 'shelter-rescue',
                routeNamePrefix: 'shelter.tickets',
                usesHierarchicalCategories: false,
                supportsAttachments: true,
                supportsDelete: false,
            ),
            'pet-care-clinic' => new self(
                moduleSlug: 'pet-care-clinic',
                routeNamePrefix: 'petcare.tickets',
                usesHierarchicalCategories: false,
                supportsAttachments: true,
                supportsDelete: false,
            ),
            default => throw new InvalidArgumentException(
                "Tickets is not configured for module [{$moduleSlug}].",
            ),
        };
    }

    public function permission(string $ability): string
    {
        return "{$this->moduleSlug}.tickets.{$ability}";
    }

    public function permissionPrefix(): string
    {
        return "{$this->moduleSlug}.tickets.";
    }

    public function indexRouteName(): string
    {
        return "{$this->routeNamePrefix}.index";
    }

    public function showRouteName(): string
    {
        return "{$this->routeNamePrefix}.show";
    }
}
