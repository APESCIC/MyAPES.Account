<?php

namespace Tests\Feature\AccountSeparation;

use App\Core\Accounts\User;
use App\Services\AuthorizationProfile;
use App\Services\ModuleInstallationSynchronizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\DataProvider;
use Plugins\Cases\Models\ShelterCase;
use Plugins\Consultations\Models\PetCareConsultation;
use Plugins\PetProfiles\Models\PetProfile;
use Plugins\Recruitment\Models\RecruitmentApplication;
use Plugins\Recruitment\Models\RecruitmentRole;
use Plugins\Tickets\Models\SupportTicket;
use Tests\Concerns\CreatesClinicalPersonas;
use Tests\TestCase;

class StaffClientActionsTest extends TestCase
{
    use CreatesClinicalPersonas;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(ModuleInstallationSynchronizer::class)->synchronize();
        Notification::fake();
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function staffSideAccounts(): array
    {
        return [
            'student' => ['student'],
            'volunteer' => ['volunteer'],
            'staff' => ['staff'],
            'administrator' => ['administrator'],
        ];
    }

    public function test_unmapped_cloudron_accounts_get_guidance_on_public_entry_points(): void
    {
        $unmapped = $this->staffSide('unmapped');
        $role = RecruitmentRole::factory()->open()->create();

        $this->actingAs($unmapped)
            ->get(route('tickets.public.start', ['service' => 'apes-cic', 'area' => 'it_systems', 'via' => 'login']))
            ->assertForbidden()
            ->assertSeeText(__('public.account_separation.title'));
        $this->actingAs($unmapped)
            ->get(route('recruitment.apply.start', ['recruitmentRole' => $role, 'via' => 'register']))
            ->assertForbidden()
            ->assertSeeText(__('public.account_separation.title'));
    }

    #[DataProvider('staffSideAccounts')]
    public function test_staff_side_accounts_get_guidance_instead_of_client_create_and_apply(string $kind): void
    {
        $staff = $this->staffSide($kind);
        $role = RecruitmentRole::factory()->open()->create();

        $requests = [
            'apes cic ticket' => fn () => $this->post(route('apes-cic.tickets.store'), [
                'service_area' => 'operations_facilities',
                'sub_category' => 'premises',
                'subject' => 'Staff own ticket',
                'priority' => 'medium',
                'description' => 'Should be refused.',
            ]),
            'shelter ticket' => fn () => $this->post(route('shelter.tickets.store'), [
                'service_area' => 'rescue',
                'subject' => 'Staff own ticket',
                'priority' => 'medium',
                'description' => 'Should be refused.',
            ]),
            'shelter pet' => fn () => $this->post(route('shelter.pets.store'), [
                'name' => 'Staff pet',
                'sex' => 'unknown',
                'neutering_status' => 'unknown',
            ]),
            'pet care pet' => fn () => $this->post(route('petcare.pets.store'), [
                'name' => 'Staff pet',
                'sex' => 'unknown',
                'neutering_status' => 'unknown',
            ]),
            'apes cic case' => fn () => $this->post(route('apes-cic.cases.store'), [
                'category' => 'legal',
                'sub_category' => 'contracts',
                'priority' => 'medium',
                'title' => 'Staff own case',
            ]),
            'recruitment apply' => fn () => $this->post(route('recruitment.apply', $role), [
                'statement' => 'Staff applying from a work account.',
            ]),
            'recruitment apply start' => fn () => $this->get(route('recruitment.apply.start', ['recruitmentRole' => $role, 'via' => 'login'])),
            'open a ticket start' => fn () => $this->get(route('tickets.public.start', ['service' => 'apes-cic', 'area' => 'it_systems', 'via' => 'login'])),
            'my applications' => fn () => $this->get(route('recruitment.applications.index')),
        ];

        foreach ($requests as $label => $request) {
            $this->actingAs($staff);
            $response = $request();

            $this->assertSame(403, $response->getStatusCode(), "{$kind}: {$label}");
            $response->assertSeeText(__('public.account_separation.title'));
            $response->assertSee('href="'.route('public.register').'"', false);
        }

        $this->assertSame(0, SupportTicket::query()->count());
        $this->assertSame(0, PetProfile::query()->count());
        $this->assertSame(0, ShelterCase::query()->count());
        $this->assertSame(0, RecruitmentApplication::query()->count());
    }

