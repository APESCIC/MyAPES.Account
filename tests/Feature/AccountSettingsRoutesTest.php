<?php

namespace Tests\Feature;

use App\Core\Accounts\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AccountSettingsRoutesTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_account_can_open_each_settings_page(): void
    {
        $user = User::factory()->create();

        foreach (['account.show', 'account.profile', 'account.security', 'account.privacy'] as $route) {
            $this->actingAs($user)
                ->get(route($route))
                ->assertOk()
                ->assertSee(__('account.settings.tabs_label'), false);
        }
    }

    public function test_directory_staff_can_open_each_settings_page(): void
    {
        $user = User::factory()->protectedRole('staff')->create();

        foreach (['account.show', 'account.profile', 'account.security', 'account.privacy'] as $route) {
            $this->actingAs($user)
                ->get(route($route))
                ->assertOk();
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

    public function test_legacy_profile_route_serves_hash_redirect_page(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('profile.edit'))
            ->assertOk()
            ->assertSee('#passkeys', false)
            ->assertSee('Continue to Account settings', false);
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
}
