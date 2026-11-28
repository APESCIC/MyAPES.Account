<?php

namespace Tests\Feature\Navigation;

use App\Core\Accounts\User;
use App\Core\Extensions\Models\ModuleInstallation;
use App\Support\Navigation\NavItem;
use App\Support\Navigation\WorkspaceMenu;
use App\Support\Navigation\WorkspaceNavigation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CreatesClinicalPersonas;
use Tests\TestCase;

/**
 * Top menu permission and enablement filtering (#893).
 */
class TopMenuFilteringTest extends TestCase
{
    use CreatesClinicalPersonas;
    use RefreshDatabase;

    /**
     * @return array<string, array{0: string}>
     */
    public static function personas(): array
    {
        return [
            'service user' => ['service-user'],
            'student' => ['student'],
            'volunteer' => ['volunteer'],
            'staff' => ['staff'],
            'administrator' => ['administrator'],
            'vet' => ['vet'],
        ];
    }

    #[DataProvider('personas')]
    public function test_top_menu_links_return_ok_for_each_persona(string $persona): void
    {
        $user = $persona === 'vet'
            ? $this->vetStaff()
            : User::factory()->protectedRole($persona)->create();

        $menu = $this->menuFor($user, 'dashboard');
        foreach ($this->topItems($menu) as $item) {
            $url = strtok($item->url, '#');
            $response = $this->actingAs($user)->get($url);
            $this->assertFalse($response->isForbidden(), "{$persona} hit 403 on {$item->key}");
            $this->assertFalse($response->isNotFound(), "{$persona} hit 404 on {$item->key}");
            $this->assertTrue($response->isSuccessful(), "{$persona} failed on {$item->key}");
        }
    }

    public function test_staff_top_menu_excludes_bookings(): void
    {
        $staff = User::factory()->protectedRole('staff')->create();
        $labels = collect($this->topItems($this->menuFor($staff, 'dashboard')))
            ->map(static fn (NavItem $item): string => $item->label)
            ->all();

        foreach ($labels as $label) {
            $this->assertStringNotContainsStringIgnoringCase('booking', $label);
        }
    }

    public function test_disabled_plugin_drops_actions_from_top_menu(): void
    {
        $admin = User::factory()->protectedRole('administrator')->create();

        ModuleInstallation::query()
            ->where('sub_core_key', 'pet-care-clinic')
            ->where('module_key', 'consultations')
            ->update(['enabled' => false]);

        request()->attributes->remove('myapes.modules.enabled-instance-keys');

        $menu = $this->menuFor($admin, 'petcare.index');
        $pluginKeys = [];
        foreach ($menu->topItems as $top) {
            if ($top->key !== 'module.pet-care-clinic') {
                continue;
            }
            foreach ($top->groups as $group) {
                if (str_starts_with($group->key, 'plugin.')) {
                    $pluginKeys[] = $group->key;
                }
            }
        }

        $this->assertNotContains('plugin.consultations', $pluginKeys);
    }

    public function test_staff_sees_clients_mega_after_modules_without_admin_mega(): void
    {
        $admin = User::factory()->protectedRole('administrator')->create();
        $menu = $this->menuFor($admin, 'dashboard');
        $keys = array_map(static fn ($top) => $top->key, $menu->topItems);

        $this->assertContains('module.apes-cic', $keys);
        $this->assertContains('staff.clients', $keys);
        $this->assertNotContains('staff.admin', $keys);
        $this->assertTrue(array_search('staff.clients', $keys, true) > array_search('module.pet-care-clinic', $keys, true));

        $userKeys = array_map(static fn (NavItem $item): string => $item->key, $menu->userMenuItems);
        $this->assertContains('user.admin', $userKeys);
    }

    public function test_non_admin_staff_never_sees_admin_top_or_user_item(): void
    {
        $staff = User::factory()->protectedRole('staff')->create();
        $menu = $this->menuFor($staff, 'dashboard');
        $keys = array_map(static fn ($top) => $top->key, $menu->topItems);
        $userKeys = array_map(static fn (NavItem $item): string => $item->key, $menu->userMenuItems);

        $this->assertNotContains('staff.admin', $keys);
        $this->assertNotContains('user.admin', $userKeys);
    }

    public function test_admin_panel_uses_section_top_items(): void
    {
        $admin = User::factory()->protectedRole('administrator')->create();
        $keys = array_map(static fn ($top) => $top->key, $this->menuFor($admin, 'admin.index')->topItems);

        $this->assertSame(
            ['admin.overview', 'admin.people', 'admin.security', 'admin.modules', 'admin.communication', 'admin.system'],
            $keys,
        );
    }

    public function test_public_account_mega_menu_lists_own_record_actions(): void
    {
        $user = User::factory()->protectedRole('service-user')->create();
        $cic = collect($this->menuFor($user, 'dashboard')->topItems)
            ->first(static fn ($top) => $top->key === 'module.apes-cic');
        $this->assertNotNull($cic);
        $labels = collect($cic->items())->map(static fn (NavItem $item): string => $item->label)->all();

        $this->assertContains('Open a ticket', $labels);
        $this->assertContains('View my tickets', $labels);
        $this->assertContains('Book an appointment', $labels);
    }

    private function menuFor(User $user, string $routeName): WorkspaceMenu
    {
        $request = Request::create(route($routeName));
        $request->setLaravelSession(app('session.store'));
        $route = app('router')->getRoutes()->match($request);
        $route->bind($request);
        $request->setRouteResolver(static fn () => $route);
        $this->app->instance('request', $request);
        $this->actingAs($user);

        return app(WorkspaceNavigation::class)->forRequest($user, $request);
    }

    /**
     * @return list<NavItem>
     */
    private function topItems(WorkspaceMenu $menu): array
    {
        $items = [];
        foreach ($menu->topItems as $top) {
            foreach ($top->items() as $item) {
                $items[] = $item;
            }
        }

        return $items;
    }
}