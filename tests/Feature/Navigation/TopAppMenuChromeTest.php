<?php

namespace Tests\Feature\Navigation;

use App\Core\Accounts\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Top app bar, mega menus and user menu rendering (#896).
 */
class TopAppMenuChromeTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, array{0: ?string, 1: string}>
     */
    public static function signedInPersonas(): array
    {
        return [
            'service user' => ['service-user', 'dashboard'],
            'staff' => ['staff', 'dashboard'],
            'administrator' => ['administrator', 'dashboard'],
        ];
    }

    #[DataProvider('signedInPersonas')]
    public function test_signed_in_pages_use_the_app_bar_mega_menus_and_user_menu(string $persona, string $route): void
    {
        $user = User::factory()->protectedRole($persona)->create();

        $response = $this->actingAs($user)->get(route($route))->assertOk();

        $response
            ->assertSee('data-workspace-app-bar', false)
            ->assertSee('data-workspace-app-nav', false)
            ->assertSee('data-workspace-mega-root', false)
            ->assertSee('data-workspace-user-menu', false)
            ->assertSee('data-workspace-palette-open', false);

        $response->assertDontSee('data-workspace-panel-switch', false);

        if ($persona === 'administrator') {
            $response
                ->assertSee('data-nav-key="user.admin"', false)
                ->assertDontSee('data-nav-key="staff.admin"', false)
                ->assertSee('href="'.route('admin.index').'"', false);
        }
    }

    public function test_guest_sees_simple_app_bar_links_and_sign_in_actions(): void
    {
        $this->get(route('home'))
            ->assertOk()
            ->assertSee('data-workspace-app-bar', false)
            ->assertSee('data-nav-key="guest.ticket"', false)
            ->assertSee('data-nav-key="help"', false)
            ->assertSee('data-nav-key="public.login"', false)
            ->assertDontSee('data-workspace-mega-root', false)
            ->assertDontSee('data-workspace-user-menu', false)
            ->assertSee('sg-app-bar__guest-actions', false);
    }

    public function test_admin_panel_uses_admin_mega_sections_not_staff_modules(): void
    {
        $admin = User::factory()->protectedRole('administrator')->create();

        $html = $this->actingAs($admin)
            ->get(route('admin.index'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('data-workspace-mega-root', $html);
        $this->assertStringContainsString('data-workspace-mega-trigger', $html);
        $this->assertStringContainsString(__('admin.nav.overview'), $html);
    }

    public function test_user_menu_includes_profile_and_sign_out(): void
    {
        $user = User::factory()->protectedRole('staff')->create(['name' => 'Case Worker']);

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('data-workspace-user-menu-trigger', false)
            ->assertSee('href="'.route('account.show').'"', false)
            ->assertSee('action="'.route('auth.logout').'"', false)
            ->assertSeeText('Case Worker');
    }

    public function test_administrator_user_menu_includes_github_support_links(): void
    {
        $admin = User::factory()->protectedRole('administrator')->create();

        $this->actingAs($admin)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee(config('myapes.github.repository_url'), false)
            ->assertSee(config('myapes.github.new_issue_url'), false)
            ->assertSeeText(__('workspace.dock.github'));
    }

    public function test_user_menu_keeps_same_origin_profile_in_the_same_tab(): void
    {
        $user = User::factory()->protectedRole('staff')->create();

        $html = $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->getContent();

        $this->assertMatchesRegularExpression(
            '/data-nav-key="user\.profile"[^>]*(?!target="_blank")[^>]*>/',
            $html,
        );
        $this->assertDoesNotMatchRegularExpression(
            '/data-nav-key="user\.profile"[^>]*target="_blank"/',
            $html,
        );
    }
}