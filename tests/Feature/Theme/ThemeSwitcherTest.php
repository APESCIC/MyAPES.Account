<?php

namespace Tests\Feature\Theme;

use App\Core\Accounts\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Theme switcher chrome (#976).
 */
class ThemeSwitcherTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_home_renders_footer_theme_switcher_without_legacy_dock_toggle(): void
    {
        $this->get(route('home'))
            ->assertOk()
            ->assertSee('data-theme-switcher', false)
            ->assertSee('theme-switcher--footer', false)
            ->assertSee('id="footer-theme-skin-label"', false)
            ->assertDontSee('data-theme-toggle', false);
    }

    public function test_authenticated_dashboard_and_profile_render_theme_controls(): void
    {
        $user = User::factory()->accessLevel(User::ROLE_SERVICE_USER)->create();

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('data-theme-switcher', false)
            ->assertSee('theme-switcher--menu', false)
            ->assertSee('id="user-menu-theme-skin-label"', false)
            ->assertDontSee('data-theme-toggle', false);

        $this->actingAs($user)
            ->get(route('profile.edit'))
            ->assertOk()
            ->assertSee('id="appearance"', false)
            ->assertSee(__('workspace.theme.appearance_heading'), false)
            ->assertSee('theme-switcher--profile', false)
            ->assertSee('id="profile-appearance-skin-label"', false)
            ->assertDontSee('data-theme-toggle', false)
            ->assertDontSee('theme-switcher--footer', false);
    }
}