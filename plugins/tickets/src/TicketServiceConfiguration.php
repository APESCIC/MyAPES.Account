<?php

namespace Plugins\Tickets;

use App\Services\TicketServiceDefinition;
use InvalidArgumentException;

final class TicketServiceConfiguration
{
    /**
     * Built-in areas, used when no managed list is stored (#472).
     *
     * @var array<string, list<string>>
     */
    public const DEFAULT_SERVICE_AREAS = [
        'shelter-rescue' => ['adoption', 'surrender', 'rescue', 'fostering', 'animal_welfare', 'other'],
        'pet-care-clinic' => ['appointment', 'consultation', 'prescription', 'billing', 'follow_up', 'other'],
    ];

    public function __construct(
        private readonly TicketCategoryResolver $categories,
    ) {}

    public function for(string $subCoreKey): TicketServiceDefinition
    {
        return match ($subCoreKey) {
            'apes-cic' => new TicketServiceDefinition(
                'APES CIC',
                'apes-cic.tickets',
                'apes_cic.ticket',
                'apes-cic',
                'Organisational support tickets',
                'General organisational support for web, IT, HR, finance, operations and related needs.',
                $this->apesCicServiceAreaKeys(),
                true,
            ),
            'shelter-rescue' => new TicketServiceDefinition(
                'APES Shelter and Rescue',
                'shelter.tickets',
                'shelter.ticket',
                'apes-shelter',
                'APES Shelter and Rescue Tickets',
                'Support for adoption, surrender, rescue, fostering and animal welfare.',
                $this->serviceAreaKeys('shelter-rescue', self::DEFAULT_SERVICE_AREAS['shelter-rescue']),
                false,
            ),
            'pet-care-clinic' => new TicketServiceDefinition(
                'APES Pet Care Clinic',
                'petcare.tickets',
                'petcare.ticket',
                'apes-petcare',
                'APES Pet Care Clinic Tickets',
                'Support for appointments, consultations, prescriptions, billing and follow-up.',
                $this->serviceAreaKeys('pet-care-clinic', self::DEFAULT_SERVICE_AREAS['pet-care-clinic']),
                false,
            ),
            default => throw new InvalidArgumentException('Unknown ticket service.'),
        };
    }

    /** @return array<int, string> */
    private function apesCicServiceAreaKeys(): array
    {
        return $this->serviceAreaKeys('apes-cic', ['web_development', 'it_systems', 'other']);
    }

    /**
     * Active managed-list keys (#471 / #472), or the built-in list when none are stored.
     *
     * @param  array<int, string>  $fallback
     * @return array<int, string>
     */
    private function serviceAreaKeys(string $subCoreKey, array $fallback): array
    {
        $keys = $this->categories->serviceAreaKeys($subCoreKey);

        return $keys !== [] ? $keys : $fallback;
    }
}
