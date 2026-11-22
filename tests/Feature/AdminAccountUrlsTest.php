<?php

namespace Tests\Feature;

use App\Core\Accounts\User;
use App\Services\AuthorizationProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminAccountUrlsTest extends TestCase
{
    use RefreshDatabase;

    public function test_legacy_account_type_urls_redirect_with_301(): void
    {
        $administrator = User::factory()->protectedRole(AuthorizationProfile::ROLE_ADMINISTRATOR)->create();

        $this->actingAs($administrator)
            ->get('/admin/users?account_type=public')
            ->assertRedirect(route('admin.public-accounts.index'))
            ->assertStatus(301);

        $this->actingAs($administrator)
            ->get('/admin/users?account_type=staff&q=ada')
            ->assertRedirect(route('admin.staff-accounts.index', ['q' => 'ada']))
            ->assertStatus(301);
    }

    public function test_legacy_user_show_redirects_to_typed_account_url(): void
    {
        $administrator = User::factory()->protectedRole(AuthorizationProfile::ROLE_ADMINISTRATOR)->create();
        $public = User::factory()->accessLevel(User::ROLE_SERVICE_USER)->create();
        $staff = User::factory()->accessLevel(User::ROLE_STAFF)->create();

        $this->actingAs($administrator)
            ->get(route('admin.users.show', $public))
            ->assertRedirect(route('admin.public-accounts.show', $public))
            ->assertStatus(301);

        $this->actingAs($administrator)
            ->get(route('admin.users.show', $staff))
            ->assertRedirect(route('admin.staff-accounts.show', $staff))
            ->assertStatus(301);
    }

    public function test_new_account_routes_resolve_and_render(): void
    {
        $administrator = User::factory()->protectedRole(AuthorizationProfile::ROLE_ADMINISTRATOR)->create();
        $public = User::factory()->accessLevel(User::ROLE_SERVICE_USER)->create(['name' => 'Pat Public']);

        $this->actingAs($administrator)
            ->get(route('admin.public-accounts.index'))
            ->assertOk()
            ->assertSeeText('Public Accounts')
            ->assertSeeText('Pat Public');

        $this->actingAs($administrator)
            ->get(route('admin.staff-accounts.index'))
            ->assertOk()
            ->assertSeeText('Staff Accounts');

        $this->actingAs($administrator)
            ->get(route('admin.public-accounts.show', $public))
            ->assertOk()
            ->assertSeeText('Pat Public');
    }
}
