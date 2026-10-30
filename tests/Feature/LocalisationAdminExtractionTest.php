<?php

namespace Tests\Feature;

use App\Support\PermissionDescriptions;
use Tests\TestCase;

class LocalisationAdminExtractionTest extends TestCase
{
    public function test_admin_nav_and_flash_keys_resolve(): void
    {
        $this->assertSame('Overview', __('admin.nav.overview'));
        $this->assertSame('Access', __('admin.nav.access'));
        $this->assertSame('Plugins', __('admin.nav.plugins'));
        $this->assertSame('Maintenance', __('admin.nav.maintenance'));
        $this->assertSame(
            'Directory synchronization requested.',
            __('admin.flash.directory_synchronization_requested'),
        );
        $this->assertStringNotContainsString('Super Admin', __('admin.nav.overview'));
        $this->assertStringNotContainsString('Super Admin', __('admin.nav.access'));
    }

    public function test_permission_display_labels_resolve_via_lang(): void
    {
        $this->assertSame(
            __('admin.permissions.labels.admin_access'),
            PermissionDescriptions::title('admin.access'),
        );
        $this->assertSame('Admin panel access', PermissionDescriptions::title('admin.access'));
    }

    public function test_plugin_permissions_use_the_manifest_ability_label(): void
    {
        $this->assertSame('Assign tickets', PermissionDescriptions::title('apes-cic.tickets.assign'));
        $this->assertSame('Student area access', PermissionDescriptions::title('student.access'));
    }

    public function test_plugin_permission_groups_use_module_and_plugin_names(): void
    {
        $this->assertSame('APES CIC · Cases', PermissionDescriptions::group('apes-cic.cases.assign'));
        $this->assertSame('APES Shelter and Rescue · Tickets', PermissionDescriptions::group('shelter-rescue.tickets.view-all'));
        $this->assertSame('Access', PermissionDescriptions::group('admin.access'));
    }

    public function test_admin_inventory_section_is_clear(): void
    {
        $path = base_path('docs/i18n-inventory.md');
        $this->assertFileExists($path);
        $contents = file_get_contents($path);
        $this->assertMatchesRegularExpression(
            '/\| \[Admin shell\].*\|\s*0\s*\|/',
            $contents,
        );
    }
}
