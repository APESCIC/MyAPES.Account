<?php

namespace App\Services;

use App\Contracts\ModuleRegistry;
use App\Core\Accounts\User;
use Illuminate\Support\Facades\Schema;

class AuthorizationProfile
{
    public const ROLE_SERVICE_USER = 'service-user';

    public const ROLE_STAFF = 'staff';

    public const ROLE_ADMINISTRATOR = 'administrator';

    public const ROLE_SUPER_ADMIN = 'super-admin';

    public const ROLE_VOLUNTEER = 'volunteer';

    public const ROLE_STUDENT = 'student';

    public const PERMISSION_STAFF_ACCESS = 'staff.access';

    public const PERMISSION_ADMIN_ACCESS = 'admin.access';

    public const PERMISSION_SUPERADMIN_ACCESS = 'superadmin.access';

    public const PERMISSION_VOLUNTEER_ACCESS = 'volunteer.access';

    public const PERMISSION_STUDENT_ACCESS = 'student.access';

    private const BASE_PERMISSION_MATRIX = [
        self::ROLE_SERVICE_USER => [],
        self::ROLE_STUDENT => [
            self::PERMISSION_STUDENT_ACCESS,
        ],
        self::ROLE_VOLUNTEER => [
            self::PERMISSION_VOLUNTEER_ACCESS,
        ],
        self::ROLE_STAFF => [
            self::PERMISSION_STAFF_ACCESS,
        ],
        self::ROLE_ADMINISTRATOR => [
            self::PERMISSION_STAFF_ACCESS,
            self::PERMISSION_ADMIN_ACCESS,
            'admin.users.view',
            'admin.users.manage',
            'admin.analytics.view',
            'admin.emails.view',
        ],
        self::ROLE_SUPER_ADMIN => [
            self::PERMISSION_STAFF_ACCESS,
            self::PERMISSION_ADMIN_ACCESS,
            self::PERMISSION_SUPERADMIN_ACCESS,
            'admin.users.view',
            'admin.users.manage',
            'admin.groups.view',
            'admin.group-mappings.manage',
            'admin.roles.view',
            'admin.roles.manage',
            'admin.permissions.view',
            'admin.modules.view',
            'admin.modules.manage',
            'admin.analytics.view',
            'admin.maintenance.manage',
            'admin.emails.view',
            'admin.emails.manage',
        ],
    ];

    private const PROTECTED_ROLE_PRECEDENCE = [
        self::ROLE_SUPER_ADMIN,
        self::ROLE_ADMINISTRATOR,
        self::ROLE_STAFF,
        self::ROLE_VOLUNTEER,
        self::ROLE_STUDENT,
        self::ROLE_SERVICE_USER,
    ];

    private const SUPER_ADMIN_ONLY_PERMISSIONS = [
        self::PERMISSION_SUPERADMIN_ACCESS,
        'admin.groups.view',
        'admin.group-mappings.manage',
        'admin.roles.view',
        'admin.roles.manage',
        'admin.permissions.view',
        'admin.modules.view',
        'admin.modules.manage',
        'admin.maintenance.manage',
        'admin.emails.manage',
    ];

    private const LEGACY_TO_PROTECTED = [
        'service_user' => self::ROLE_SERVICE_USER,
        'student' => self::ROLE_STUDENT,
        'volunteer' => self::ROLE_VOLUNTEER,
        'staff' => self::ROLE_STAFF,
        'admin' => self::ROLE_ADMINISTRATOR,
        'superadmin' => self::ROLE_SUPER_ADMIN,
    ];

    /** @var array<string, array<int, string>>|null */
    private ?array $cachedPermissionMatrix = null;

    /** @var array<int, string>|null */
    private ?array $cachedPermissions = null;

    public function __construct(
        private readonly ModuleRegistry $modules,
    ) {}

    /**
     * @return array<string, array<int, string>>
     */
    public function permissionMatrix(): array
    {
        if ($this->cachedPermissionMatrix !== null) {
            return $this->cachedPermissionMatrix;
        }

        $matrix = self::BASE_PERMISSION_MATRIX;

        if (Schema::hasTable('module_plugins') || Schema::hasTable('module_installations')) {
            foreach ($this->modules->permissions() as $permission) {
                foreach ($permission->defaultRoles as $role) {
                    $matrix[$role][] = $permission->name;
                }
            }
        }

        foreach ($matrix as &$permissions) {
            $permissions = array_values(array_unique($permissions));
            sort($permissions);
        }
        unset($permissions);

        return $this->cachedPermissionMatrix = $matrix;
    }

