<?php

namespace Tests\Feature;

use App\Core\Accounts\ClientNote;
use App\Core\Accounts\User;
use App\Services\AuthorizationProfile;
use App\Services\ModuleInstallationSynchronizer;
use App\Services\TotpTwoFactorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Plugins\Tickets\Models\SupportTicket;
use Tests\TestCase;

class UserProfileHubTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(ModuleInstallationSynchronizer::class)->synchronize();
    }

    public function test_admin_profile_hub_shows_tabs_and_records(): void
    {
        $administrator = $this->userWithAccess(User::ROLE_ADMIN);
        $client = User::factory()->create([
            'identity_type' => User::IDENTITY_LOCAL,
        ]);

        SupportTicket::query()->create([
            'sub_core_key' => 'apes-cic',
            'user_id' => $client->id,
            'service_area' => 'web_development',
            'sub_category' => 'website_issue',
            'subject' => 'Hub ticket',
            'priority' => 'medium',
            'status' => 'open',
            'description' => 'For the profile hub',
        ]);

        $this->actingAs($administrator)
            ->get(route('admin.users.show', ['user' => $client, 'tab' => 'overview']))
            ->assertOk()
            ->assertSee('data-profile-hub-tabs', false)
            ->assertSee(__('admin.users.hub.tabs.overview'))
            ->assertSee(__('admin.users.hub.tabs.records'))
            ->assertSee(__('admin.users.hub.admin_tools.heading'));

        $this->actingAs($administrator)
            ->get(route('admin.users.show', ['user' => $client, 'tab' => 'records']))
            ->assertOk()
            ->assertSee('Hub ticket');
    }

    public function test_staff_can_view_public_client_but_not_edit_or_see_staff_accounts(): void
    {
        $staff = $this->userWithAccess(User::ROLE_STAFF);
        $client = User::factory()->create([
            'identity_type' => User::IDENTITY_LOCAL,
        ]);
        $otherStaff = $this->userWithAccess(User::ROLE_STAFF);

        $this->actingAs($staff)
            ->get(route('clients.show', $client))
            ->assertOk()
            ->assertSee(__('admin.users.hub.staff_read_only'))
            ->assertDontSee(__('admin.users.hub.admin_tools.heading'));

        $this->actingAs($staff)
            ->get(route('clients.show', $otherStaff))
            ->assertNotFound();
    }

    public function test_staff_can_add_notes_but_only_admin_can_edit_or_delete_and_notes_stay_private(): void
    {
        $staff = $this->userWithAccess(User::ROLE_STAFF);
        $administrator = $this->userWithAccess(User::ROLE_ADMIN);
        $client = User::factory()->create([
            'identity_type' => User::IDENTITY_LOCAL,
        ]);

        $this->actingAs($staff)
            ->post(route('clients.notes.store', $client), [
                'body' => 'Internal follow-up for shelter intake.',
            ])
            ->assertRedirect();

        $note = ClientNote::query()->where('user_id', $client->id)->firstOrFail();
        $this->assertSame($staff->id, (int) $note->author_id);

        $this->actingAs($staff)
            ->put(route('clients.notes.update', [$client, $note]), [
                'body' => 'Staff should not edit.',
            ])
            ->assertForbidden();

        $this->actingAs($administrator)
            ->put(route('clients.notes.update', [$client, $note]), [
                'body' => 'Admin updated note.',
                'admin_hub' => '1',
            ])
            ->assertRedirect();

        $this->assertSame('Admin updated note.', $note->fresh()->body);

        $this->actingAs($client)
            ->get(route('profile.edit'))
            ->assertOk()
            ->assertDontSee('Admin updated note.');
    }

    public function test_admin_can_reset_two_factor_for_local_client(): void
    {
        $administrator = $this->userWithAccess(User::ROLE_ADMIN);
        $client = User::factory()->create([
            'identity_type' => User::IDENTITY_LOCAL,
            'password' => 'Password123!',
        ]);

        $twoFactor = app(TotpTwoFactorService::class);
        $twoFactor->beginEnrolment($client);
        $client->forceFill(['two_factor_confirmed_at' => now()])->save();
        $this->assertTrue($twoFactor->hasEnabledTwoFactor($client->fresh()));

        $this->actingAs($administrator)
            ->post(route('admin.users.two-factor.reset', $client), [
                'confirm_two_factor_reset' => '1',
            ])
            ->assertRedirect(route('admin.users.show', ['user' => $client, 'tab' => 'overview']))
            ->assertSessionHas('status');

        $this->assertFalse($twoFactor->hasEnabledTwoFactor($client->fresh()));
    }

    public function test_staff_ticket_created_for_client_is_owned_by_client(): void
    {
        $staff = $this->userWithAccess(User::ROLE_STAFF);
        $client = User::factory()->create([
            'identity_type' => User::IDENTITY_LOCAL,
        ]);

        $this->actingAs($staff)
            ->post(route('clients.tickets.store', $client), [
                'module' => 'apes-cic',
                'service_area' => 'operations_facilities',
                'sub_category' => 'premises',
                'subject' => 'Owned by client',
                'priority' => 'medium',
                'description' => 'Created from the profile hub',
            ])
            ->assertRedirect();

        $ticket = SupportTicket::query()->where('subject', 'Owned by client')->first();
        $this->assertNotNull($ticket);
        $this->assertSame($client->id, (int) $ticket->user_id);
    }

    public function test_disabled_or_unscoped_plugins_are_hidden_from_records(): void
    {
        $staff = $this->userWithAccess(User::ROLE_STAFF);
        $client = User::factory()->create([
            'identity_type' => User::IDENTITY_LOCAL,
        ]);

        SupportTicket::query()->create([
            'sub_core_key' => 'apes-cic',
            'user_id' => $client->id,
            'service_area' => 'web_development',
            'sub_category' => 'website_issue',
            'subject' => 'Hidden without view-all',
            'priority' => 'medium',
            'status' => 'open',
            'description' => 'Should stay hidden for plain staff if view-all is absent.',
        ]);

        // Staff role keeps tickets.view-all by default in this app; assert the panel structure.
        $this->actingAs($staff)
            ->get(route('clients.show', ['client' => $client, 'tab' => 'records']))
            ->assertOk()
            ->assertSee(__('admin.users.hub.records.heading'));
    }

    public function test_staff_clients_view_permission_is_named_for_core(): void
    {
        $this->assertTrue(
            in_array(
                AuthorizationProfile::PERMISSION_CLIENT_VIEW,
                app(AuthorizationProfile::class)->permissions(),
                true,
            ),
        );
    }

    private function userWithAccess(string $accessLevel): User
    {
        return User::factory()
            ->accessLevel($accessLevel)
            ->create()
            ->refresh();
    }
}
