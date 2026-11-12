<?php

namespace Tests\Feature\Api;

use App\Core\Accounts\StaffProfile;
use App\Core\Accounts\User;
use App\Core\Accounts\UserContactPreference;
use App\Core\Accounts\UserServiceSelection;
use App\Core\Api\ApiAbilities;
use App\Core\Api\ApiClient;
use App\Services\AuthorizationProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Plugins\Cases\Models\CaseUpdate;
use Plugins\Cases\Models\ShelterCase;
use Plugins\PetProfiles\Models\PetProfile;
use Plugins\Tickets\Models\SupportTicket;
use Plugins\Tickets\Models\SupportTicketMessage;
use Tests\TestCase;

/**
 * The first /api/v1 endpoints for APES Newsroom and EASM (#640).
 */
class ApiEndpointsTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'not-a-real-password-for-api-tests';

    private User $cicPerson;

    private User $shelterPerson;

    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();

        $this->cicPerson = $this->person('apes-cic', 'cic.person@example.test');
        $this->shelterPerson = $this->person('shelter-rescue', 'shelter.person@example.test');
        $this->staff = User::factory()->protectedRole(AuthorizationProfile::ROLE_STAFF)->directoryIdentity('staff-subject')->create(['name' => 'Ada Author', 'ldap_groups' => ['myapesaccount.newsroom', 'other.group']]);
        StaffProfile::query()->create(['user_id' => $this->staff->id, 'job_title' => 'Writer', 'team' => 'shelter-rescue']);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function endpoints(): array
    {
        return [
            'staff list' => ['/api/v1/staff', ApiAbilities::STAFF_READ],
            'groups' => ['/api/v1/groups', ApiAbilities::GROUPS_READ],
            'user lookup' => ['/api/v1/users/lookup?email=cic.person@example.test', ApiAbilities::USERS_READ],
            'tickets' => ['/api/v1/tickets', ApiAbilities::TICKETS_READ],
        ];
    }

    #[DataProvider('endpoints')]
    public function test_each_endpoint_needs_its_ability(string $url, string $ability): void
    {
        $others = array_values(array_diff(ApiAbilities::all(), [$ability]));

        $this->as('apes-cic', $others)->getJson($url)->assertForbidden();
        $this->as('apes-cic', [$ability])->getJson($url)->assertOk();
    }

    public function test_newsroom_reads_staff_groups_people_and_consent(): void
    {
        UserContactPreference::query()->updateOrCreate(['user_id' => $this->cicPerson->id], ['email' => true, 'sms' => false, 'policy_version' => '2026-08', 'confirmed_at' => now()]);
        $newsroom = ApiAbilities::presets()['newsroom']['abilities'];

        $this->as('apes-cic', $newsroom)->getJson('/api/v1/staff')
            ->assertOk()
            ->assertJsonPath('data.0.name', 'Ada Author')
            ->assertJsonPath('data.0.team', 'shelter-rescue')
            ->assertJsonPath('data.0.groups', ['myapesaccount.newsroom']);

        $this->as('apes-cic', $newsroom)->getJson('/api/v1/users/lookup?email=CIC.Person@example.test')
            ->assertOk()
            ->assertJsonPath('data.id', $this->cicPerson->id)
            ->assertJsonPath('data.services', ['apes-cic']);

        $this->as('apes-cic', $newsroom)->getJson('/api/v1/users/'.$this->cicPerson->id.'/consents')
            ->assertOk()
            ->assertJsonPath('data.channels.email', true)
            ->assertJsonPath('data.channels.sms', false);
    }

    public function test_responses_never_include_secrets_or_internal_notes(): void
    {
        $ticket = $this->ticket('shelter-rescue', $this->shelterPerson);
        SupportTicketMessage::query()->create(['support_ticket_id' => $ticket->id, 'user_id' => $this->staff->id, 'message' => 'Secret staff note text', 'is_staff_note' => true]);
        SupportTicketMessage::query()->create(['support_ticket_id' => $ticket->id, 'user_id' => $this->staff->id, 'message' => 'Public reply text', 'is_staff_note' => false]);
        $case = $this->shelterCase();
        CaseUpdate::query()->create(['shelter_case_id' => $case->id, 'user_id' => $this->staff->id, 'body' => 'Internal case note text', 'visibility' => CaseUpdate::VISIBILITY_INTERNAL]);
        CaseUpdate::query()->create(['shelter_case_id' => $case->id, 'user_id' => $this->staff->id, 'body' => 'Public case update text', 'visibility' => CaseUpdate::VISIBILITY_PUBLIC]);

        $responses = [
            $this->as('shelter-rescue', ApiAbilities::all())->getJson('/api/v1/users/'.$this->shelterPerson->id),
            $this->as('shelter-rescue', ApiAbilities::all())->getJson('/api/v1/staff/'.$this->staff->id),
            $this->as('shelter-rescue', ApiAbilities::all())->getJson('/api/v1/tickets/'.$ticket->id)->assertJsonPath('data.messages.0.body', 'Public reply text'),
            $this->as('shelter-rescue', ApiAbilities::all())->getJson('/api/v1/cases/'.$case->id)->assertJsonPath('data.updates.0.body', 'Public case update text'),
        ];

        foreach ($responses as $response) {
            $response->assertOk();
            $body = (string) $response->getContent();
            foreach (['password', 'two_factor', 'recovery', 'remember_token', self::PASSWORD, 'Secret staff note text', 'Internal case note text', 'oidc_sub', 'support_needs', 'phone'] as $forbidden) {
                $this->assertStringNotContainsStringIgnoringCase($forbidden, $body);
            }
        }
    }

    public function test_a_newsroom_token_cannot_read_pets_or_cases(): void
    {
        $pet = $this->pet($this->shelterPerson);
        $case = $this->shelterCase();
        $newsroom = $this->as('apes-cic', ApiAbilities::presets()['newsroom']['abilities']);

        $newsroom->getJson('/api/v1/pets/'.$pet->id)->assertForbidden();
        $newsroom->getJson('/api/v1/users/'.$this->cicPerson->id.'/pets')->assertForbidden();
        $newsroom->getJson('/api/v1/cases/'.$case->id)->assertForbidden();
    }

    public function test_a_shelter_token_cannot_read_apes_cic_tickets_or_people(): void
    {
        $cicTicket = $this->ticket('apes-cic', $this->cicPerson);
        $shelterTicket = $this->ticket('shelter-rescue', $this->shelterPerson);
        $easm = ApiAbilities::presets()['easm']['abilities'];

        $this->as('shelter-rescue', $easm)->getJson('/api/v1/tickets/'.$cicTicket->id)->assertNotFound();
        $this->as('shelter-rescue', $easm)->getJson('/api/v1/tickets?organisation=apes-cic')->assertForbidden();
        $this->as('shelter-rescue', $easm)->getJson('/api/v1/tickets')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $shelterTicket->id);
        $this->as('shelter-rescue', $easm)->getJson('/api/v1/users/'.$this->cicPerson->id)->assertNotFound();
        $this->as('shelter-rescue', $easm)->getJson('/api/v1/users/lookup?email=cic.person@example.test')->assertNotFound();
    }

    public function test_easm_reads_people_pets_and_cases_in_its_organisation(): void
    {
        $pet = $this->pet($this->shelterPerson);
        $case = $this->shelterCase($pet);
        $easm = ApiAbilities::presets()['easm']['abilities'];

        $this->as('shelter-rescue', $easm)->getJson('/api/v1/users/'.$this->shelterPerson->id.'/pets')
            ->assertOk()
            ->assertJsonPath('data.0.name', 'Monty')
            ->assertJsonPath('meta.total', 1);
        $this->as('shelter-rescue', $easm)->getJson('/api/v1/pets/'.$pet->id)->assertOk()->assertJsonPath('data.owner_id', $this->shelterPerson->id);
        $this->as('shelter-rescue', $easm)->getJson('/api/v1/cases/'.$case->id)
            ->assertOk()
            ->assertJsonPath('data.reference', 'Case #'.$case->id)
            ->assertJsonPath('data.pet.name', 'Monty');
    }

    public function test_lists_are_paginated(): void
    {
        foreach (range(1, 3) as $number) {
            $this->ticket('apes-cic', $this->cicPerson, 'website');
        }
        $this->ticket('apes-cic', $this->cicPerson, 'it_systems');

        $this->as('apes-cic', [ApiAbilities::TICKETS_READ])->getJson('/api/v1/tickets?per_page=2')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.total', 4)
            ->assertJsonPath('meta.last_page', 2);
        $this->as('apes-cic', [ApiAbilities::TICKETS_READ])->getJson('/api/v1/tickets?category=it_systems')
            ->assertJsonPath('meta.total', 1);
        $this->as('apes-cic', [ApiAbilities::TICKETS_READ])->getJson('/api/v1/tickets?per_page=5000')
            ->assertJsonPath('meta.per_page', 100);
    }

    /**
     * @param  list<string>  $abilities
     */
    private function as(string $organisation, array $abilities): self
    {
        $this->app['auth']->forgetGuards();
        $client = ApiClient::query()->create(['name' => 'Test app', 'organisation' => $organisation, 'abilities' => $abilities]);

        return $this->withToken($client->createToken('test', $abilities, now()->addDay())->plainTextToken);
    }

    private function person(string $organisation, string $email): User
    {
        $user = User::factory()->protectedRole(AuthorizationProfile::ROLE_SERVICE_USER)->create(['email' => $email, 'password' => self::PASSWORD]);
        $user->serviceSelections()->delete();
        UserServiceSelection::query()->create(['user_id' => $user->id, 'sub_core_key' => $organisation]);

        return $user;
    }

    private function ticket(string $organisation, User $owner, string $area = 'website'): SupportTicket
    {
        return SupportTicket::query()->create([
            'sub_core_key' => $organisation,
            'user_id' => $owner->id,
            'service_area' => $area,
            'subject' => 'Ticket for '.$organisation,
            'priority' => 'medium',
            'status' => 'open',
            'description' => '<p>Details</p>',
        ]);
    }

    private function pet(User $owner): PetProfile
    {
        return PetProfile::query()->create(['user_id' => $owner->id, 'service_domain' => PetProfile::DOMAIN_SHELTER, 'name' => 'Monty', 'species' => 'Corn snake', 'sex' => 'unknown', 'neutering_status' => 'unknown']);
    }

    private function shelterCase(?PetProfile $pet = null): ShelterCase
    {
        return ShelterCase::query()->create([
            'sub_core_key' => 'shelter-rescue',
            'pet_profile_id' => $pet?->id,
            'user_id' => $this->shelterPerson->id,
            'case_type' => 'adoption',
            'title' => 'Adoption enquiry',
            'details' => '<p>Case details</p>',
            'priority' => 'medium',
            'status' => 'open',
            'opened_at' => now(),
        ]);
    }
}