    /**
     * @return array<int, string>
     */
    public function permissions(): array
    {
        if ($this->cachedPermissions !== null) {
            return $this->cachedPermissions;
        }

        $permissions = array_values(array_unique(array_merge(
            ...array_values($this->permissionMatrix()),
        )));
        sort($permissions);

        return $this->cachedPermissions = $permissions;
    }

    public function flushRuntimeCache(): void
    {
        $this->cachedPermissionMatrix = null;
        $this->cachedPermissions = null;
    }

    /**
     * @return array<int, string>
     */
    public function protectedRolesByPrecedence(): array
    {
        return self::PROTECTED_ROLE_PRECEDENCE;
    }

    public function isProtectedRole(string $roleName): bool
    {
        return array_key_exists($roleName, self::BASE_PERMISSION_MATRIX);
    }

    public function isDirectoryRestrictedPermission(string $permission): bool
    {
        $modulePermission = $this->modules->permission($permission);

        return $modulePermission?->requiresDirectoryContext
            ?? in_array($permission, $this->permissions(), true);
    }

    public function isApplicationPermission(string $permission): bool
    {
        return in_array($permission, $this->permissions(), true);
    }

    public function isSuperAdminOnlyPermission(string $permission): bool
    {
        return in_array(
            $permission,
            self::SUPER_ADMIN_ONLY_PERMISSIONS,
            true,
        );
    }

    public function effectiveProtectedRole(User $user): ?string
    {
        $names = $user->roles()
            ->where('guard_name', 'web')
            ->pluck('name')
            ->all();

        foreach (self::PROTECTED_ROLE_PRECEDENCE as $roleName) {
            if (in_array($roleName, $names, true)) {
                return $roleName;
            }
        }

        return null;
    }

    public function hasDirectoryProtectedEligibility(User $user): bool
    {
        return in_array(
            $this->effectiveProtectedRole($user),
            [
                self::ROLE_STUDENT,
                self::ROLE_VOLUNTEER,
                self::ROLE_STAFF,
                self::ROLE_ADMINISTRATOR,
                self::ROLE_SUPER_ADMIN,
            ],
            true,
        );
    }

    /**
     * @return array<int, string>
     */
    public function directoryProtectedRoles(): array
    {
        return [
            self::ROLE_STUDENT,
            self::ROLE_VOLUNTEER,
            self::ROLE_STAFF,
            self::ROLE_ADMINISTRATOR,
            self::ROLE_SUPER_ADMIN,
        ];
    }

    public function isSuperAdmin(User $user): bool
    {
        return $this->effectiveProtectedRole($user) === self::ROLE_SUPER_ADMIN;
    }

    public function superAdminRoleName(): string
    {
        return self::ROLE_SUPER_ADMIN;
    }

    public function protectedRoleForLegacy(string $legacyAccessLevel): ?string
    {
        return self::LEGACY_TO_PROTECTED[$legacyAccessLevel] ?? null;
    }

    public function legacyAccessLevelFor(string $protectedRole): string
    {
        $legacy = array_search($protectedRole, self::LEGACY_TO_PROTECTED, true);

        if (! is_string($legacy)) {
            throw new \InvalidArgumentException(
                "Unsupported protected authorization role [{$protectedRole}].",
            );
        }

        return $legacy;
    }

    /**
     * @return array<int, string>
     */
    public function qaSelectors(): array
    {
        return array_keys(self::LEGACY_TO_PROTECTED);
    }

    /**
     * @return array<int, string>
     */
    public function qaSwitchSelectors(): array
    {
        return ['service_user', 'student', 'volunteer', 'staff', 'admin', 'superadmin'];
    }

    public function matchesQaSelector(User $user, string $selector): bool
    {
        $expectedRole = $this->protectedRoleForLegacy($selector);

        return $expectedRole !== null
            && $this->effectiveProtectedRole($user) === $expectedRole;
    }

    public function displayKey(User $user): string
    {
        return $this->effectiveProtectedRole($user) ?? 'custom';
    }

    public function displayLabel(User $user): string
    {
        return match ($this->effectiveProtectedRole($user)) {
            self::ROLE_SERVICE_USER => 'Public',
            self::ROLE_STUDENT => 'Student',
            self::ROLE_VOLUNTEER => 'Volunteer',
            self::ROLE_STAFF => 'Staff',
            self::ROLE_ADMINISTRATOR => 'Admin',
            self::ROLE_SUPER_ADMIN => 'Super Admin',
            default => 'Custom',
        };
    }

    public function qaSelectorFor(User $user): ?string
    {
        $role = $this->effectiveProtectedRole($user);
        $selector = $role === null
            ? false
            : array_search($role, self::LEGACY_TO_PROTECTED, true);

        return is_string($selector) ? $selector : null;
    }
}
