<?php

namespace Tests\Feature;

use App\Core\Accounts\User;
use App\Core\Extensions\Models\ModuleSetting;
use App\Services\AuthorizationProfile;
use App\Services\ModuleInstallationSynchronizer;
use App\Services\ModuleSettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Plugins\Cases\CaseCategoryResolver;
use Plugins\Tickets\Models\SupportTicket;
use Plugins\Tickets\TicketCategoryResolver;
use Tests\TestCase;

/**
 * Managed lists for ticket service areas and case categories (#471).
 */
class ManagedListsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        app(ModuleInstallationSynchronizer::class)->synchronize();
        $this->admin = User::factory()->protectedRole(AuthorizationProfile::ROLE_ADMINISTRATOR)->create();
    }

    public function test_lists_are_seeded_from_todays_defaults(): void
    {
        $this->assertCount(11, app(TicketCategoryResolver::class)->serviceAreas('apes-cic'));
        $this->assertCount(8, app(CaseCategoryResolver::class)->categories('apes-cic'));

        $this->actingAs($this->admin)
            ->get(route('admin.modules.lists.show', ['apes-cic', 'tickets', 'service_areas']))
            ->assertOk()
            ->assertSeeText('Ticket service areas')
            ->assertSee('data-managed-list-item="web_development"', false);

        $this->actingAs($this->admin)
            ->get(route('admin.modules.lists.show', ['apes-cic', 'cases', 'categories']))
            ->assertOk()
            ->assertSeeText('Case categories')
            ->assertSee('data-managed-list-item="formal_complaint"', false);

        $this->actingAs($this->admin)
            ->get(route('admin.modules.settings.edit', ['apes-cic', 'tickets']))
            ->assertOk()
            ->assertSee('href="'.route('admin.modules.lists.show', ['apes-cic', 'tickets', 'service_areas']).'"', false);
    }

    public function test_admin_can_add_rename_reorder_and_delete_a_row(): void
    {
        $base = ['apes-cic', 'tickets', 'service_areas'];

        $this->actingAs($this->admin)
            ->post(route('admin.modules.lists.store', $base), ['label' => 'Volunteer Transport'])
            ->assertRedirect(route('admin.modules.lists.show', $base));
        $areas = app(TicketCategoryResolver::class);
        $this->assertSame('volunteer_transport', $areas->serviceAreaKeys('apes-cic')[11]);
        $this->assertNotNull($areas->findSubcategory('apes-cic', 'volunteer_transport', 'general'));

        $this->actingAs($this->admin)
            ->put(route('admin.modules.lists.update', [...$base, 'volunteer_transport']), ['label' => 'Transport & Driving'])
            ->assertRedirect();
        $this->assertSame('Transport & Driving', $areas->labelForArea('apes-cic', 'volunteer_transport'));

        $this->actingAs($this->admin)
            ->post(route('admin.modules.lists.move', [...$base, 'volunteer_transport']), ['direction' => 'up'])
            ->assertRedirect();
        $this->assertSame('volunteer_transport', $areas->serviceAreaKeys('apes-cic')[10]);

        $this->actingAs($this->admin)
            ->delete(route('admin.modules.lists.destroy', [...$base, 'volunteer_transport']))
            ->assertRedirect()
            ->assertSessionHas('status', 'Row deleted.');
        $this->assertNotContains('volunteer_transport', array_column($areas->allServiceAreas('apes-cic'), 'key'));
    }

    public function test_deleting_an_in_use_row_archives_it_and_old_tickets_still_show_it(): void
    {
        $owner = User::factory()->create();
        $ticket = SupportTicket::query()->create([
            'sub_core_key' => 'apes-cic',
            'user_id' => $owner->id,
            'service_area' => 'fundraising',
            'sub_category' => 'campaign',
            'subject' => 'Archived area ticket',
            'priority' => 'medium',
            'status' => 'open',
            'description' => 'Ticket in an area that gets archived.',
        ]);

        $this->actingAs($this->admin)
            ->delete(route('admin.modules.lists.destroy', ['apes-cic', 'tickets', 'service_areas', 'fundraising']))
            ->assertRedirect()
            ->assertSessionHas('status', 'Row archived because existing records use it.');

        $areas = app(TicketCategoryResolver::class);
        $this->assertNotContains('fundraising', $areas->serviceAreaKeys('apes-cic'));
        $this->assertSame('Fundraising & Sponsorship', $areas->labelForArea('apes-cic', 'fundraising'));
        $this->assertSame('Campaign', $areas->labelForSubcategory('apes-cic', 'fundraising', 'campaign'));

        $this->actingAs($this->admin)
            ->get(route('apes-cic.tickets.show', $ticket))
            ->assertOk()
            ->assertSeeText('Fundraising & Sponsorship');

        $this->actingAs($owner)
            ->get(route('apes-cic.tickets.index'))
            ->assertOk()
            ->assertDontSee('<option value="fundraising"', false);

        $listPage = $this->actingAs($this->admin)
            ->get(route('admin.modules.lists.show', ['apes-cic', 'tickets', 'service_areas']))
            ->assertOk()
            ->getContent();
        $this->assertMatchesRegularExpression('/data-managed-list-item="fundraising"\s+data-archived/', (string) $listPage);

        $this->actingAs($this->admin)
            ->post(route('admin.modules.lists.restore', ['apes-cic', 'tickets', 'service_areas', 'fundraising']))
            ->assertRedirect();
        $this->assertContains('fundraising', $areas->serviceAreaKeys('apes-cic'));
    }

    public function test_bulk_settings_save_keeps_the_archive_flag_and_reset_restores_defaults(): void
    {
        $base = ['apes-cic', 'cases', 'categories'];
        $this->actingAs($this->admin)->post(route('admin.modules.lists.store', $base), ['label' => 'Extra category']);
        $settings = app(ModuleSettingsService::class);
        $current = $settings->get('apes-cic', 'cases');
        $current['categories'][0]['archived'] = true;
        $record = ModuleSetting::query()->where('sub_core_key', 'apes-cic')->where('module_key', 'cases')->firstOrFail();
        $settings->save('apes-cic', 'cases', $current, (int) $record->lock_version);
        $record->refresh();

        $payload = ['version' => $record->lock_version, ...$record->settings];
        $this->actingAs($this->admin)
            ->put(route('admin.modules.settings.update', ['apes-cic', 'cases']), $payload)
            ->assertRedirect();
        $this->assertTrue($settings->get('apes-cic', 'cases')['categories'][0]['archived'] ?? false);

        $record->refresh();
        $this->actingAs($this->admin)
            ->put(route('admin.modules.settings.update', ['apes-cic', 'cases']), [
                'version' => $record->lock_version,
                'reset_defaults' => '1',
                'confirm_reset' => '1',
            ])
            ->assertRedirect();

        $categories = app(CaseCategoryResolver::class);
        $this->assertCount(8, $categories->categories('apes-cic'));
        $this->assertCount(8, $categories->allCategories('apes-cic'));
    }

    public function test_unknown_lists_and_rows_are_not_found(): void
    {
        $this->actingAs($this->admin)
            ->get(route('admin.modules.lists.show', ['apes-cic', 'tickets', 'websites']))
            ->assertNotFound();
        $this->actingAs($this->admin)
            ->put(route('admin.modules.lists.update', ['apes-cic', 'tickets', 'service_areas', 'nope']), ['label' => 'X'])
            ->assertNotFound();
    }
}
