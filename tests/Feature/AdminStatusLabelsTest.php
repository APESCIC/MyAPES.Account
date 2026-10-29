<?php

namespace Tests\Feature;

use App\Modules\ModuleCodeStatus;
use Tests\TestCase;

class AdminStatusLabelsTest extends TestCase
{
    public function test_module_code_status_labels_resolve_from_lang(): void
    {
        $this->assertSame('Shipped', ModuleCodeStatus::Shipped->label());
        $this->assertSame('Code Not Shipped', ModuleCodeStatus::CodeNotShipped->label());
        $this->assertSame('Incompatible', ModuleCodeStatus::Incompatible->label());

        foreach (ModuleCodeStatus::cases() as $status) {
            $this->assertTrue(trans()->has("admin.plugins.code_status.{$status->value}"), $status->value);
        }
    }

    public function test_admin_overview_alert_matches_the_module_code_status_label(): void
    {
        $this->assertSame(ModuleCodeStatus::CodeNotShipped->label(), __('admin.overview.alert.code_not_shipped'));
        $this->assertSame(ModuleCodeStatus::Incompatible->label(), __('admin.overview.alert.incompatible'));
    }

    public function test_module_actions_and_states_resolve_from_lang(): void
    {
        foreach (['install' => 'Install', 'enable' => 'Enable', 'disable' => 'Disable'] as $action => $label) {
            $this->assertSame($label, __("admin.plugins.actions.{$action}"));
        }

        $this->assertSame('Disabled', __('admin.plugins.disabled'));
        $this->assertSame('Available', __('admin.plugins.available'));
        $this->assertSame('Present', __('admin.access.present'));
        $this->assertSame('Missing', __('admin.access.missing'));
    }

    public function test_admin_status_chrome_is_not_hard_coded_in_views(): void
    {
        $views = [
            'admin/modules/index.blade.php',
            'admin/organisation-modules/index.blade.php',
            'admin/users/index.blade.php',
            'admin/users/show.blade.php',
            'admin/groups/index.blade.php',
            'admin/groups/show.blade.php',
            'admin/access/groups.blade.php',
        ];

        foreach ($views as $view) {
            $source = file_get_contents(resource_path("views/{$view}"));

            foreach (["'Active'", "'Suspended'", "'Enabled'", "'Disabled'", "'Available'", 'ucfirst($group->status)', 'str($action)->title()'] as $hardCoded) {
                $this->assertStringNotContainsString($hardCoded, $source, "{$view} hard-codes {$hardCoded}.");
            }
        }
    }
}
