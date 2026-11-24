<?php

namespace Tests\Feature;

use App\Core\Accounts\User;
use App\Core\Search\RecordRanker;
use App\Core\Search\RecordSearch;
use App\Services\AuthorizationProfile;
use App\Services\ModuleInstallationSynchronizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Plugins\Tickets\Models\SupportTicket;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Record search scoping, ranking and JSON endpoint (#532).
 */
class RecordSearchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(ModuleInstallationSynchronizer::class)->synchronize();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function test_guests_cannot_use_the_record_search_endpoint(): void
    {
        $this->getJson(route('workspace.search.records', ['q' => 'open']))
            ->assertUnauthorized();
    }

    public function test_short_queries_return_empty_groups(): void
    {
        $staff = User::factory()
            ->protectedRole(AuthorizationProfile::ROLE_STAFF)
            ->create();

        $this->actingAs($staff)
            ->getJson(route('workspace.search.records', ['q' => 'a']))
            ->assertOk()
            ->assertJson(['groups' => []]);
    }

    public function test_exact_reference_ranks_before_partial_subject(): void
    {
        $this->assertSame(
            RecordRanker::EXACT_IDENTIFIER,
            RecordRanker::bestScore('#42', reference: '#42', partialFields: ['Other ticket']),
        );
        $this->assertSame(
            RecordRanker::CONTAINS,
            RecordRanker::bestScore('chip', reference: '#99', partialFields: ['Microchip check']),
        );
        $this->assertTrue(
            RecordRanker::bestScore('#42', reference: '#42', partialFields: ['x'])
            < RecordRanker::bestScore('chip', reference: '#99', partialFields: ['Microchip check']),
        );
    }

    public function test_status_filter_excludes_non_matching_records(): void
    {
        $this->assertNull(
            RecordRanker::bestScore(
                'open',
                reference: '#1',
                partialFields: ['open ticket'],
                recordStatus: 'closed',
                statusFilter: 'open',
            ),
        );
        $this->assertSame(
            RecordRanker::EXACT_IDENTIFIER,
            RecordRanker::bestScore(
                '#1',
                reference: '#1',
                partialFields: ['open ticket'],
                recordStatus: 'open',
                statusFilter: 'open',
            ),
        );
    }

    public function test_ticket_search_respects_view_own_scope(): void
    {
        $owner = User::factory()->create();
        $stranger = User::factory()->create();

        $own = SupportTicket::query()->create([
            'sub_core_key' => 'apes-cic',
            'user_id' => $owner->id,
            'subject' => 'Alpha scoped ticket',
            'description' => 'Owned ticket body',
            'status' => 'open',
            'priority' => 'medium',
            'service_area' => 'general',
        ]);
        SupportTicket::query()->create([
            'sub_core_key' => 'apes-cic',
            'user_id' => $stranger->id,
            'subject' => 'Alpha foreign ticket',
            'description' => 'Foreign ticket body',
            'status' => 'open',
            'priority' => 'medium',
            'service_area' => 'general',
        ]);

        $groups = app(RecordSearch::class)->search($owner->fresh(), 'Alpha');
        $ticketGroup = collect($groups['groups'])->firstWhere('type', 'tickets');
        $this->assertNotNull($ticketGroup);
        $ids = collect($ticketGroup['hits'])->pluck('id')->all();
        $this->assertSame([(string) $own->id], $ids);
    }

    public function test_record_search_returns_at_most_five_hits_per_type(): void
    {
        $owner = User::factory()->create();

        for ($i = 1; $i <= 8; $i++) {
            SupportTicket::query()->create([
                'sub_core_key' => 'apes-cic',
                'user_id' => $owner->id,
                'subject' => "Palette bulk ticket {$i}",
                'description' => "Bulk ticket body {$i}",
                'status' => 'open',
                'priority' => 'medium',
                'service_area' => 'general',
            ]);
        }

        $groups = app(RecordSearch::class)->search($owner->fresh(), 'Palette bulk');
        $ticketGroup = collect($groups['groups'])->firstWhere('type', 'tickets');
        $this->assertNotNull($ticketGroup);
        $this->assertCount(5, $ticketGroup['hits']);
    }

    public function test_authenticated_staff_can_call_the_json_endpoint(): void
    {
        $staff = User::factory()
            ->protectedRole(AuthorizationProfile::ROLE_STAFF)
            ->create();

        SupportTicket::query()->create([
            'sub_core_key' => 'apes-cic',
            'user_id' => $staff->id,
            'subject' => 'Endpoint visible ticket',
            'description' => 'Endpoint ticket body',
            'status' => 'open',
            'priority' => 'medium',
            'service_area' => 'general',
        ]);

        $this->actingAs($staff)
            ->getJson(route('workspace.search.records', ['q' => 'Endpoint visible']))
            ->assertOk()
            ->assertJsonStructure(['groups']);
    }

    public function test_people_group_matches_postcode_and_date_of_birth(): void
    {
        $staff = User::factory()
            ->protectedRole(AuthorizationProfile::ROLE_STAFF)
            ->create();
        $client = User::factory()->create(['name' => 'Postcode Person']);
        $client->profile()->create([
            'first_name' => 'Postcode',
            'last_name' => 'Person',
            'date_of_birth' => '1990-03-14',
            'postcode' => 'B1 1AA',
            'address_line_1' => '10 High Street',
            'town_city' => 'Birmingham',
            'mobile_number' => '+447700900999',
        ]);

        $this->actingAs($staff);

        $this->assertTrue(
            $staff->fresh()->can(AuthorizationProfile::PERMISSION_CLIENT_SEARCH),
            'staff must have staff.clients.search for people search',
        );

        $byPostcode = app(RecordSearch::class)->search($staff->fresh(), 'B11AA', perType: 25);
        $people = collect($byPostcode['groups'])->firstWhere('type', 'users');
        $this->assertNotNull($people, 'expected people hits for postcode: '.json_encode($byPostcode));
        $this->assertSame('People', $people['label']);
        $this->assertTrue(collect($people['hits'])->contains(fn (array $hit): bool => $hit['id'] === (string) $client->id));

        $byDob = app(RecordSearch::class)->search($staff->fresh(), '14/03/1990', perType: 25);
        $peopleByDob = collect($byDob['groups'])->firstWhere('type', 'users');
        $this->assertNotNull($peopleByDob);
        $this->assertTrue(collect($peopleByDob['hits'])->contains(fn (array $hit): bool => $hit['id'] === (string) $client->id));

        $this->getJson(route('workspace.search.records', ['q' => 'B11AA']))
            ->assertOk()
            ->assertJsonFragment(['type' => 'users', 'label' => 'People']);
    }

    public function test_people_search_requires_client_search_permission(): void
    {
        $public = User::factory()
            ->protectedRole(AuthorizationProfile::ROLE_SERVICE_USER)
            ->create();
        $client = User::factory()->create(['name' => 'Hidden From Public']);
        $client->profile()->create([
            'first_name' => 'Hidden',
            'last_name' => 'From Public',
            'postcode' => 'E1 6AN',
        ]);

        $this->actingAs($public);

        $groups = app(RecordSearch::class)->search($public->fresh(), 'Hidden From');
        $this->assertNull(collect($groups['groups'])->firstWhere('type', 'users'));

        $this->getJson(route('workspace.search.records', ['q' => 'Hidden From']))
            ->assertOk()
            ->assertJsonMissing(['type' => 'users']);
    }
}
