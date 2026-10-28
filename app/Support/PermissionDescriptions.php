<?php

namespace App\Support;

use App\Core\Access\PermissionNaming;

/**
 * Human-readable titles and descriptions for code-owned permission keys.
 *
 * Catalogue `group` stays for finer labels (Accounts, Directory, …). Structure
 * layer (Core / Module / Plugin) comes from {@see PermissionNaming} (#292).
 */
final class PermissionDescriptions
{
    /** @var array<string, array{title: string, description: string, group: string}> */
    private const CATALOGUE = [
        'staff.access' => [
            'title' => 'Staff area access',
            'description' => 'Open the staff workspace and staff-only navigation.',
            'group' => 'Access',
        ],
        'admin.access' => [
            'title' => 'Admin panel access',
            'description' => 'Open the day-to-day Admin panel for account operations.',
            'group' => 'Access',
        ],
        'superadmin.access' => [
            'title' => 'Super Admin panel access',
            'description' => 'Open the Super Admin panel for technical configuration and directory controls.',
            'group' => 'Access',
        ],
        'admin.users.view' => [
            'title' => 'View accounts',
            'description' => 'Browse public and staff accounts and open account detail pages.',
            'group' => 'Accounts',
        ],
        'admin.users.manage' => [
            'title' => 'Manage accounts',
            'description' => 'Update profiles, assign custom roles, and suspend or reactivate accounts.',
            'group' => 'Accounts',
        ],
        'admin.analytics.view' => [
            'title' => 'View admin overview',
            'description' => 'See operational KPIs on the Admin overview.',
            'group' => 'Accounts',
        ],
        'admin.groups.view' => [
            'title' => 'View directory groups',
            'description' => 'Browse Cloudron directory groups and their role mappings.',
            'group' => 'Directory',
        ],
        'admin.group-mappings.manage' => [
            'title' => 'Manage group mappings',
            'description' => 'Map Cloudron directory groups to roles and queue directory synchronization.',
            'group' => 'Directory',
        ],
        'admin.roles.view' => [
            'title' => 'View roles',
            'description' => 'Browse protected and custom roles and their permissions.',
            'group' => 'Authorization',
        ],
        'admin.roles.manage' => [
            'title' => 'Manage custom roles',
            'description' => 'Create, update, and delete custom roles and their permission sets.',
            'group' => 'Authorization',
        ],
        'admin.permissions.view' => [
            'title' => 'View permissions',
            'description' => 'Browse the code-owned permission catalogue and which roles hold each permission.',
            'group' => 'Authorization',
        ],
        'admin.modules.view' => [
            'title' => 'View plugins',
            'description' => 'Review first-party plugin registry status across Services.',
            'group' => 'Platform',
        ],
        'admin.modules.manage' => [
            'title' => 'Manage plugins',
            'description' => 'Install, enable, or disable first-party plugins and their navigation.',
            'group' => 'Platform',
        ],
        'admin.maintenance.manage' => [
            'title' => 'Manage maintenance mode',
            'description' => 'Activate or deactivate application maintenance mode.',
            'group' => 'Platform',
        ],
        'admin.emails.view' => [
            'title' => 'View email templates',
            'description' => 'Browse registered email templates and preview them with sample data.',
            'group' => 'Email',
        ],
        'admin.emails.manage' => [
            'title' => 'Manage email templates',
            'description' => 'Send test emails, resend verification safely, and edit subject and intro wording.',
            'group' => 'Email',
        ],
    ];

    public static function title(string $permission): string
    {
        $key = 'admin.permissions.labels.'.str_replace('.', '_', $permission);
        $translated = __($key);

        if ($translated !== $key) {
            return $translated;
        }

        return self::CATALOGUE[$permission]['title']
            ?? self::headlineFromKey($permission);
    }

    public static function description(string $permission): string
    {
        return self::CATALOGUE[$permission]['description']
            ?? 'Application permission: '.$permission;
    }

    public static function group(string $permission): string
    {
        if (isset(self::CATALOGUE[$permission])) {
            return self::CATALOGUE[$permission]['group'];
        }

        if (str_contains($permission, '.')) {
            $parts = explode('.', $permission);

            return self::headlineFromKey($parts[0].'.'.$parts[1]);
        }

        return 'Other';
    }

    public static function layer(string $permission): string
    {
        return PermissionNaming::layer($permission);
    }

    /**
     * @return array<int, string>
     */
    public static function matchingKeys(string $needle): array
    {
        $needle = strtolower(trim($needle));
        if ($needle === '') {
            return [];
        }

        $matches = [];
        foreach (self::CATALOGUE as $key => $meta) {
            $haystack = strtolower(
                $key.' '.$meta['title'].' '.$meta['description'].' '.$meta['group'].' '.self::layer($key),
            );
            if (str_contains($haystack, $needle)) {
                $matches[] = $key;
            }
        }

        return $matches;
    }

    /**
     * Catalogue subgroup labels (Accounts, Directory, plugin pair headlines).
     *
     * @return array<int, string>
     */
    public static function groupsFor(iterable $permissionNames): array
    {
        $groups = [];
        foreach ($permissionNames as $name) {
            $groups[] = self::group((string) $name);
        }

        $groups = array_values(array_unique($groups));
        sort($groups);

        return $groups;
    }

    /**
     * Structure layers for Access UI filters (#292).
     *
     * @return array<int, string>
     */
    public static function layersFor(iterable $permissionNames): array
    {
        return PermissionNaming::layersFor($permissionNames);
    }

    /**
     * @return array{title: string, description: string, group: string, layer: string}
     */
    public static function meta(string $permission): array
    {
        return [
            'title' => self::title($permission),
            'description' => self::description($permission),
            'group' => self::group($permission),
            'layer' => self::layer($permission),
        ];
    }

    private static function headlineFromKey(string $permission): string
    {
        return str($permission)
            ->replace('.', ' ')
            ->replace('-', ' ')
            ->headline()
            ->toString();
    }
}
