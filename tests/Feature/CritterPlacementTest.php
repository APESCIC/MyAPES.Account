<?php

namespace Tests\Feature;

use App\Core\Accounts\User;
use App\Services\AuthorizationProfile;
use App\Services\ModuleInstallationSynchronizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesClinicalPersonas;
use Tests\TestCase;

class CritterPlacementTest extends TestCase
{
    use CreatesClinicalPersonas;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(ModuleInstallationSynchronizer::class)->synchronize();
    }

    public function test_not_found_page_shows_cast_svg_path(): void
    {
        $this->get('/this-route-does-not-exist-for-critter-404')
            ->assertNotFound()
            ->assertSee('mascot/cast/dot-oops.svg', false);
    }

    public function test_empty_staff_ticket_index_still_shows_mascot_empty_state(): void
    {
        $staff = User::factory()
            ->protectedRole(AuthorizationProfile::ROLE_STAFF)
            ->create();

        $this->actingAs($staff)
            ->get(route('apes-cic.tickets.index'))
            ->assertOk()
            ->assertSee('mascot-tip--empty', false)
            ->assertSee('mascot/cast/dot-thinking.svg', false);
    }
}