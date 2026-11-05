<?php

namespace Tests\Feature;

use App\Core\Accounts\User;
use App\Services\AuthorizationProfile;
use App\Services\ModuleInstallationSynchronizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\DataProvider;
use Plugins\Tickets\Models\SupportTicket;
use Tests\TestCase;

class PublicTicketPriorityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(ModuleInstallationSynchronizer::class)->synchronize();
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function publicTicketIndexRoutes(): array
    {
        return [
            'apes cic' => ['apes-cic.tickets.index'],
            'shelter' => ['shelter.tickets.index'],
            'pet care' => ['petcare.tickets.index'],
        ];
    }

    /**
     * @return array<string, array{0: string, 1: array<string, string>}>
     */
    public static function publicTicketStoreRoutes(): array
    {
        return [
            'apes cic' => ['apes-cic.tickets.store', [
                'service_area' => 'operations_facilities',
                'sub_category' => 'premises',
            ]],
            'shelter' => ['shelter.tickets.store', [
                'service_area' => 'rescue',
            ]],
            'pet care' => ['petcare.tickets.store', [
                'service_area' => 'appointment',
            ]],
        ];
    }

    #[DataProvider('publicTicketIndexRoutes')]
    public function test_public_ticket_create_form_omits_urgent_without_an_explain_path(string $indexRoute): void
    {
        $owner = User::factory()->create();

        $html = $this->actingAs($owner)
            ->get(route($indexRoute))
            ->assertOk()
            ->assertSee('data-ticket-create-form', false)
            ->getContent();

        $select = $this->prioritySelectHtml($html);

        $this->assertSame(1, preg_match_all('/value="low"/', $select));
        $this->assertSame(1, preg_match_all('/value="medium"/', $select));
        $this->assertSame(1, preg_match_all('/value="high"/', $select));
        $this->assertSame(0, preg_match_all('/value="urgent"/', $select));
        $this->assertDoesNotMatchRegularExpression('/title=/', $select);
        $this->assertStringNotContainsString('tooltip', $select);
        $this->assertStringNotContainsString('staff', strtolower($select));
    }

    #[DataProvider('publicTicketIndexRoutes')]
    public function test_staff_ticket_index_has_no_create_form(string $indexRoute): void
    {
        $staff = User::factory()
            ->protectedRole(AuthorizationProfile::ROLE_STAFF)
            ->create();

        $this->actingAs($staff)
            ->get(route($indexRoute))
            ->assertOk()
            ->assertDontSee('data-ticket-create-form', false);
    }

    /**
     * @param  array<string, string>  $categoryFields
     */
    #[DataProvider('publicTicketStoreRoutes')]
    public function test_public_ticket_create_rejects_urgent_and_does_not_persist(
        string $storeRoute,
        array $categoryFields,
    ): void {
        $owner = User::factory()->create();

        $this->actingAs($owner)
            ->from(route(str_replace('.store', '.index', $storeRoute)))
            ->post(route($storeRoute), [
                ...$categoryFields,
                'subject' => 'Public urgent should fail',
                'priority' => 'urgent',
                'description' => 'Public create must not accept Urgent.',
            ])
            ->assertRedirect(route(str_replace('.store', '.index', $storeRoute)))
            ->assertSessionHasErrors('priority');

        $this->assertSame(0, SupportTicket::query()->count());
    }

    /**
     * @param  array<string, string>  $categoryFields
     */
    #[DataProvider('publicTicketStoreRoutes')]
    public function test_staff_cannot_create_tickets_for_themselves(
        string $storeRoute,
        array $categoryFields,
    ): void {
        Notification::fake();
        $staff = User::factory()
            ->protectedRole(AuthorizationProfile::ROLE_STAFF)
            ->create();

        $this->actingAs($staff)
            ->post(route($storeRoute), [
                ...$categoryFields,
                'subject' => 'Staff own ticket',
                'priority' => 'urgent',
                'description' => 'Staff use a personal public account instead.',
            ])
            ->assertForbidden();

        $this->assertSame(0, SupportTicket::query()->count());
    }

    public function test_public_ticket_create_still_accepts_high(): void
    {
        Notification::fake();
        $owner = User::factory()->create();

        $this->actingAs($owner)
            ->post(route('apes-cic.tickets.store'), [
                'service_area' => 'operations_facilities',
                'sub_category' => 'premises',
                'subject' => 'Public high is allowed',
                'priority' => 'high',
                'description' => 'Public create must still accept High.',
            ])
            ->assertRedirect();

        $ticket = SupportTicket::query()
            ->where('subject', 'Public high is allowed')
            ->firstOrFail();
        $this->assertSame('high', $ticket->priority);
    }

    public function test_staff_ticket_update_form_still_includes_urgent(): void
    {
        $owner = User::factory()->create();
        $staff = User::factory()
            ->protectedRole(AuthorizationProfile::ROLE_STAFF)
            ->create();
        $ticket = SupportTicket::query()->create([
            'sub_core_key' => 'apes-cic',
            'user_id' => $owner->id,
            'service_area' => 'operations_facilities',
            'sub_category' => 'premises',
            'subject' => 'Staff can still mark urgent later',
            'priority' => 'medium',
            'status' => 'open',
            'description' => 'Existing ticket for staff priority edit.',
        ]);

        $html = $this->actingAs($staff)
            ->get(route('apes-cic.tickets.show', $ticket))
            ->assertOk()
            ->assertSee('name="priority"', false)
            ->getContent();

        $select = $this->prioritySelectHtml($html);

        $this->assertSame(1, preg_match_all('/value="urgent"/', $select));
    }

    private function prioritySelectHtml(string $html): string
    {
        $this->assertMatchesRegularExpression(
            '/<select[^>]*id="priority"[^>]*>[\s\S]*?<\/select>/',
            $html,
        );
        preg_match('/<select[^>]*id="priority"[^>]*>[\s\S]*?<\/select>/', $html, $matches);

        return $matches[0];
    }
}
