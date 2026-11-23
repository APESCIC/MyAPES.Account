<?php

namespace Tests\Feature\Theme;

use App\Core\Accounts\User;
use App\Services\AuthorizationProfile;
use App\Support\Theme\ThemePreference;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Calm mode hides decorations and motion (#904).
 */
class CalmModeDecorTest extends TestCase
{
    use RefreshDatabase;

    public function test_calm_preference_sets_data_calm_on_html(): void
    {
        $user = User::factory()->protectedRole(AuthorizationProfile::ROLE_SERVICE_USER)->create([
            'theme_skin' => 'jungle',
            'theme_calm' => true,
        ]);

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('data-calm="true"', false);
    }

    public function test_calm_mode_default_is_off(): void
    {
        $user = User::factory()->protectedRole(AuthorizationProfile::ROLE_SERVICE_USER)->create([
            'theme_calm' => null,
        ]);

        $appearance = app(ThemePreference::class)->resolve($user);

        $this->assertFalse($appearance['calm']);
    }

    public function test_calm_css_hides_jungle_decor_and_chrome_critters(): void
    {
        $decorCss = (string) file_get_contents(base_path('resources/css/themes/jungle-decor.css'));
        $menuCss = (string) file_get_contents(base_path('resources/css/themes/menu-decor.css'));
        $motionCss = (string) file_get_contents(base_path('resources/css/themes/critter-motion.css'));

        $this->assertStringContainsString("[data-calm='true']", $decorCss);
        $this->assertStringContainsString('.jungle-decor', $decorCss);
        $this->assertStringContainsString('[data-calm=\'true\'] .critter-chrome', $menuCss);
        $this->assertStringContainsString("[data-calm='true'] .critter img", $motionCss);
    }

    public function test_error_critter_still_renders_under_calm_jungle(): void
    {
        $user = User::factory()->protectedRole(AuthorizationProfile::ROLE_SERVICE_USER)->create([
            'theme_skin' => 'jungle',
            'theme_calm' => true,
        ]);

        $this->actingAs($user)
            ->get('/this-route-does-not-exist-calm-decor')
            ->assertNotFound()
            ->assertSee('mascot/cast/dot-oops.svg', false)
            ->assertDontSee('critter-chrome', false);
    }
}