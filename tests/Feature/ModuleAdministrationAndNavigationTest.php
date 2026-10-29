<?php

namespace Tests\Feature;

use App\Core\Accounts\User;
use App\Core\Extensions\Models\ModuleInstallation;
use App\Services\AuthorizationProfile;
use App\Services\ModuleInstallationSynchronizer;
use App\Services\ModuleProjectionCache;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesRecruitmentPersonas;
use Tests\TestCase;

class ModuleAdministrationAndNavigationTest extends TestCase
{
    use CreatesRecruitmentPersonas;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(ModuleInstallationSynchronizer::class)->synchronize();
    }

    public function test_stable_sub_core_entry_pages_are_generated_from_registry_metadata(): void
    {
        $user = $this->hrStaff();

        $this->actingAs($user)
            ->get('/apes-cic')
            ->assertOk()
            ->assertSee('APES CIC')
            ->assertSee('Tickets')
            ->assertSee('Recruitment')
            ->assertSee(route('apes-cic.tickets.index'))
            ->assertSee(route('apes-cic.recruitment.index'));
        $this->actingAs($user)
            ->get('/shelter')
            ->assertOk()
            ->assertSee('APES Shelter and Rescue')
            ->assertSee('Pet Profiles')
            ->assertSee('Cases')
            ->assertSee(route('shelter.pets.index'));
        $this->actingAs($user)
            ->get('/petcare')
            ->assertOk()
            ->assertSee('APES Pet Care Clinic')
            ->assertSeeInOrder(['Pet Profiles', 'Tickets', 'Consultations'])
            ->assertSee(route('petcare.tickets.index'));
    }

    public function test_shared_navigation_and_dashboard_summaries_hide_disabled_instances(): void
    {
        $user = User::factory()->create();
        $installation = $this->installation('apes-cic', 'tickets');
        $installation->forceFill([
            'enabled' => false,
            'disabled_at' => now(),
        ])->save();

        $dashboard = $this->actingAs($user)->get('/dashboard');

        $dashboard->assertOk();
        $dashboard->assertDontSee('data-module-instance="apes-cic:tickets"', false);
        $dashboard->assertSee(route('apes-cic.index'));
        $dashboard->assertSee('data-module-instance="apes-cic:cases"', false);
        $dashboard->assertSee(
            'data-module-instance="shelter-rescue:cases"',
            false,
        );
        $this->actingAs($user)
            ->get('/apes-cic')
            ->assertOk()
            ->assertSee('Cases')
            ->assertDontSee(route('apes-cic.tickets.index'));

        $cases = $this->installation('apes-cic', 'cases');
        $cases->forceFill([
            'enabled' => false,
            'disabled_at' => now(),
        ])->save();
        $recruitment = $this->installation('apes-cic', 'recruitment');
        $recruitment->forceFill([
            'enabled' => false,
            'disabled_at' => now(),
        ])->save();
        app(ModuleProjectionCache::class)->invalidate();
        $this->actingAs($user)
            ->get('/apes-cic')
            ->assertOk()
            ->assertSee('No plugins are currently available.');
    }

    public function test_administrators_cannot_open_module_administration(): void
    {
        $administrator = User::factory()
            ->protectedRole(AuthorizationProfile::ROLE_ADMINISTRATOR)
            ->create();

        $this->actingAs($administrator)
            ->get('/admin/modules')
            ->assertForbidden();

        $this->actingAs($administrator)
            ->post('/admin/modules/apes-cic/tickets/transition', [
                'action' => 'disable',
                'confirm_action' => '1',
                'confirm_navigation' => '1',
                'version' => $this->installation(
                    'apes-cic',
                    'tickets',
                )->lock_version,
            ])
            ->assertForbidden();
    }

    public function test_super_admins_can_view_the_complete_matrix_with_deferred_manage_controls(): void
    {
        $superAdmin = User::factory()
            ->protectedRole(AuthorizationProfile::ROLE_SUPER_ADMIN)
            ->create();

        $response = $this->actingAs($superAdmin)->get('/admin/modules');

        $response->assertOk();
        $response->assertSee('module-registry', false);
        $response->assertSee('module-registry__subcore', false);
        $response->assertSee('module-registry__row--shipped', false);
        $response->assertSee('module-registry__rows', false);
        $response->assertSee('Compatible:', false);
        $response->assertSee(route('admin.modules.settings.edit', ['apes-cic', 'tickets']));
        $response->assertSeeText('Settings');
        $this->assertSame(
            15,
            substr_count($response->getContent(), 'data-module-cell='),
        );
        $this->assertSame(
            0,
            substr_count(
                $response->getContent(),
                'data-code-status="code_not_shipped"',
            ),
        );
        $response->assertDontSee('Code Not Shipped');
        $response->assertSee('Incompatible');
        $response->assertSeeText('shelter-rescue:pet-profiles (Enabled)');
        $response->assertSee('data-module-action-form', false);
        $response->assertSee('data-module-manage', false);
        $response->assertSee('module-registry__status-rail', false);
        $response->assertSee('module-registry__metric-bar', false);
        $response->assertSeeText('Manage');
        $response->assertSee('<details class="module-registry__manage"', false);
    }

    public function test_guest_and_staff_cannot_open_module_administration(): void
    {
        $this->get('/admin/modules')
            ->assertRedirect(route('public.login'));

        $staff = User::factory()
            ->protectedRole(AuthorizationProfile::ROLE_STAFF)
            ->create();

        $this->actingAs($staff)
            ->get('/admin/modules')
            ->assertForbidden();
    }

    public function test_super_admin_forms_require_both_explicit_confirmations(): void
    {
        $superAdmin = User::factory()
            ->protectedRole(AuthorizationProfile::ROLE_SUPER_ADMIN)
            ->create();
        $installation = $this->installation('apes-cic', 'tickets');

        $page = $this->actingAs($superAdmin)->get('/admin/modules');
        $page->assertOk();
        $page->assertSee('data-module-action-form', false);
        $page->assertSee('confirm_action', false);
        $page->assertSee('confirm_navigation', false);

        $this->actingAs($superAdmin)
            ->from('/admin/modules')
            ->post('/admin/modules/apes-cic/tickets/transition', [
                'action' => 'disable',
                'version' => $installation->lock_version,
            ])
            ->assertRedirect('/admin/modules')
            ->assertSessionHasErrors(['confirm_action', 'confirm_navigation']);
        $this->assertTrue($installation->fresh()->enabled);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'module.lifecycle_validation_failed',
            'user_id' => $superAdmin->id,
        ]);
    }

    public function test_super_admin_can_submit_a_confirmed_lifecycle_transition(): void
    {
        $superAdmin = User::factory()
            ->protectedRole(AuthorizationProfile::ROLE_SUPER_ADMIN)
            ->create();
        $installation = $this->installation('apes-cic', 'tickets');

        $this->actingAs($superAdmin)
            ->post('/admin/modules/apes-cic/tickets/transition', [
                'action' => 'disable',
                'confirm_action' => '1',
                'confirm_navigation' => '1',
                'version' => $installation->lock_version,
            ])
            ->assertRedirect('/admin/modules')
            ->assertSessionHas('status');

        $this->assertFalse($installation->fresh()->enabled);
    }

    public function test_module_mutation_authorization_precedes_identifier_lookup(): void
    {
        $administrator = User::factory()
            ->protectedRole(AuthorizationProfile::ROLE_ADMINISTRATOR)
            ->create();
        $payload = [
            'action' => 'disable',
            'confirm_action' => '1',
            'confirm_navigation' => '1',
            'version' => $this->installation(
                'apes-cic',
                'tickets',
            )->lock_version,
        ];

        $existing = $this->actingAs($administrator)
            ->post('/admin/modules/apes-cic/tickets/transition', $payload);
        $missing = $this->actingAs($administrator)
            ->post('/admin/modules/not-a-core/not-a-module/transition', $payload);

        $existing->assertForbidden();
        $missing->assertForbidden();
        $this->assertSame(
            $existing->getStatusCode(),
            $missing->getStatusCode(),
        );
    }

    private function installation(
        string $subCore,
        string $module,
    ): ModuleInstallation {
        return ModuleInstallation::query()
            ->where('sub_core_key', $subCore)
            ->where('module_key', $module)
            ->firstOrFail();
    }
}
