<?php

namespace Tests\Feature\Theme;

use App\Core\Accounts\User;
use App\Services\AuthorizationProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Menu and header jungle decorations (#902).
 */
class MenuHeaderDecorTest extends TestCase
{
    use RefreshDatabase;

    public function test_signed_in_user_menu_renders_spike_critter(): void
    {
        $user = User::factory()->protectedRole(AuthorizationProfile::ROLE_SERVICE_USER)->create();

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('critter-chrome', false)
            ->assertSee('critter--chrome', false)
            ->assertSee('mascot/cast/spike-welcome.svg', false);
    }

    public function test_guest_footer_renders_spike_critter(): void
    {
        $this->get(route('home'))
            ->assertOk()
            ->assertSee('site-footer__critter', false)
            ->assertSee('mascot/cast/spike-welcome.svg', false);
    }

    public function test_jungle_mega_menu_leaf_and_icon_rules_are_in_decor_css(): void
    {
        $decorCss = (string) file_get_contents(base_path('resources/css/themes/jungle-decor.css'));

        $this->assertStringContainsString('.sg-mega__heading::before', $decorCss);
        $this->assertStringContainsString('.sg-mega__link-icon i', $decorCss);
        $this->assertStringContainsString('pointer-events: none', $decorCss);
    }

    public function test_glassy_user_menu_still_renders_menu_critter_without_jungle_assets(): void
    {
        $user = User::factory()->protectedRole(AuthorizationProfile::ROLE_SERVICE_USER)->create([
            'theme_skin' => 'glassy',
        ]);

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('data-skin="glassy"', false)
            ->assertSee('critter-chrome', false)
            ->assertDontSee('/decor/jungle/canopy', false);
    }
}