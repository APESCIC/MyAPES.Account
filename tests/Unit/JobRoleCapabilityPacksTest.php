<?php

namespace Tests\Unit;

use App\Contracts\ModuleRegistry;
use App\Services\AuthorizationProfile;
use App\Support\JobRoleCapabilityPacks;
use Tests\TestCase;

class JobRoleCapabilityPacksTest extends TestCase
{
    public function test_fixed_packs_expand_to_reviewed_permission_sets(): void
    {
        $modules = app(ModuleRegistry::class);
        $definitions = JobRoleCapabilityPacks::definitions($modules);

        $this->assertSame(
            ['admin.analytics.view'],
            $definitions['admin-overview']['permissions'],
        );
        $this->assertSame(
            ['admin.users.view'],
            $definitions['view-accounts']['permissions'],
        );
        $this->assertSame(
            ['admin.users.manage'],
            $definitions['manage-accounts']['permissions'],
        );

        foreach ($definitions['staff-module-work']['permissions'] as $permission) {
            $descriptor = $modules->permission($permission);
            $this->assertNotNull($descriptor);
            $this->assertTrue($descriptor->requiresDirectoryContext);
            $this->assertNotSame('delete', $descriptor->ability);
        }

        foreach ($definitions['module-delete']['permissions'] as $permission) {
            $descriptor = $modules->permission($permission);
            $this->assertNotNull($descriptor);
            $this->assertSame('delete', $descriptor->ability);
        }

        $this->assertSame(
            'on',
            JobRoleCapabilityPacks::state(
                'view-accounts',
                ['admin.users.view', 'admin.analytics.view'],
                $modules,
            ),
        );
        $this->assertSame(
            'off',
            JobRoleCapabilityPacks::state('view-accounts', [], $modules),
        );
        $this->assertSame(
            'indeterminate',
            JobRoleCapabilityPacks::state(
                'staff-module-work',
                [JobRoleCapabilityPacks::definitions($modules)['staff-module-work']['permissions'][0]],
                $modules,
            ),
        );
    }

    public function test_merge_applies_pack_on_and_off_changes(): void
    {
        $modules = app(ModuleRegistry::class);

        $merged = JobRoleCapabilityPacks::merge(
            ['admin.analytics.view', 'admin.users.view'],
            ['manage-accounts'],
            ['view-accounts'],
            $modules,
        );

        $this->assertSame(
            ['admin.analytics.view', 'admin.users.manage'],
            $merged,
        );
    }

    public function test_expand_never_returns_super_admin_only_permissions(): void
    {
        $modules = app(ModuleRegistry::class);
        $profile = app(AuthorizationProfile::class);
        $packKeys = array_keys(JobRoleCapabilityPacks::definitions($modules));

        foreach (JobRoleCapabilityPacks::expand($packKeys, $modules) as $permission) {
            $this->assertFalse(
                $profile->isAdministratorOnlyPermission($permission),
                "Pack expand must not include Super Admin-only permission [{$permission}].",
            );
        }
    }
}