    public function test_public_accounts_keep_client_create_and_apply(): void
    {
        $client = User::factory()->create();
        $role = RecruitmentRole::factory()->open()->create();

        $this->actingAs($client)
            ->post(route('apes-cic.tickets.store'), [
                'service_area' => 'operations_facilities',
                'sub_category' => 'premises',
                'subject' => 'Client ticket',
                'priority' => 'medium',
                'description' => 'Allowed.',
            ])
            ->assertRedirect();
        $this->actingAs($client)
            ->post(route('shelter.pets.store'), [
                'name' => 'Client pet',
                'sex' => 'unknown',
                'neutering_status' => 'unknown',
            ])
            ->assertRedirect();
        $this->actingAs($client)
            ->get(route('recruitment.applications.index'))
            ->assertOk();

        $this->assertSame($client->id, SupportTicket::query()->sole()->user_id);
        $this->assertSame($client->id, PetProfile::query()->sole()->user_id);
        $this->assertTrue($client->can('apes-cic.recruitment.view-own'));
        $this->assertTrue($role->isOpen());
    }

    public function test_staff_index_pages_show_guidance_in_place_of_the_client_form(): void
    {
        $staff = User::factory()->protectedRole(AuthorizationProfile::ROLE_STAFF)->create();
        $client = User::factory()->create();

        foreach (['apes-cic.tickets.index', 'shelter.pets.index', 'apes-cic.cases.index'] as $routeName) {
            $this->actingAs($staff)
                ->get(route($routeName))
                ->assertOk()
                ->assertSee('data-public-account-required', false)
                ->assertDontSee('id="create"', false);

            $this->actingAs($client)
                ->get(route($routeName))
                ->assertOk()
                ->assertDontSee('data-public-account-required', false)
                ->assertSee('id="create"', false);
        }
    }

    public function test_staff_see_guidance_on_an_open_role(): void
    {
        $staff = User::factory()->protectedRole(AuthorizationProfile::ROLE_STAFF)->create();
        $role = RecruitmentRole::factory()->open()->create();

        $this->actingAs($staff)
            ->get(route('recruitment.show', $role))
            ->assertOk()
            ->assertSee('data-public-account-required', false)
            ->assertDontSee('action="'.route('recruitment.apply', $role).'"', false);
    }

    public function test_staff_open_cases_and_consultations_only_for_someone_elses_pet(): void
    {
        $staff = User::factory()->protectedRole(AuthorizationProfile::ROLE_STAFF)->create();
        $client = User::factory()->create();
        $clientShelterPet = $this->petFor($client, PetProfile::DOMAIN_SHELTER, 'Client shelter pet');
        $clientCarePet = $this->petFor($client, PetProfile::DOMAIN_PETCARE, 'Client care pet');
        $staffShelterPet = $this->petFor($staff, PetProfile::DOMAIN_SHELTER, 'Legacy staff shelter pet');

        $this->actingAs($staff)
            ->post(route('shelter.cases.store'), [
                'pet_profile_id' => $clientShelterPet->id,
                'case_type' => 'rescue',
                'title' => 'Case for a client',
            ])
            ->assertRedirect();
        $this->actingAs($staff)
            ->post(route('shelter.cases.store'), [
                'pet_profile_id' => $staffShelterPet->id,
                'case_type' => 'rescue',
                'title' => 'Case for own pet',
            ])
            ->assertForbidden();
        $this->actingAs($staff)
            ->post(route('petcare.consultations.store'), [
                'pet_profile_id' => $clientCarePet->id,
                'subject' => 'Consultation by non-clinical staff',
            ])
            ->assertForbidden();
        $vet = $this->vetStaff();
        $vetCarePet = $this->petFor($vet, PetProfile::DOMAIN_PETCARE, 'Legacy vet care pet');
        $this->actingAs($vet)
            ->post(route('petcare.consultations.store'), [
                'pet_profile_id' => $clientCarePet->id,
                'subject' => 'Consultation for a client',
            ])
            ->assertRedirect();
        $this->actingAs($vet)
            ->post(route('petcare.consultations.store'), [
                'pet_profile_id' => $vetCarePet->id,
                'subject' => 'Consultation for own pet',
            ])
            ->assertForbidden();
        $this->assertDatabaseMissing('pet_care_consultations', ['subject' => 'Consultation by non-clinical staff']);

        $this->assertSame($client->id, ShelterCase::query()->where('title', 'Case for a client')->sole()->user_id);
        $this->assertDatabaseMissing('shelter_cases', ['title' => 'Case for own pet']);
        $this->assertSame($client->id, PetCareConsultation::query()->where('subject', 'Consultation for a client')->sole()->user_id);
        $this->assertDatabaseMissing('pet_care_consultations', ['subject' => 'Consultation for own pet']);
    }

    private function staffSide(string $kind): User
    {
        if ($kind === 'unmapped') {
            return User::factory()->cloudronIdentity('unmapped-separation')->create();
        }

        return User::factory()->protectedRole($kind)->create();
    }

    private function petFor(User $owner, string $domain, string $name): PetProfile
    {
        return PetProfile::query()->create([
            'user_id' => $owner->id,
            'service_domain' => $domain,
            'name' => $name,
            'species' => 'dog',
            'sex' => 'unknown',
            'neutering_status' => 'unknown',
        ]);
    }
}
