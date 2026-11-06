<?php

namespace App\Support;

/**
 * Seeded organisational job roles. These are custom (non-protected) roles.
 */
final class DefaultJobRoles
{
    public const BOARD_OF_DIRECTORS = 'board-of-directors';

    public const MANAGEMENT = 'management';

    public const CLIENT_SERVICES_ADVISOR = 'client-services-advisor';

    public const RECEPTIONIST = 'receptionist';

    public const HUMAN_RESOURCES = 'human-resources';

    public const VET = 'vet';

    public const VET_NURSE = 'vet-nurse';

    /**
     * Consultation abilities shared by Vet and Vet Nurse (#534, #535).
     *
     * @var array<int, string>
     */
    private const CLINICAL_PERMISSIONS = [
        'pet-care-clinic.consultations.create',
        'pet-care-clinic.consultations.update-all',
        'pet-care-clinic.consultations.view-all',
    ];

    /**
     * @return array<string, array{title: string, permissions: array<int, string>, directory_group?: string}>
     */
    public static function catalogue(): array
    {
        return [
            self::BOARD_OF_DIRECTORS => [
                'title' => 'Board Of Directors',
                'permissions' => [
                    'admin.analytics.view',
                ],
            ],
            self::MANAGEMENT => [
                'title' => 'Management',
                'permissions' => [
                    'admin.analytics.view',
                    'admin.users.view',
                ],
            ],
            self::CLIENT_SERVICES_ADVISOR => [
                'title' => 'Client Services Advisor',
                'permissions' => [
                    'admin.users.view',
                ],
            ],
            self::RECEPTIONIST => [
                'title' => 'Receptionist',
                'permissions' => [
                    'admin.users.view',
                ],
            ],
            self::HUMAN_RESOURCES => [
                'title' => 'Human Resources',
                'permissions' => [
                    'apes-cic.recruitment.create',
                    'apes-cic.recruitment.delete',
                    'apes-cic.recruitment.review-applications',
                    'apes-cic.recruitment.update',
                    'apes-cic.recruitment.view-all',
                ],
            ],
            self::VET => [
                'title' => 'Vet',
                'permissions' => self::CLINICAL_PERMISSIONS,
                'directory_group' => 'myapesaccount.vet',
            ],
            self::VET_NURSE => [
                'title' => 'Vet Nurse',
                'permissions' => self::CLINICAL_PERMISSIONS,
                'directory_group' => 'myapesaccount.vetnurse',
            ],
        ];
    }

    /**
     * Job roles that create and edit consultations.
     *
     * @return array<int, string>
     */
    public static function clinicalRoles(): array
    {
        return [
            self::VET,
            self::VET_NURSE,
        ];
    }

    /**
     * Managed Cloudron group seeded as a mutable mapping when the role is first created.
     */
    public static function directoryGroup(string $name): ?string
    {
        return self::catalogue()[$name]['directory_group'] ?? null;
    }

    /**
     * @return array<int, string>
     */
    public static function names(): array
    {
        return array_keys(self::catalogue());
    }

    public static function isDefault(string $name): bool
    {
        return array_key_exists($name, self::catalogue());
    }

    public static function title(string $name): string
    {
        return self::catalogue()[$name]['title']
            ?? str($name)->replace('-', ' ')->headline()->toString();
    }

    /**
     * @return array<int, string>
     */
    public static function defaultPermissions(string $name): array
    {
        $permissions = self::catalogue()[$name]['permissions'] ?? [];
        sort($permissions);

        return array_values($permissions);
    }
}
