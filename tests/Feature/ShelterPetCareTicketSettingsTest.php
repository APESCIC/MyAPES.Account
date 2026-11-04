<?php

namespace Tests\Feature;

use App\Core\Accounts\User;
use App\Core\Extensions\Models\ModuleSetting;
use App\Services\AuthorizationProfile;
use App\Services\ModuleInstallationSynchronizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Plugins\Tickets\Models\SupportTicket;
use Plugins\Tickets\TicketServiceConfiguration;
use Tests\TestCase;

/**
 * Tickets settings pages for Shelter and Pet Care (#472).
 */
class ShelterPetCareTicketSettingsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        app(ModuleInstallationSynchronizer::class)->synchronize();
        $this->admin = User::factory()->protectedRole(AuthorizationProfile::ROLE_ADMINISTRATOR)->create();
    }

    public function test_both_modules_have_a_tickets_settings_page_with_editable_areas(): void
    {
        foreach (['shelter-rescue' => 'Adoption', 'pet-care-clinic' => 'Appointment'] as $module => $firstArea) {
            $this->actingAs($this->admin)
                ->get(route('admin.modules.settings.edit', [$module, 'tickets']))
                ->assertOk()
                ->assertSee('data-service-area-settings', false)
                ->assertSeeText($firstArea)
                ->assertSee('href="'.route('admin.modules.lists.show', [$module, 'tickets', 'service_areas']).'"', false);

            $this->actingAs($this->admin)
                ->get(route('admin.modules.lists.show', [$module, 'tickets', 'service_areas']))
                ->assertOk()
                ->assertSeeText('Ticket service areas');
        }
    }

    public function test_an_added_and_renamed_shelter_area_is_offered_on_the_ticket_form(): void
    {
        $base = ['shelter-rescue', 'tickets', 'service_areas'];
        $this->actingAs($this->admin)->post(route('admin.modules.lists.store', $base), ['label' => 'Lost and found'])->assertRedirect();
        $this->actingAs($this->admin)->put(route('admin.modules.lists.update', [...$base, 'adoption']), ['label' => 'Adoption enquiry'])->assertRedirect();

        $this->assertContains('lost_and_found', app(TicketServiceConfiguration::class)->for('shelter-rescue')->serviceAreas);

        $member = User::factory()->protectedRole(AuthorizationProfile::ROLE_SERVICE_USER)->create();
        $member->serviceSelections()->firstOrCreate(['sub_core_key' => 'shelter-rescue']);

        $this->actingAs($member)
            ->get(route('shelter.tickets.index'))
            ->assertOk()
            ->assertSee('<option value="lost_and_found"', false)
            ->assertSeeText('Lost and found')
            ->assertSeeText('Adoption enquiry');

        $this->actingAs($member)
            ->post(route('shelter.tickets.store'), [
                'service_area' => 'lost_and_found',
                'priority' => 'medium',
                'subject' => 'Found a tortoise',
                'description' => 'A tortoise was found near the shelter.',
            ])
            ->assertRedirect();
        $this->assertTrue(SupportTicket::query()->where('sub_core_key', 'shelter-rescue')->where('service_area', 'lost_and_found')->exists());
    }

    public function test_archiving_a_used_pet_care_area_keeps_old_tickets_readable_and_reset_restores_it(): void
    {
        $member = User::factory()->protectedRole(AuthorizationProfile::ROLE_SERVICE_USER)->create();
        $ticket = SupportTicket::query()->create([
            'sub_core_key' => 'pet-care-clinic',
            'user_id' => $member->id,
            'service_area' => 'billing',
            'subject' => 'Invoice question',
            'priority' => 'low',
            'status' => 'open',
            'description' => 'Question about an invoice.',
        ]);

        $this->actingAs($this->admin)
            ->post(route('admin.modules.lists.remove', ['pet-care-clinic', 'tickets', 'service_areas', 'billing']))
            ->assertSessionHas('status', 'Row archived because existing records use it.');

        $this->assertNotContains('billing', app(TicketServiceConfiguration::class)->for('pet-care-clinic')->serviceAreas);
        $this->actingAs($this->admin)
            ->get(route('petcare.tickets.show', $ticket))
            ->assertOk()
            ->assertSeeText('Billing');

        $record = ModuleSetting::query()->where('sub_core_key', 'pet-care-clinic')->where('module_key', 'tickets')->firstOrFail();
        $this->actingAs($this->admin)
            ->put(route('admin.modules.settings.update', ['pet-care-clinic', 'tickets']), [
                'version' => $record->lock_version,
                'reset_defaults' => '1',
                'confirm_reset' => '1',
            ])
            ->assertRedirect(route('admin.modules.settings.edit', ['pet-care-clinic', 'tickets']));

        $this->assertContains('billing', app(TicketServiceConfiguration::class)->for('pet-care-clinic')->serviceAreas);
    }

    public function test_public_open_a_ticket_page_uses_the_managed_labels(): void
    {
        $this->actingAs($this->admin)
            ->put(route('admin.modules.lists.update', ['shelter-rescue', 'tickets', 'service_areas', 'rescue']), ['label' => 'Rescue request']);

        auth()->logout();
        $this->get(route('tickets.public.index'))->assertOk()->assertSeeText('Rescue request');
    }
}
