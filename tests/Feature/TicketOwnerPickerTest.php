<?php

namespace Tests\Feature;

use App\Core\Accounts\User;
use App\Services\AuthorizationProfile;
use App\Services\ModuleInstallationSynchronizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Plugins\Tickets\Models\SupportTicket;
use Tests\TestCase;

class TicketOwnerPickerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(ModuleInstallationSynchronizer::class)->synchronize();
    }

    public function test_ticket_detail_no_longer_lists_every_user(): void
    {
        [$staff, $ticket] = $this->staffAndTicket();
        $others = User::factory()->count(5)->sequence(
            fn ($sequence) => [
                'name' => 'Listed Person '.$sequence->index,
                'email' => 'listed-person-'.$sequence->index.'@example.test',
                'username' => 'listed_person_'.$sequence->index,
            ],
        )->create();

        $response = $this->actingAs($staff)
            ->get(route('apes-cic.tickets.show', $ticket))
            ->assertOk()
            ->assertSee('data-owner-picker', false)
            ->assertSeeText('Current owner: '.$ticket->user->name);

        foreach ($others as $other) {
            $response->assertDontSee($other->email);
        }
    }

    public function test_search_without_javascript_shows_up_to_ten_matches(): void
    {
        [$staff, $ticket] = $this->staffAndTicket();
        User::factory()->count(12)->sequence(
            fn ($sequence) => [
                'name' => 'Robin Match '.$sequence->index,
                'email' => 'robin-match-'.$sequence->index.'@example.test',
                'username' => 'robin_match_'.$sequence->index,
            ],
        )->create();
        User::factory()->create([
            'name' => 'Unrelated Person',
            'email' => 'unrelated-person@example.test',
            'username' => 'unrelated_person',
        ]);

        $html = $this->actingAs($staff)
            ->get(route('apes-cic.tickets.show', [$ticket, 'owner_q' => 'robin']))
            ->assertOk()
            ->assertDontSeeText('Unrelated Person')
            ->getContent();

        $this->assertSame(10, substr_count((string) $html, '>Robin Match '));
    }

    public function test_matching_owner_is_excluded_before_the_ten_result_limit(): void
    {
        [$staff, $ticket] = $this->staffAndTicket([
            'name' => 'Robin Ticket Owner',
            'email' => 'robin-ticket-owner@example.test',
            'username' => 'robin_ticket_owner',
        ]);
        User::factory()->count(12)->sequence(
            fn ($sequence) => [
                'name' => 'Robin Match '.$sequence->index,
                'email' => 'robin-limit-'.$sequence->index.'@example.test',
                'username' => 'robin_limit_'.$sequence->index,
            ],
        )->create();

        $html = $this->actingAs($staff)
            ->get(route('apes-cic.tickets.show', [$ticket, 'owner_q' => 'robin']))
            ->assertOk()
            ->assertSeeText('Current owner: Robin Ticket Owner')
            ->getContent();

        $this->assertSame(10, substr_count((string) $html, '>Robin Match '));
        $this->assertSame(0, preg_match_all('/<option[^>]*>Robin Ticket Owner\b/', (string) $html));

        $json = $this->getJson(route('apes-cic.tickets.owner-candidates', [$ticket, 'q' => 'robin']))
            ->assertOk()
            ->json('data');

        $this->assertCount(10, $json);
        $this->assertFalse(
            collect($json)->contains(
                static fn (array $row): bool => str_contains($row['label'], 'Robin Ticket Owner'),
            ),
        );
    }

    public function test_json_search_needs_two_characters_and_skips_suspended_accounts(): void
    {
        [$staff, $ticket] = $this->staffAndTicket();
        $active = User::factory()->create([
            'name' => 'Avery Active',
            'email' => 'avery-active@example.test',
            'username' => 'avery_active',
        ]);
        User::factory()->create([
            'name' => 'Avery Suspended',
            'email' => 'avery-suspended@example.test',
            'username' => 'avery_suspended',
            'suspended_at' => now(),
        ]);

        $this->actingAs($staff)
            ->getJson(route('apes-cic.tickets.owner-candidates', [$ticket, 'q' => 'a']))
            ->assertOk()
            ->assertExactJson(['data' => []]);

        $this->getJson(route('apes-cic.tickets.owner-candidates', [$ticket, 'q' => 'avery']))
            ->assertOk()
            ->assertExactJson(['data' => [['id' => $active->id, 'label' => 'Avery Active ('.$active->email.')']]]);

        $this->getJson(route('apes-cic.tickets.owner-candidates', [$ticket, 'q' => $ticket->user->email]))
            ->assertOk()
            ->assertExactJson(['data' => []]);
    }

    public function test_people_who_cannot_assign_cannot_search_owners(): void
    {
        [, $ticket] = $this->staffAndTicket();

        $this->actingAs($ticket->user)
            ->getJson(route('apes-cic.tickets.owner-candidates', [$ticket, 'q' => 'qa']))
            ->assertForbidden();
    }

    public function test_saving_rejects_an_owner_who_cannot_use_the_service(): void
    {
        [$staff, $ticket] = $this->staffAndTicket();
        $suspended = User::factory()->create([
            'name' => 'Suspended Candidate',
            'email' => 'suspended-candidate@example.test',
            'username' => 'suspended_candidate',
            'suspended_at' => now(),
        ]);

        $this->actingAs($staff)
            ->from(route('apes-cic.tickets.show', $ticket))
            ->put(route('apes-cic.tickets.update', $ticket), ['user_id' => $suspended->id])
            ->assertSessionHasErrors('user_id');

        $this->assertNotSame($suspended->id, $ticket->fresh()->user_id);
    }

    /**
     * @param  array{name?: string, email?: string, username?: string}  $ownerAttributes
     * @return array{0: User, 1: SupportTicket}
     */
    private function staffAndTicket(array $ownerAttributes = []): array
    {
        $staff = User::factory()->protectedRole(AuthorizationProfile::ROLE_STAFF)->create([
            'name' => 'Staff Tester',
            'email' => 'staff-tester@example.test',
            'username' => 'staff_tester',
        ]);
        $ticket = SupportTicket::query()->create([
            'sub_core_key' => 'apes-cic',
            'user_id' => User::factory()->create(array_merge([
                'name' => 'Ticket Owner Fixture',
                'email' => 'ticket-owner-fixture@example.test',
                'username' => 'ticket_owner_fixture',
            ], $ownerAttributes))->id,
            'service_area' => 'operations_facilities',
            'sub_category' => 'premises',
            'subject' => 'Owner picker',
            'priority' => 'medium',
            'status' => 'open',
            'description' => 'Owner picker test.',
        ]);

        return [$staff, $ticket->load('user')];
    }
}
