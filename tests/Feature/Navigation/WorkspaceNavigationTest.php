<?php

namespace Tests\Feature\Navigation;

use App\Core\Accounts\Permission;
use App\Core\Accounts\Role;
use App\Core\Accounts\RoleSource;
use App\Core\Accounts\User;
use App\Services\AuthorizationRoleMaterializer;
use App\Support\Navigation\NavGroup;
use App\Support\Navigation\NavItem;
use App\Support\Navigation\NavPanel;
use App\Support\Navigation\WorkspaceMenu;
use App\Support\Navigation\WorkspaceNavigation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class WorkspaceNavigationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, array{0: ?string, 1: list<string>, 2: string}>
     */
    public static function personas(): array
    {
        return [
            'guest' => [null, [NavPanel::PUBLIC], 'Public'],
            'service user' => ['service-user', [NavPanel::STAFF], 'Account'],
            'student' => ['student', [NavPanel::STAFF], 'Staff'],
            'volunteer' => ['volunteer', [NavPanel::STAFF], 'Staff'],
            'staff' => ['staff', [NavPanel::STAFF], 'Staff'],
            'administrator' => ['administrator', [NavPanel::STAFF, NavPanel::ADMIN], 'Staff'],
            'super admin' => ['super-admin', [NavPanel::STAFF, NavPanel::ADMIN], 'Staff'],
        ];
    }

    /**
     * @param  list<string>  $expectedPanels
     */
    #[DataProvider('personas')]
    public function test_each_persona_gets_only_the_panels_it_can_use(?string $persona, array $expectedPanels, string $firstPanelLabel): void
    {
        $user = $persona === null ? null : User::factory()->protectedRole($persona)->create();
        $menu = $this->menuFor($user, $user === null ? 'home' : 'dashboard');

        $this->assertSame($expectedPanels, array_keys($menu->panels));
        $this->assertSame($firstPanelLabel, $menu->panels[$expectedPanels[0]]->label);
        $this->assertSame(count($expectedPanels) === 2, $menu->showsPanelSwitch());

        foreach ($menu->panels as $panel) {
            foreach ($panel->groups as $group) {
                $this->assertNotSame([], $group->items, "{$panel->key} group {$group->key} should have been dropped.");
            }
        }
    }

    public function test_every_shown_staff_link_is_reachable_for_each_persona(): void
    {
        foreach (['service-user', 'student', 'volunteer', 'staff', 'administrator', 'super-admin'] as $persona) {
            $user = User::factory()->protectedRole($persona)->create();
            $menu = $this->menuFor($user, 'dashboard');

            foreach ($menu->panels as $panel) {
                foreach ($panel->items() as $item) {
                    $this->actingAs($user)->get($item->url)->assertOk();
                }
            }
        }
    }

    public function test_guest_public_panel_offers_sign_in_routes_and_no_staff_links(): void
    {
        $menu = $this->menuFor(null, 'home');
        $keys = $this->itemKeys($menu->panels[NavPanel::PUBLIC]);

        $this->assertContains('public.login', $keys);
        $this->assertContains('public.register', $keys);
        $this->assertContains('staff.login', $keys);
        $this->assertNotContains('dashboard', $keys);
        $this->assertSame([], $menu->quickActions);
    }

    public function test_administrator_admin_panel_hides_super_admin_only_groups(): void
    {
        $admin = User::factory()->protectedRole('administrator')->create();
        $superAdmin = User::factory()->protectedRole('super-admin')->create();

        $adminKeys = $this->itemKeys($this->menuFor($admin, 'admin.index')->panels[NavPanel::ADMIN]);
        $superKeys = $this->itemKeys($this->menuFor($superAdmin, 'admin.index')->panels[NavPanel::ADMIN]);

        $this->assertContains('admin.overview', $adminKeys);
        $this->assertContains('admin.users', $adminKeys);
        $this->assertNotContains('admin.access', $adminKeys);
        $this->assertNotContains('admin.plugins', $adminKeys);
        $this->assertNotContains('admin.maintenance', $adminKeys);

        foreach (['admin.overview', 'admin.users', 'admin.staff', 'admin.access', 'admin.modules', 'admin.plugins', 'admin.emails', 'admin.maintenance'] as $key) {
            $this->assertContains($key, $superKeys);
        }
    }

    public function test_admin_panel_home_is_the_first_openable_child_and_empty_groups_drop(): void
    {
        $actor = $this->staffWith('admin.users.view');
        $admin = $this->menuFor($actor, 'dashboard')->panels[NavPanel::ADMIN];

        $this->assertSame(['people'], array_map(static fn (NavGroup $group): string => $group->key, $admin->groups));
        $this->assertSame(route('admin.users.index', ['account_type' => 'public']), $admin->home()?->url);
    }

    /**
     * @return array<string, array{0: string, 1: array<string, string>, 2: string, 3: string}>
     */
    public static function activeRoutes(): array
    {
        return [
            'dashboard' => ['dashboard', [], NavPanel::STAFF, 'dashboard'],
            'profile' => ['profile.edit', [], NavPanel::STAFF, 'profile'],
            'module home' => ['shelter.index', [], NavPanel::STAFF, 'module.shelter-rescue.home'],
            'plugin beats module prefix' => ['apes-cic.tickets.index', [], NavPanel::STAFF, 'module.apes-cic.tickets'],
            'pet profiles' => ['petcare.pets.index', [], NavPanel::STAFF, 'module.pet-care-clinic.pet-profiles'],
            'admin overview' => ['admin.index', [], NavPanel::ADMIN, 'admin.overview'],
            'public users' => ['admin.users.index', ['account_type' => 'public'], NavPanel::ADMIN, 'admin.users'],
            'staff users' => ['admin.users.index', ['account_type' => 'staff'], NavPanel::ADMIN, 'admin.staff'],
            'access child page' => ['admin.roles.index', [], NavPanel::ADMIN, 'admin.access'],
            'plugin settings' => ['admin.modules.index', [], NavPanel::ADMIN, 'admin.plugins'],
            'help' => ['help', [], NavPanel::STAFF, 'help'],
        ];
    }

    /**
     * @param  array<string, string>  $query
     */
    #[DataProvider('activeRoutes')]
    public function test_the_current_route_resolves_exactly_one_active_item(string $routeName, array $query, string $panel, string $item): void
    {
        $superAdmin = User::factory()->protectedRole('super-admin')->create();
        $menu = $this->menuFor($superAdmin, $routeName, $query);

        $this->assertSame($panel, $menu->currentPanelKey);
        $this->assertSame($item, $menu->activeItemKey);
        $this->assertCount(1, array_filter($menu->currentPanel()?->items() ?? [], $menu->isActive(...)));
    }

    public function test_a_user_page_activates_the_list_for_that_account_type(): void
    {
        $superAdmin = User::factory()->protectedRole('super-admin')->create();
        $staffMember = User::factory()->protectedRole('staff')->create();
        $publicMember = User::factory()->protectedRole('service-user')->create();

        $this->assertSame('admin.staff', $this->menuFor($superAdmin, 'admin.users.show', ['user' => (string) $staffMember->id])->activeItemKey);
        $this->assertSame('admin.users', $this->menuFor($superAdmin, 'admin.users.show', ['user' => (string) $publicMember->id])->activeItemKey);
    }

    public function test_quick_actions_follow_create_permissions_and_panel_access(): void
    {
        foreach (['service-user', 'staff', 'administrator', 'super-admin'] as $persona) {
            $user = User::factory()->protectedRole($persona)->create();
            $menu = $this->menuFor($user, 'dashboard');
            $keys = array_map(static fn ($action): string => $action->key, $menu->quickActions);

            foreach ($menu->quickActions as $action) {
                if (str_starts_with($action->key, 'create.')) {
                    [, $subCore, $module] = explode('.', $action->key, 3);
                    $this->assertTrue($user->can("{$subCore}.{$module}.create"), "{$persona} should not see {$action->key}.");
                    $this->actingAs($user)->get(strtok($action->url, '#'))->assertOk();
                }
            }

            $this->assertContains('view-site', $keys);
            $this->assertContains('account', $keys);
            $this->assertSame($menu->showsPanelSwitch(), in_array('switch.admin', $keys, true), $persona);
            $this->assertNotContains('switch.staff', $keys, 'The current panel is never offered as a switch.');
        }
    }

    public function test_navigation_permissions_live_in_the_builder_not_in_blade(): void
    {
        foreach (['layouts/app.blade.php', 'components/workspace/nav.blade.php', 'components/workspace/rail.blade.php', 'components/workspace/panel-switch.blade.php'] as $view) {
            $source = (string) file_get_contents(resource_path('views/'.$view));

            foreach (['@can', '@canany', 'Gate::', '->can(', 'homeUrlFor', 'AdminPrimaryNav'] as $forbidden) {
                $this->assertStringNotContainsString($forbidden, $source, "{$view} computes navigation permissions.");
            }
        }
    }

    /**
     * @param  array<string, string>  $parameters
     */
    private function menuFor(?User $user, string $routeName, array $parameters = []): WorkspaceMenu
    {
        $request = Request::create(route($routeName, $parameters));
        $request->setLaravelSession(app('session.store'));
        $route = app('router')->getRoutes()->match($request);
        $route->bind($request);
        $request->setRouteResolver(static fn () => $route);

        foreach ($route->parameters() as $name => $value) {
            if ($name === 'user' && is_string($value)) {
                $route->setParameter($name, User::query()->findOrFail($value));
            }
        }

        $this->app->instance('request', $request);

        if ($user !== null) {
            $this->actingAs($user);
        }

        return app(WorkspaceNavigation::class)->forRequest($user, $request);
    }

    /**
     * @return list<string>
     */
    private function itemKeys(NavPanel $panel): array
    {
        return array_map(static fn (NavItem $item): string => $item->key, $panel->items());
    }

    private function staffWith(string $permissionName): User
    {
        $actor = User::factory()->protectedRole('staff')->create();
        $role = Role::query()->create(['name' => 'workspace-'.$permissionName, 'guard_name' => 'web']);
        $role->permissions()->attach(
            Permission::query()->where('name', $permissionName)->where('guard_name', 'web')->firstOrFail()->id,
        );
        app(AuthorizationRoleMaterializer::class)->grant($actor, $role, RoleSource::SOURCE_LOCAL, actor: $actor);

        return $actor;
    }
}
