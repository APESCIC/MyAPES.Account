<?php

namespace Plugins\Cases;

use InvalidArgumentException;

/**
 * Per-module presentation for the shared Cases plugin (#289).
 *
 * Flavour separates APES CIC category/attachment cases from Shelter pet-linked cases.
 */
final readonly class CasesArea
{
    public const FLAVOUR_CIC = 'cic';

    public const FLAVOUR_SHELTER = 'shelter';

    /**
     * @param  list<string>  $priorities
     * @param  list<string>  $statuses
     * @param  list<string>  $caseTypes
     */
    public function __construct(
        public string $moduleSlug,
        public string $routeNamePrefix,
        public string $flavour,
        public string $auditEventPrefix,
        public string $viewNamespace,
        public string $serviceLabel,
        public array $priorities,
        public array $statuses,
        public array $caseTypes,
        public bool $usesCategories,
        public bool $usesPetProfiles,
        public bool $supportsDelete,
        public bool $supportsAttachments,
    ) {}

    public static function forModule(string $moduleSlug): self
    {
        return match ($moduleSlug) {
            'apes-cic' => new self(
                moduleSlug: 'apes-cic',
                routeNamePrefix: 'apes-cic.cases',
                flavour: self::FLAVOUR_CIC,
                auditEventPrefix: 'apes_cic.case',
                viewNamespace: 'cases::cic',
                serviceLabel: 'APES CIC',
                priorities: ['low', 'medium', 'high', 'urgent'],
                statuses: ['open', 'in_progress', 'waiting_on_user', 'resolved', 'closed'],
                caseTypes: [],
                usesCategories: true,
                usesPetProfiles: false,
                supportsDelete: true,
                supportsAttachments: true,
            ),
            'shelter-rescue' => new self(
                moduleSlug: 'shelter-rescue',
                routeNamePrefix: 'shelter.cases',
                flavour: self::FLAVOUR_SHELTER,
                auditEventPrefix: 'shelter.case',
                viewNamespace: 'cases::shelter',
                serviceLabel: 'APES Shelter and Rescue',
                priorities: [],
                statuses: ['open', 'in_review', 'closed'],
                caseTypes: ['adoption', 'surrender', 'rescue', 'fostering'],
                usesCategories: false,
                usesPetProfiles: true,
                supportsDelete: false,
                supportsAttachments: true,
            ),
            default => throw new InvalidArgumentException(
                "Cases is not configured for module [{$moduleSlug}].",
            ),
        };
    }

    public function isShelter(): bool
    {
        return $this->flavour === self::FLAVOUR_SHELTER;
    }

    public function isCic(): bool
    {
        return $this->flavour === self::FLAVOUR_CIC;
    }

    public function permission(string $ability): string
    {
        return "{$this->moduleSlug}.cases.{$ability}";
    }

    public function permissionPrefix(): string
    {
        return "{$this->moduleSlug}.cases.";
    }

    public function indexRouteName(): string
    {
        return "{$this->routeNamePrefix}.index";
    }

    public function showRouteName(): string
    {
        return "{$this->routeNamePrefix}.show";
    }

    public function view(string $name): string
    {
        return "{$this->viewNamespace}.{$name}";
    }
}
