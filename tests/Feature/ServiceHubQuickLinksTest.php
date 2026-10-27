<?php

namespace Tests\Feature;

use App\Core\Accounts\User;
use App\Services\AuthorizationProfile;
use App\Services\ModuleInstallationSynchronizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Plugins\PetProfiles\Models\PetProfile;
use Plugins\Tickets\Models\SupportTicket;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\CreatesRecruitmentPersonas;
use Tests\TestCase;

class ServiceHubQuickLinksTest extends TestCase
{
    use CreatesRecruitmentPersonas;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(ModuleInstallationSynchronizer::class)->synchronize();
    }

    public function test_staff_hub_create_and_view_links_use_distinct_compose_and_list_urls(): void
    {
        $staff = $this->hrStaff();

        $hubs = [
            '/apes-cic' => [
                'tickets' => route('apes-cic.tickets.index'),
                'cases' => route('apes-cic.cases.index'),
                'recruitment' => route('apes-cic.recruitment.index'),
            ],
            '/shelter' => [
                'pet-profiles' => route('shelter.pets.index'),
                'tickets' => route('shelter.tickets.index'),
                'cases' => route('shelter.cases.index'),
            ],
            '/petcare' => [
                'pet-profiles' => route('petcare.pets.index'),
                'tickets' => route('petcare.tickets.index'),
                'consultations' => route('petcare.consultations.index'),
            ],
        ];

        foreach ($hubs as $hubPath => $modules) {
            $html = $this->actingAs($staff)
                ->get($hubPath)
                ->assertOk()
                ->getContent();

            foreach ($modules as $moduleKey => $listUrl) {
                $viewHref = $this->hubActionHref($html, 'view', $moduleKey);
                $createHref = $this->hubActionHref($html, 'create', $moduleKey);

                $this->assertSame($listUrl.'#list', $viewHref);
                $this->assertSame($listUrl.'#create', $createHref);
                $this->assertNotSame($viewHref, $createHref);
            }
        }
    }

    public function test_shelter_pet_profiles_hub_label_and_view_use_live_pets_list(): void
    {
        $staff = User::factory()
            ->protectedRole(AuthorizationProfile::ROLE_STAFF)
            ->create();

        $html = $this->actingAs($staff)
            ->get('/shelter')
            ->assertOk()
            ->getContent();

        $listUrl = 'http://localhost/shelter/pets';

        $this->assertSame($listUrl.'#list', $this->hubActionHref($html, 'view', 'pet-profiles'));
        $this->assertSame($listUrl.'#create', $this->hubActionHref($html, 'create', 'pet-profiles'));
        $this->assertSame($listUrl.'#list', $this->hubLabelHref($html, 'pet-profiles'));
        $this->assertStringNotContainsString('/shelter/pet-profiles', $html);
    }

    public function test_shelter_pet_profiles_alias_redirects_to_live_pets_list(): void
    {
        $staff = User::factory()
            ->protectedRole(AuthorizationProfile::ROLE_STAFF)
            ->create();

        $this->get('/shelter/pet-profiles')->assertRedirect(route('public.login'));

        $this->actingAs($staff)
            ->get('/shelter/pet-profiles')
            ->assertRedirect('/shelter/pets');

        $this->actingAs($staff)
            ->followingRedirects()
            ->get('/shelter/pet-profiles')
            ->assertOk()
            ->assertSee('id="create"', false)
            ->assertSee('id="list"', false)
            ->assertSeeText('Pet profiles');
    }

    public function test_petcare_pet_profiles_alias_redirects_to_live_pets_list(): void
    {
        $staff = User::factory()
            ->protectedRole(AuthorizationProfile::ROLE_STAFF)
            ->create();

        $this->actingAs($staff)
            ->get('/petcare/pet-profiles')
            ->assertRedirect('/petcare/pets');
    }

    public function test_hub_hides_create_links_without_create_permission(): void
    {
        $staff = User::factory()
            ->protectedRole(AuthorizationProfile::ROLE_STAFF)
            ->create();
        $this->removeRolePermission(
            AuthorizationProfile::ROLE_STAFF,
            'shelter-rescue.pet-profiles.create',
        );

        $html = $this->actingAs($staff->fresh())
            ->get('/shelter')
            ->assertOk()
            ->getContent();

        $this->assertSame(
            'http://localhost/shelter/pets#list',
            $this->hubActionHref($html, 'view', 'pet-profiles'),
        );
        $this->assertSame(0, preg_match(
            '/data-hub-action="create"[^>]*data-module-key="pet-profiles"|data-module-key="pet-profiles"[^>]*data-hub-action="create"/',
            $html,
        ));
    }

    public function test_service_index_pagination_keeps_the_list_fragment(): void
    {
        $staff = User::factory()
            ->protectedRole(AuthorizationProfile::ROLE_STAFF)
            ->create();

        foreach (range(1, 21) as $i) {
            PetProfile::query()->create([
                'user_id' => $staff->id,
                'service_domain' => PetProfile::DOMAIN_SHELTER,
                'name' => 'Shelter page pet '.$i,
                'species' => 'dog',
                'sex' => 'unknown',
                'neutering_status' => 'unknown',
            ]);
            SupportTicket::create([
                'sub_core_key' => SupportTicket::SUB_CORE_APES_CIC,
                'user_id' => $staff->id,
                'service_area' => 'operations',
                'subject' => 'CIC page ticket '.$i,
                'priority' => 'medium',
                'status' => 'open',
                'description' => 'Pagination fixture.',
            ]);
        }

        $this->actingAs($staff)
            ->get(route('shelter.pets.index'))
            ->assertOk()
            ->assertSee('page=2#list', false);
        $this->actingAs($staff)
            ->get(route('apes-cic.tickets.index'))
            ->assertOk()
            ->assertSee('page=2#list', false);
    }

    public function test_signed_in_dashboard_does_not_use_staff_hub_create_view_actions(): void
    {
        $staff = User::factory()
            ->protectedRole(AuthorizationProfile::ROLE_STAFF)
            ->create();

        $this->actingAs($staff)
            ->get('/dashboard')
            ->assertOk()
            ->assertDontSee('data-hub-action=', false)
            ->assertSee('href="'.route('shelter.pets.index').'"', false);
    }

    private function hubActionHref(string $html, string $action, string $moduleKey): string
    {
        $pattern = sprintf(
            '/<a[^>]*href="([^"]+)"[^>]*data-hub-action="%s"[^>]*data-module-key="%s"/',
            preg_quote($action, '/'),
            preg_quote($moduleKey, '/'),
        );

        $this->assertSame(
            1,
            preg_match($pattern, $html, $matches),
            "Missing hub {$action} link for {$moduleKey}.",
        );

        return html_entity_decode($matches[1], ENT_QUOTES);
    }

    private function hubLabelHref(string $html, string $moduleKey): string
    {
        $pattern = sprintf(
            '/<a[^>]*href="([^"]+)"[^>]*data-hub-module-label="%s"/',
            preg_quote($moduleKey, '/'),
        );

        $this->assertSame(
            1,
            preg_match($pattern, $html, $matches),
            "Missing hub label link for {$moduleKey}.",
        );

        return html_entity_decode($matches[1], ENT_QUOTES);
    }

    private function removeRolePermission(string $roleName, string $permissionName): void
    {
        $role = Role::query()
            ->where('guard_name', 'web')
            ->where('name', $roleName)
            ->firstOrFail();
        $permission = Permission::query()
            ->where('guard_name', 'web')
            ->where('name', $permissionName)
            ->firstOrFail();
        $role->permissions()->detach($permission->id);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
