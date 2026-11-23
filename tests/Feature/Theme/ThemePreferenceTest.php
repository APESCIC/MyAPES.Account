<?php

namespace Tests\Feature\Theme;

use App\Core\Accounts\User;
use App\Core\Settings\AppSettings;
use App\Core\Settings\GeneralSettings;
use App\Services\AuthorizationProfile;
use App\Support\Theme\ThemePreference;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Persisted workspace appearance (#977).
 */
class ThemePreferenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_existing_user_null_preferences_resolve_to_glassy(): void
    {
        $user = User::factory()->protectedRole(AuthorizationProfile::ROLE_SERVICE_USER)->create([
            'theme_skin' => null,
            'theme_mode' => null,
            'theme_calm' => null,
        ]);

        $appearance = app(ThemePreference::class)->resolve($user);

        $this->assertSame('glassy', $appearance['skin']);
        $this->assertSame('light', $appearance['mode']);
        $this->assertFalse($appearance['calm']);
    }

    public function test_saving_preferences_persists_and_follows_the_user(): void
    {
        $user = User::factory()->protectedRole(AuthorizationProfile::ROLE_SERVICE_USER)->create();

        $this->actingAs($user)
            ->postJson(route('account.theme-preference.store'), [
                'skin' => 'jungle',
                'mode' => 'dark',
                'calm' => true,
            ])
            ->assertOk()
            ->assertJsonPath('appearance.skin', 'jungle')
            ->assertJsonPath('appearance.mode', 'dark')
            ->assertJsonPath('appearance.calm', true);

        $user->refresh();
        $this->assertSame('jungle', $user->theme_skin);
        $this->assertSame('dark', $user->theme_mode);
        $this->assertTrue($user->theme_calm);

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('data-appearance-source="server"', false)
            ->assertSee('data-skin="jungle"', false)
            ->assertSee('data-theme="dark"', false)
            ->assertSee('data-calm="true"', false)
            ->assertSee("root.dataset.appearanceSource === 'server'", false);
    }

    public function test_changing_the_admin_default_only_affects_users_with_null_skin(): void
    {
        $unset = User::factory()->protectedRole(AuthorizationProfile::ROLE_SERVICE_USER)->create([
            'theme_skin' => null,
        ]);
        $chosen = User::factory()->protectedRole(AuthorizationProfile::ROLE_SERVICE_USER)->create([
            'theme_skin' => 'glassy',
        ]);

        app(AppSettings::class)->set(GeneralSettings::GROUP, ['default_theme_skin' => 'jungle']);

        $resolver = app(ThemePreference::class);

        $this->assertSame('jungle', $resolver->resolve($unset)['skin']);
        $this->assertSame('glassy', $resolver->resolve($chosen)['skin']);
    }

    public function test_guest_pages_use_layout_defaults_without_account_columns(): void
    {
        $this->get(route('home'))
            ->assertOk()
            ->assertSee('data-skin="glassy"', false)
            ->assertSee('data-theme="light"', false)
            ->assertDontSee('data-appearance-source="server"', false)
            ->assertSee("appearanceKey = 'myapes-appearance'", false)
            ->assertSee("themeKey = 'myapes-theme'", false);

        app(AppSettings::class)->set(GeneralSettings::GROUP, ['default_theme_skin' => 'jungle']);

        $this->get(route('home'))
            ->assertOk()
            ->assertSee('data-skin="glassy"', false)
            ->assertDontSee('data-appearance-source="server"', false);
    }

    /**
     * Browser slow-network FOUC verification is tracked in #908 (Playwright).
     */
    public function test_playwright_slow_network_fouc_deferred_to_issue_908(): void
    {
        $this->markTestIncomplete('Playwright slowed-network first-paint check deferred to #908.');
    }

    public function test_guest_cookie_is_merged_once_on_login(): void
    {
        $user = User::factory()->protectedRole(AuthorizationProfile::ROLE_SERVICE_USER)->create([
            'theme_skin' => null,
        ]);

        $resolver = app(ThemePreference::class);
        $resolver->mergeGuestCookieOnce(
            $user,
            json_encode(['skin' => 'jungle', 'mode' => 'dark', 'calm' => false], JSON_THROW_ON_ERROR),
        );

        $user->refresh();
        $this->assertSame('jungle', $user->theme_skin);
        $this->assertSame('dark', $user->theme_mode);

        $resolver->mergeGuestCookieOnce(
            $user,
            json_encode(['skin' => 'glassy', 'mode' => 'light', 'calm' => true], JSON_THROW_ON_ERROR),
        );

        $user->refresh();
        $this->assertSame('jungle', $user->theme_skin);
    }
}