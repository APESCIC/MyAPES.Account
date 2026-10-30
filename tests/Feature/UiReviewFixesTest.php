<?php

namespace Tests\Feature;

use App\Core\Accounts\User;
use App\Services\AuthorizationProfile;
use App\Services\ModuleInstallationSynchronizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Layout and capitalisation fixes from the UI review (#455).
 */
class UiReviewFixesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(ModuleInstallationSynchronizer::class)->synchronize();
    }

    public function test_consultations_list_shows_a_friendly_empty_state(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('petcare.consultations.index'))
            ->assertOk()
            ->assertSee('mascot-tip--empty', false)
            ->assertSeeText('No consultations are available to you yet')
            ->assertDontSee('<th>ID</th>', false);
    }

    public function test_admin_overview_labels_every_access_class_in_title_case(): void
    {
        foreach (['volunteer', 'student'] as $role) {
            User::factory()->protectedRole($role)->create();
        }

        $this->actingAs(User::factory()->protectedRole(AuthorizationProfile::ROLE_SUPER_ADMIN)->create())
            ->get(route('admin.index'))
            ->assertOk()
            ->assertSee('<th scope="row">Super Admin</th>', false)
            ->assertSee('<th scope="row">Volunteer</th>', false)
            ->assertSee('<th scope="row">Student</th>', false)
            ->assertSee('<th scope="row">Cloudron OIDC</th>', false);
    }

    public function test_permission_groups_use_real_module_names(): void
    {
        $this->actingAs(User::factory()->protectedRole(AuthorizationProfile::ROLE_SUPER_ADMIN)->create())
            ->followingRedirects()
            ->get('/admin/permissions')
            ->assertOk()
            ->assertSeeText('APES CIC · Cases')
            ->assertDontSeeText('Apes Cic Cases');
    }

    public function test_row_headers_keep_their_own_casing_and_the_spike_dock_flows_on_narrow_screens(): void
    {
        $css = (string) file_get_contents(resource_path('css/app.css'));

        $this->assertMatchesRegularExpression("/th\[scope='row'\]\s*\{[^}]*text-transform:\s*none/", $css);
        $this->assertMatchesRegularExpression('/@media \(max-width: 63\.99rem\)\s*\{\s*\.mascot-dock\s*\{[^}]*position:\s*static/', $css);
        $this->assertMatchesRegularExpression('/\.module-settings-editor :is\(input, select, textarea, button, summary\)\s*\{[^}]*scroll-margin-bottom/', $css);
    }
}
