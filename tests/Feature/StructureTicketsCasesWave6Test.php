<?php

namespace Tests\Feature;

use App\Core\Accounts\User;
use App\Core\Eloquent\MorphMap;
use App\Core\Extensions\Plugins\PluginRegistry;
use App\Services\AuthorizationProfile;
use App\Services\ModuleInstallationSynchronizer;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Plugins\Cases\Http\Controllers\CaseController;
use Plugins\Cases\Http\Controllers\CaseUpdateController;
use Plugins\Cases\Models\ShelterCase;
use Plugins\Tickets\Http\Controllers\TicketController;
use Plugins\Tickets\Models\SupportTicket;
use Tests\TestCase;

class StructureTicketsCasesWave6Test extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(ModuleInstallationSynchronizer::class)->synchronize();
    }

    public function test_single_ticket_controller_serves_all_module_ticket_routes(): void
    {
        foreach ([
            'apes-cic.tickets.index',
            'apes-cic.tickets.store',
            'apes-cic.tickets.show',
            'apes-cic.tickets.update',
            'apes-cic.tickets.destroy',
            'shelter.tickets.index',
            'shelter.tickets.store',
            'shelter.tickets.show',
            'shelter.tickets.update',
            'petcare.tickets.index',
            'petcare.tickets.store',
            'petcare.tickets.show',
            'petcare.tickets.update',
        ] as $name) {
            $this->assertTrue(Route::has($name), "Missing route {$name}");
            $action = Route::getRoutes()->getByName($name)->getActionName();
            $this->assertStringContainsString(TicketController::class, $action);
        }

        $this->assertFalse(Route::has('shelter.tickets.destroy'));
        $this->assertFalse(Route::has('petcare.tickets.destroy'));
        $this->assertFileDoesNotExist(app_path('Http/Controllers/ApesCic/TicketController.php'));
        $this->assertFileDoesNotExist(app_path('Models/SupportTicket.php'));
    }

    public function test_single_case_controller_serves_cic_and_shelter_routes(): void
    {
        foreach ([
            'apes-cic.cases.index',
            'apes-cic.cases.store',
            'apes-cic.cases.show',
            'apes-cic.cases.update',
            'apes-cic.cases.destroy',
            'apes-cic.cases.updates.store',
            'shelter.cases.index',
            'shelter.cases.store',
            'shelter.cases.show',
            'shelter.cases.update',
            'shelter.cases.updates.store',
        ] as $name) {
            $this->assertTrue(Route::has($name), "Missing route {$name}");
            $action = Route::getRoutes()->getByName($name)->getActionName();
            if (str_contains($name, 'updates')) {
                $this->assertStringContainsString(CaseUpdateController::class, $action);
            } else {
                $this->assertStringContainsString(CaseController::class, $action);
            }
        }

        $this->assertFalse(Route::has('shelter.cases.destroy'));
        $this->assertFileDoesNotExist(app_path('Http/Controllers/ApesCic/CaseController.php'));
        $this->assertFileDoesNotExist(app_path('Http/Controllers/Shelter/CaseController.php'));
        $this->assertFileDoesNotExist(app_path('Models/ShelterCase.php'));
    }

    public function test_live_ticket_and_case_urls_remain(): void
    {
        $staff = User::factory()
            ->protectedRole(AuthorizationProfile::ROLE_STAFF)
            ->create();

        $this->actingAs($staff)->get('/apes-cic/tickets')->assertOk();
        $this->actingAs($staff)->get('/shelter/tickets')->assertOk();
        $this->actingAs($staff)->get('/petcare/tickets')->assertOk();
        $this->actingAs($staff)->get('/apes-cic/cases')->assertOk();
        $this->actingAs($staff)->get('/shelter/cases')->assertOk();
    }

    public function test_morph_aliases_point_at_plugin_models(): void
    {
        $this->assertSame(SupportTicket::class, MorphMap::aliases()['support_ticket']);
        $this->assertSame(ShelterCase::class, MorphMap::aliases()['case']);
        $this->assertSame(MorphMap::aliases(), Relation::morphMap());
        $this->assertSame('support_ticket', MorphMap::aliasFor(SupportTicket::class));
        $this->assertSame('case', MorphMap::aliasFor(ShelterCase::class));
    }

    public function test_plugin_manifests_are_registered(): void
    {
        $tickets = app(PluginRegistry::class)->plugin('tickets');
        $this->assertSame(['apes-cic', 'shelter-rescue', 'pet-care-clinic'], $tickets->compatibleModules);
        $this->assertSame('tickets', $tickets->translationNamespace);

        $cases = app(PluginRegistry::class)->plugin('cases');
        $this->assertSame(['apes-cic', 'shelter-rescue'], $cases->compatibleModules);
        $this->assertSame('cases', $cases->translationNamespace);
        $this->assertCount(1, $cases->dependencies);
        $this->assertSame('pet-profiles', $cases->dependencies[0]->pluginSlug);
    }

    public function test_client_creates_apes_cic_ticket_and_staff_can_view_it(): void
    {
        $client = User::factory()->create();
        $staff = User::factory()
            ->protectedRole(AuthorizationProfile::ROLE_STAFF)
            ->create();

        $this->actingAs($client)->post(route('apes-cic.tickets.store'), [
            'service_area' => 'operations_facilities',
            'sub_category' => 'premises',
            'subject' => 'Wave6 ticket',
            'priority' => 'medium',
            'description' => 'Structure move coverage.',
        ])->assertRedirect();

        $ticket = SupportTicket::query()
            ->where('subject', 'Wave6 ticket')
            ->where('sub_core_key', 'apes-cic')
            ->firstOrFail();

        $this->actingAs($staff)
            ->get(route('apes-cic.tickets.show', $ticket))
            ->assertOk()
            ->assertSee('Wave6 ticket');
    }
}
