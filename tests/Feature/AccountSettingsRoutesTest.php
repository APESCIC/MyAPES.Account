<?php

namespace Tests\Feature;

use App\Core\Accounts\User;
use App\Services\AuthorizationProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AccountSettingsRoutesTest extends TestCase
{
    use RefreshDatabase;

  /** @return array<string, array{0: string, 1: string}> */
    public static function legacyProfileHashTargets(): array
    {
        return [
            'default' => ['', 'account.show'],
            'profile' => ['#profile', 'account.profile'],
            'passkeys' => ['#passkeys', 'account.security#passkeys'],
            'two-factor' => ['#two-factor', 'account.security#two-factor'],
            'password' => ['#password', 'account.security#password'],
            'phone-pin' => ['#phone-pin', 'account.security#phone-pin'],
            'signed-in-devices' => ['#signed-in-devices', 'account.security#signed-in-devices'],
            'security-activity' => ['#security-activity', 'account.security#security-activity'],
            'account-email' => ['#account-email', 'account.show#account-email'],
            'username' => ['#username', 'account.show#username'],
            'appearance' => ['#appearance', 'account.show#appearance'],
            'notifications' => ['#notifications', 'account.privacy#notifications'],
        ];
    }

    public function test_guests_are_redirected_from_account_settings_routes(): void
    {
        foreach (['account.show', 'account.profile', 'account.security', 'account.privacy', 'profile.edit'] as $route) {
            $this->get(route($route))
                ->assertRedirect(route('public.login'));
        }
    }

    public function test_public_account_can_open_each_settings_page(): void
    {
        $user = User::factory()->create();

        foreach (['account.show', 'account.profile', 'account.security', 'account.privacy'] as $route) {
            $this->actingAs($user)
                ->get(route($route))
                ->assertOk()
                ->assertSee(__('account.settings.tabs_label'), false)
                ->assertSee('class="admin-subnav"', false);
        }
    }

    public function test_directory_staff_can_open_each_settings_page(): void
    {
        $user = User::factory()->protectedRole('staff')->create();

        foreach (['account.show', 'account.profile', 'account.security', 'account.privacy'] as $route) {
            $this->actingAs($user)
                ->get(route($route))
                ->assertOk()
                ->assertSee(__('account.settings.tabs_label'), false);
        }
    }

    public function test_account_tab_shows_login_details_and_appearance(): void
    {
        $user = User::factory()->create([
            'email' => 'account.tab@example.com',
        ]);

        $this->actingAs($user)
            ->get(route('account.show'))
            ->assertOk()
            ->assertSee('id="sign-in-methods"', false)
            ->assertSee('id="account-email"', false)
            ->assertSee('id="username"', false)
            ->assertSee('id="appearance"', false)
            ->assertSee('account.tab@example.com', false)
            ->assertSee(__('workspace.theme.appearance_heading'), false)
            ->assertSee('name="email"', false);
    }

    #[DataProvider('legacyProfileHashTargets')]
    public function test_legacy_profile_route_maps_hashes_to_account_destinations(string $hash, string $routeFragment): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)
            ->get(route('profile.edit').$hash)
            ->assertOk()
            ->assertSee(__('account.settings.redirect_continue'), false);

        if (str_contains($routeFragment, '#')) {
            [$routeName, $fragment] = explode('#', $routeFragment, 2);
            $expectedTarget = route($routeName).'#'.$fragment;
            $response->assertSee(addcslashes($expectedTarget, '/'), false);
        } else {
            $response->assertSee(addcslashes(route($routeFragment), '/'), false);
        }
    }

    public function test_account_pages_set_expected_titles(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('account.security'))
            ->assertOk()
            ->assertSee(__('account.settings.tabs.security'), false)
            ->assertSee('id="passkeys"', false);
    }

    public function test_profile_tab_renders_personal_details_form_for_public_accounts(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('account.profile'))
            ->assertOk()
            ->assertSee('id="profile-personal"', false)
            ->assertSee(__('account.settings.tabs.profile'), false);
    }

    public function test_profile_tab_renders_staff_workplace_form_for_directory_staff(): void
    {
        $user = User::factory()->protectedRole('staff')->create();

        $this->actingAs($user)
            ->get(route('account.profile'))
            ->assertOk()
            ->assertSee('id="profile-staff-workplace"', false)
            ->assertSee('name="job_title"', false);
    }

    public function test_account_tab_renders_read_only_directory_fields_for_staff(): void
    {
        $user = User::factory()
            ->protectedRole('staff')
            ->create([
                'name' => 'Staff Reader',
                'ldap_groups' => ['myapesaccount.staff'],
            ]);

        $this->actingAs($user)
            ->get(route('account.show'))
            ->assertOk()
            ->assertSee('id="account-directory"', false)
            ->assertSeeText('Staff Reader')
            ->assertSee('myapesaccount.staff', false)
            ->assertDontSee('id="account-email"', false);
    }

    public function test_staff_privacy_tab_lists_notification_preferences(): void
    {
        $staff = User::factory()
            ->protectedRole(AuthorizationProfile::ROLE_STAFF)
            ->create([
                'email_verified_at' => now(),
                'onboarding_completed_at' => now(),
            ]);

        $this->actingAs($staff)
            ->get(route('account.privacy'))
            ->assertOk()
            ->assertSee('id="notifications"', false)
            ->assertSee('id="contact-preferences"', false);
    }
}