<?php

namespace Tests\Unit\Navigation;

use App\Contracts\ModuleNavigationProvider;
use App\Core\Accounts\User;
use App\Core\Extensions\Plugins\PluginMenuAction;
use App\Support\Navigation\ModuleMegaMenuBuilder;
use App\Support\Navigation\WorkspaceNavigation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Module mega menu builder (#885): three modules, plugin groups, Overview home.
 */
class ModuleMegaMenuBuilderTest extends TestCase
{
    use RefreshDatabase;

    public function test_three_modules_build_three_top_items_with_plugin_groups_and_overview(): void
    {
        $admin = User::factory()->protectedRole('administrator')->create();
        $this->actingAs($admin);

        $menu = app(WorkspaceNavigation::class)->forRequest($admin, request());

        $keys = array_map(static fn ($item) => $item->key, $menu->topItems);
        $this->assertSame(
            ['module.apes-cic', 'module.shelter-rescue', 'module.pet-care-clinic'],
            $keys,
            'Modules appear in sortOrder as top items',
        );

        $labels = array_map(static fn ($item) => $item->label, $menu->topItems);
        $this->assertSame(
            ['APES CIC', 'APES Shelter and Rescue', 'APES Pet Care Clinic'],
            $labels,
        );

        foreach ($menu->topItems as $top) {
            $this->assertNotEmpty($top->groups);
            $this->assertSame('overview', $top->groups[0]->key);
            $this->assertSame('Overview', $top->groups[0]->items[0]->label);
            $this->assertSame($top->url, $top->groups[0]->items[0]->url);

            $pluginKeys = array_map(
                static fn ($group) => $group->key,
                array_slice($top->groups, 1),
            );
            $this->assertNotEmpty($pluginKeys, $top->label.' should list plugin columns');
            $this->assertSame($pluginKeys, array_values(array_unique($pluginKeys)), 'No duplicate plugin columns');
        }

        $cicPluginKeys = array_map(
            static fn ($group) => $group->key,
            array_slice($menu->topItems[0]->groups, 1),
        );
        $this->assertContains('plugin.tickets', $cicPluginKeys);
    }

    public function test_builder_exposes_no_duplicate_action_urls_per_module(): void
    {
        $admin = User::factory()->protectedRole('administrator')->create();
        $builder = app(ModuleMegaMenuBuilder::class);
        $modules = app(ModuleNavigationProvider::class)->forUser($admin);

        $tops = $builder->forAudience($admin, $modules, PluginMenuAction::AUDIENCE_STAFF);

        foreach ($tops as $top) {
            $urls = [];
            foreach ($top->items() as $item) {
                $this->assertArrayNotHasKey($item->url, $urls, "Duplicate action URL {$item->url} in {$top->label}");
                $urls[$item->url] = $item->key;
            }
        }
    }
}
