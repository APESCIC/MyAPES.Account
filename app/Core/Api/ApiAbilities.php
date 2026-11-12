<?php

namespace App\Core\Api;

/**
 * What an API client may read (#638, #640). Every /api/v1 endpoint needs one
 * of these on the token; nothing here allows a write.
 */
final class ApiAbilities
{
    public const STAFF_READ = 'staff:read';

    public const GROUPS_READ = 'groups:read';

    public const USERS_READ = 'users:read';

    public const CONSENT_READ = 'consent:read';

    public const TICKETS_READ = 'tickets:read';

    public const PETS_READ = 'pets:read';

    public const CASES_READ = 'cases:read';

    public const ORGANISATIONS = ['apes-cic', 'shelter-rescue', 'pet-care-clinic'];

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return [
            self::STAFF_READ,
            self::GROUPS_READ,
            self::USERS_READ,
            self::CONSENT_READ,
            self::TICKETS_READ,
            self::PETS_READ,
            self::CASES_READ,
        ];
    }

    /**
     * The approved starting set for each first app (5 Nov 2026): APES Newsroom
     * reads APES CIC staff, groups, people, mailing-list consent and tickets;
     * EASM reads Shelter and Rescue people, pets, tickets and cases.
     *
     * @return array<string, array{name: string, organisation: string, abilities: list<string>}>
     */
    public static function presets(): array
    {
        return [
            'newsroom' => [
                'name' => 'APES Newsroom',
                'organisation' => 'apes-cic',
                'abilities' => [self::STAFF_READ, self::GROUPS_READ, self::USERS_READ, self::CONSENT_READ, self::TICKETS_READ],
            ],
            'easm' => [
                'name' => 'EASM',
                'organisation' => 'shelter-rescue',
                'abilities' => [self::USERS_READ, self::PETS_READ, self::TICKETS_READ, self::CASES_READ],
            ],
        ];
    }
}
