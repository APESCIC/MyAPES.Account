<?php

namespace Tests\Feature\Admin;

use App\Core\Accounts\User;
use App\Services\AdminSearchCatalogue;
use App\Services\AuthorizationProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminSecuritySearchAndRedirectsTest extends TestCase
{
    use RefreshDatabase;

    public function test_moved_admin_urls_redirect_and_ctrl_k_finds_new_pages(): void
    {
        $admin = User::factory()->protectedRole(AuthorizationProfile::ROLE_ADMINISTRATOR)->create();

        $this->actingAs($admin)
            ->get('/admin/settings/keys-and-secrets')
            ->assertStatus(301)
            ->assertRedirect(route('admin.security.keys-and-secrets'));

        $ids = collect(app(AdminSearchCatalogue::class)->entriesFor($admin))->pluck('id')->all();

        foreach ([
            'admin.security',
            'admin.audit-log',
            'admin.keys',
            'admin.sign-in-policy',
            'admin.public-profiles',
            'admin.versions',
        ] as $id) {
            $this->assertContains($id, $ids);
        }
    }
}
