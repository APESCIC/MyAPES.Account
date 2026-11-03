<?php

namespace Tests\Feature\Navigation;

use App\Core\Accounts\User;
use App\Support\Navigation\NavItem;
use App\Support\Navigation\NavPanel;
use App\Support\Navigation\WorkspaceMenu;
use App\Support\Navigation\WorkspaceNavigation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Public, Staff and Admin areas (#464).
 */
class ThreeAreasTest extends TestCase
{
    use RefreshDatabase;

    public function test_logged_out_users_only_reach_public_pages(): void
    {
        foreach (['home', 'help', 'privacy', 'cookies', 'terms', 'change-log.index', 'public.login', 'public.register', 'staff.login'] as $route) {
            $this->get(route($route))->assertOk();
        }

        foreach (['dashboard', 'profile.edit', 'admin.index', 'help.staff'] as $route) {
            $this->get(route($route))->assertRedirect(route('public.login'));
        }
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function staffRoles(): array
    {
        return [
            'staff' => ['staff'],
            'volunteer' => ['volunteer'],
            'student' => ['student'],
            'administrator' => ['administrator'],
        ];
    }

    #[DataProvider('staffRoles')]
    public function test_staff_roles_land_on_the_staff_dashboard_and_get_the_staff_guide(string $role): void
    {
        $user = User::factory()->protectedRole($role)->create();

        $this->actingAs($user)->get(route('home'))->assertRedirect(route('dashboard'));

        $menu = $this->menuFor($user, 'dashboard');
        $this->assertSame(NavPanel::STAFF, $menu->currentPanelKey);
        $this->assertSame('Staff', $menu->currentPanel()?->label);
        $this->assertContains('help.staff', $this->itemKeys($menu->panels[NavPanel::STAFF]));

        $this->actingAs($user)
            ->get(route('help.staff'))
            ->assertOk()
            ->assertSee('data-help-page="staff"', false)
            ->assertSeeText('Staff guide')
            ->assertSee('href="'.route('help').'"', false);
    }

    public function test_public_accounts_keep_the_public_guide_only(): void
    {
        $user = User::factory()->protectedRole('service-user')->create();

        $menu = $this->menuFor($user, 'dashboard');
        $keys = $this->itemKeys($menu->panels[NavPanel::STAFF]);

        $this->assertContains('help', $keys);
        $this->assertNotContains('help.staff', $keys);
        $this->actingAs($user)->get(route('help.staff'))->assertForbidden();
        $this->actingAs($user)->get(route('help'))->assertOk()->assertDontSee('href="'.route('help.staff').'"', false);
    }

    public function test_the_public_guide_points_staff_to_the_staff_guide(): void
    {
        $staff = User::factory()->protectedRole('staff')->create();

        $this->actingAs($staff)
            ->get(route('help'))
            ->assertOk()
            ->assertSee('href="'.route('help.staff').'"', false);
    }

    public function test_only_administrators_can_switch_to_the_admin_area(): void
    {
        $admin = User::factory()->protectedRole('administrator')->create();
        $staff = User::factory()->protectedRole('staff')->create();

        $adminMenu = $this->menuFor($admin, 'dashboard');
        $this->assertTrue($adminMenu->showsPanelSwitch());
        $this->assertSame(route('admin.index'), $adminMenu->panels[NavPanel::ADMIN]->home()?->url);
        $this->actingAs($admin)->get(route('admin.index'))->assertOk();

        $this->assertFalse($this->menuFor($staff, 'dashboard')->showsPanelSwitch());
        $this->actingAs($staff)->get(route('admin.index'))->assertForbidden();
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
     * @return list<string>
     */
    private function itemKeys(NavPanel $panel): array
    {
        return array_map(static fn (NavItem $item): string => $item->key, $panel->items());
    }
}
