<?php

namespace Tests\Feature\Navigation;

use App\Core\Accounts\User;
use App\Support\Navigation\NavItem;
use App\Support\Navigation\NavPanel;
use App\Support\Navigation\WorkspaceMenu;
use App\Support\Navigation\WorkspaceNavigation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * Staff and admin help pages (#490).
 */
class StaffHelpPagesTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_staff_guide_has_stable_sections_for_tour_steps(): void
    {
        $staff = User::factory()->protectedRole('staff')->create();

        $response = $this->actingAs($staff)->get(route('help.staff'))->assertOk();

        foreach (['areas', 'dashboard', 'services', 'pets', 'callers', 'call-logs', 'editor', 'search', 'account', 'support'] as $section) {
            $response->assertSee('id="'.$section.'"', false);
        }

        $response
            ->assertSeeText('Verifying a caller')
            ->assertSeeText('deleted automatically after 24 months')
            ->assertDontSee('href="'.route('help.admin').'"', false);
    }

    public function test_administrators_get_the_admin_guide_from_the_admin_menu(): void
    {
        $admin = User::factory()->protectedRole('administrator')->create();

        $menu = $this->menuFor($admin, 'help.admin');
        $this->assertSame(NavPanel::ADMIN, $menu->currentPanelKey);
        $this->assertContains('help.admin', $this->itemKeys($menu->panels[NavPanel::ADMIN]));

        $response = $this->actingAs($admin)->get(route('help.admin'))->assertOk();
        $response
            ->assertSee('data-help-page="admin"', false)
            ->assertSeeText('Admin guide')
            ->assertSee('href="'.route('help.staff').'"', false);

        foreach (['overview', 'people', 'modules', 'lists', 'recruitment', 'emails', 'maintenance', 'security'] as $section) {
            $response->assertSee('id="'.$section.'"', false);
        }

        $this->actingAs($admin)
            ->get(route('help.staff'))
            ->assertOk()
            ->assertSee('href="'.route('help.admin').'"', false);
    }

    public function test_staff_without_admin_access_cannot_open_the_admin_guide(): void
    {
        $staff = User::factory()->protectedRole('staff')->create();
        $public = User::factory()->protectedRole('service-user')->create();

        $this->actingAs($staff)->get(route('help.admin'))->assertForbidden();
        $this->actingAs($public)->get(route('help.admin'))->assertForbidden();
    }

    public function test_guests_are_sent_to_sign_in(): void
    {
        $this->get(route('help.admin'))->assertRedirect(route('public.login'));
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
