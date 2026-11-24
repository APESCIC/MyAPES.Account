<?php

namespace Tests\Feature;

use App\Core\Accounts\User;
use App\Services\AuthorizationProfile;
use App\Services\ModuleInstallationSynchronizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Staff Public Account workspace and search coverage for v0.78.0 (#935).
 */
class StaffPublicAccountWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(ModuleInstallationSynchronizer::class)->synchronize();
    }

    public function test_workspace_exposes_five_tabs_in_order(): void
    {
        $staff = User::factory()->protectedRole(AuthorizationProfile::ROLE_STAFF)->create();
        $client = User::factory()->create(['name' => 'Workspace Tab Client']);

        $page = $this->actingAs($staff)
            ->get(route('clients.show', $client))
            ->assertOk();

        foreach (['overview', 'account_management', 'account_profile', 'records', 'activity'] as $tab) {
            $page->assertSee('tab='.$tab, false);
        }

        $this->actingAs($staff)
            ->get(route('clients.show', ['client' => $client, 'tab' => 'details']))
            ->assertOk()
            ->assertSee('tab=account_profile', false);

        $this->actingAs($staff)
            ->get(route('clients.show', ['client' => $client, 'tab' => 'access']))
            ->assertOk()
            ->assertSee('tab=account_management', false);
    }

    public function test_public_users_cannot_search_people_in_the_palette(): void
    {
        $public = User::factory()->protectedRole(AuthorizationProfile::ROLE_SERVICE_USER)->create();
        User::factory()->create(['name' => 'Secret Palette Person']);

        $this->actingAs($public)
            ->getJson(route('workspace.search.records', ['q' => 'Secret Palette']))
            ->assertOk()
            ->assertJsonMissing(['type' => 'users']);
    }

    public function test_directory_badges_and_search_permission_gate_the_index(): void
    {
        $staff = User::factory()->protectedRole(AuthorizationProfile::ROLE_STAFF)->create();
        User::factory()->create([
            'name' => 'Badge Active Client',
            'account_state' => User::ACCOUNT_STATE_ACTIVE,
        ]);

        $this->actingAs($staff)
            ->get(route('clients.index'))
            ->assertOk()
            ->assertSee('Active account')
            ->assertSee('role="tablist"', false);

        $public = User::factory()->protectedRole(AuthorizationProfile::ROLE_SERVICE_USER)->create();
        $this->actingAs($public)->get(route('clients.index'))->assertForbidden();
    }
}
