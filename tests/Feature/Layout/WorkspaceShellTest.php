<?php

namespace Tests\Feature\Layout;

use App\Core\Accounts\User;
use App\Support\Navigation\WorkspaceMenu;
use App\Support\Navigation\WorkspaceNavigation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class WorkspaceShellTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function staffAndAdminPages(): array
    {
        return [
            'dashboard' => ['super-admin', '/dashboard'],
            'shelter' => ['super-admin', '/shelter'],
            'petcare' => ['super-admin', '/petcare'],
            'apes cic' => ['super-admin', '/apes-cic'],
            'plugin list' => ['super-admin', '/apes-cic/tickets'],
            'admin' => ['super-admin', '/admin'],
            'admin users' => ['super-admin', '/admin/users?account_type=staff'],
            'admin maintenance' => ['super-admin', '/admin/maintenance'],
            'staff dashboard' => ['staff', '/dashboard'],
        ];
    }

    #[DataProvider('staffAndAdminPages')]
    public function test_pages_render_the_rail_with_exactly_one_current_link(string $persona, string $path): void
    {
        $html = $this->actingAs(User::factory()->protectedRole($persona)->create())->get($path)->assertOk()->getContent();

        $this->assertStringContainsString('data-workspace-rail', $html);
        $this->assertStringContainsString('aria-label="'.__('workspace.header.breadcrumb').'"', $html);
        $this->assertSame(1, preg_match_all('/class="sg-nav-link"\s+data-nav-key="[^"]+"\s+aria-current="page"/', $html));
        $this->assertStringNotContainsString('site-sidebar', $html);
        $this->assertStringNotContainsString('primary-nav', $html);
        $this->assertStringNotContainsString('data-sidebar', $html);
    }

    public function test_panel_switch_is_hidden_for_staff_only_users_and_shown_for_super_admin(): void
    {
        $this->actingAs(User::factory()->protectedRole('staff')->create())
            ->get('/dashboard')
            ->assertOk()
            ->assertDontSee('data-workspace-panel-switch', false);

        $this->actingAs(User::factory()->protectedRole('super-admin')->create())
            ->get('/admin')
            ->assertOk()
            ->assertSee('data-workspace-panel-switch', false)
            ->assertSee('aria-current="true" >'.__('workspace.panels.admin').'</a>', false);
    }

    public function test_user_dock_shows_name_role_account_and_sign_out(): void
    {
        $user = User::factory()->protectedRole('administrator')->create(['name' => 'Ada Lovelace']);

        $this->actingAs($user)
            ->get('/dashboard')
            ->assertOk()
            ->assertSee('data-workspace-dock', false)
            ->assertSee('>AL</span>', false)
            ->assertSeeText('Ada Lovelace')
            ->assertSeeText('Admin')
            ->assertSee('href="'.route('profile.edit').'"', false)
            ->assertSee('action="'.route('auth.logout').'"', false)
            ->assertSeeText(__('workspace.dock.sign_out'));
    }

    public function test_guests_get_the_public_panel_and_sign_in_dock(): void
    {
        $this->get(route('home'))
            ->assertOk()
            ->assertSeeText(__('workspace.panel_subtitle', ['panel' => __('workspace.panels.public')]))
            ->assertSeeText(__('workspace.dock.log_in'))
            ->assertDontSee('data-workspace-panel-switch', false)
            ->assertDontSee(__('workspace.dock.sign_out'));
    }

    public function test_auth_pages_centre_the_card_under_the_horizontal_logo(): void
    {
        foreach (['public.login', 'public.register', 'staff.login', 'password.request'] as $routeName) {
            $this->get(route($routeName))
                ->assertOk()
                ->assertSee('is-auth-page', false)
                ->assertSee('logos/myapes-header-light-600x128.png', false);
        }

        $this->get(route('home'))->assertOk()->assertDontSee('is-auth-page', false);
    }

    /**
     * @return array<string, array{0: ?string, 1: string}>
     */
    public static function paletteViewers(): array
    {
        return [
            'guest' => [null, 'home'],
            'service user' => ['service-user', 'dashboard'],
            'staff' => ['staff', 'dashboard'],
            'administrator' => ['administrator', 'admin.index'],
            'super admin' => ['super-admin', 'admin.index'],
        ];
    }

    #[DataProvider('paletteViewers')]
    public function test_palette_json_matches_the_builder_for_each_role(?string $persona, string $routeName): void
    {
        $user = $persona === null ? null : User::factory()->protectedRole($persona)->create();
        $response = ($user === null ? $this : $this->actingAs($user))->get(route($routeName))->assertOk();

        $request = Request::create(route($routeName));
        $request->setLaravelSession(app('session.store'));
        $route = app('router')->getRoutes()->match($request);
        $route->bind($request);
        $request->setRouteResolver(static fn () => $route);
        $this->app->instance('request', $request);
        if ($user !== null) {
            $this->actingAs($user);
        }
        $expected = app(WorkspaceNavigation::class)->forRequest($user, $request)->paletteCommands();

        $this->assertSame($expected, $this->paletteCommands($response));
        $this->assertNotSame([], $expected);

        foreach ($expected as $command) {
            $response->assertSee('<a href="'.e($command['url']).'" data-command-id="'.e($command['id']).'"', false);
        }
    }

    public function test_palette_trigger_and_dialog_are_accessible(): void
    {
        $this->actingAs(User::factory()->protectedRole('staff')->create())
            ->get('/dashboard')
            ->assertOk()
            ->assertSee('aria-keyshortcuts="Control+K Meta+K"', false)
            ->assertSee('role="combobox"', false)
            ->assertSee('aria-controls="workspace-palette-list"', false)
            ->assertSee('role="listbox"', false)
            ->assertSee('aria-modal="true"', false)
            ->assertSee('aria-controls="workspace-drawer"', false)
            ->assertSee('<a class="skip-link" href="#main-content">', false)
            ->assertSee('<main id="main-content" class="app-main" tabindex="-1">', false);
    }

    public function test_page_title_section_adds_a_heading_and_crumb(): void
    {
        $view = $this->blade(
            '<x-workspace.page-header :menu="$menu" title="Ticket #42" subtitle="Opened today" />',
            ['menu' => new WorkspaceMenu([], 'staff', null)],
        );

        $view->assertSee('<h1 class="sg-page-header__title">Ticket #42</h1>', false)
            ->assertSee('Opened today');
    }

    /**
     * @return list<array<string, string>>
     */
    private function paletteCommands(TestResponse $response): array
    {
        $this->assertSame(1, preg_match('/<script type="application\/json" data-workspace-palette-index>(.*?)<\/script>/s', $response->getContent(), $matches));

        return json_decode($matches[1], true, flags: JSON_THROW_ON_ERROR);
    }
}
