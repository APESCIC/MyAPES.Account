<?php

namespace Tests\Feature;

use App\Core\Accounts\User;
use App\Services\ModuleInstallationSynchronizer;
use App\Support\WorkflowLabels;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Plugins\Tickets\Models\SupportTicket;
use Plugins\Tickets\TicketServiceConfiguration;
use Tests\TestCase;

class TicketOptionLabelsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(ModuleInstallationSynchronizer::class)->synchronize();
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function fixedAreaServices(): array
    {
        return [
            'shelter' => ['shelter-rescue', 'shelter.tickets'],
            'pet care' => ['pet-care-clinic', 'petcare.tickets'],
        ];
    }

    #[DataProvider('fixedAreaServices')]
    public function test_ticket_form_options_never_render_raw_ids(string $subCoreKey, string $routePrefix): void
    {
        $html = $this->actingAs(User::factory()->create())
            ->get(route($routePrefix.'.index'))
            ->assertOk()
            ->getContent();

        preg_match_all('#<option[^>]*>(.*?)</option>#s', (string) $html, $matches);
        $labels = array_map(static fn (string $label): string => trim(html_entity_decode(strip_tags($label))), $matches[1]);

        $this->assertNotEmpty($labels);
        foreach ($labels as $label) {
            $this->assertStringNotContainsString('_', $label, "Option label [{$label}] shows a raw ID.");
        }

        $areas = app(TicketServiceConfiguration::class)->for($subCoreKey)->serviceAreas;
        foreach ($areas as $area) {
            $this->assertContains(WorkflowLabels::label('ticket_area', $area), $labels);
        }
    }

    #[DataProvider('fixedAreaServices')]
    public function test_ticket_list_and_detail_show_the_area_label(string $subCoreKey, string $routePrefix): void
    {
        $owner = User::factory()->create();
        $area = $subCoreKey === 'shelter-rescue' ? 'animal_welfare' : 'follow_up';
        $label = $subCoreKey === 'shelter-rescue' ? 'Animal Welfare' : 'Follow-Up';
        $ticket = SupportTicket::query()->create([
            'sub_core_key' => $subCoreKey,
            'user_id' => $owner->id,
            'service_area' => $area,
            'subject' => 'Label check',
            'priority' => 'medium',
            'status' => 'open',
            'description' => 'Checks the area label.',
        ]);

        $this->actingAs($owner)
            ->get(route($routePrefix.'.index'))
            ->assertOk()
            ->assertSeeText($label);

        $this->get(route($routePrefix.'.show', $ticket))
            ->assertOk()
            ->assertSeeText($label)
            ->assertDontSeeText($area);
    }

    public function test_every_fixed_area_has_a_title_case_label(): void
    {
        $services = app(TicketServiceConfiguration::class);

        foreach (['shelter-rescue', 'pet-care-clinic'] as $subCoreKey) {
            foreach ($services->for($subCoreKey)->serviceAreas as $area) {
                $this->assertTrue(trans()->has("terms.ticket_area.{$area}"), "Missing terms.ticket_area.{$area}.");
            }
        }
    }
}
