<?php

namespace Tests\Feature;

use App\Core\Accounts\User;
use App\Services\AuthorizationProfile;
use App\Services\ModuleInstallationSynchronizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Plugins\Tickets\Models\SupportTicket;
use Plugins\Tickets\Notifications\TicketUpdatedNotification;
use Tests\TestCase;

class TicketWorkflowLabelsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(ModuleInstallationSynchronizer::class)->synchronize();
    }

    public function test_ticket_list_and_detail_show_title_case_status_and_priority(): void
    {
        $owner = User::factory()->create();
        $staff = User::factory()->protectedRole(AuthorizationProfile::ROLE_STAFF)->create();
        $ticket = $this->ticket($owner);

        $this->actingAs($owner)
            ->get(route('apes-cic.tickets.index'))
            ->assertOk()
            ->assertSee('<span class="status">In Progress</span>', false)
            ->assertSee('<td>Urgent</td>', false)
            ->assertSee('>Medium</option>', false)
            ->assertDontSee('>in_progress<', false)
            ->assertDontSee('>urgent<', false);

        $html = $this->actingAs($staff)
            ->get(route('apes-cic.tickets.show', $ticket))
            ->assertOk()
            ->assertSee('<span class="status">In Progress</span>', false)
            ->assertSee('<dd>Urgent</dd>', false)
            ->assertSee('<option value="in_progress" selected>In Progress</option>', false)
            ->assertSee('<option value="urgent" selected>Urgent</option>', false)
            ->getContent();

        $this->assertDoesNotMatchRegularExpression('/>(open|in_progress|resolved|closed|low|medium|high|urgent)</', $html);
    }

    public function test_ticket_update_email_uses_title_case_labels(): void
    {
        $owner = User::factory()->create();
        $ticket = $this->ticket($owner);

        $mail = (new TicketUpdatedNotification(
            $ticket,
            $owner,
            'updated',
            'apes-cic',
            'apes-cic.tickets.show',
            'APES CIC',
        ))->toMail($owner);

        $this->assertContains(__('mail.ticket_updated.line_status', ['status' => 'In Progress']), $mail->introLines);
        $this->assertContains(__('mail.ticket_updated.line_priority', ['priority' => 'Urgent']), $mail->introLines);
    }

    private function ticket(User $owner): SupportTicket
    {
        return SupportTicket::query()->create([
            'sub_core_key' => 'apes-cic',
            'user_id' => $owner->id,
            'service_area' => 'operations_facilities',
            'sub_category' => 'premises',
            'subject' => 'Label check',
            'priority' => 'urgent',
            'status' => 'in_progress',
            'description' => 'Ticket used to check workflow labels.',
        ]);
    }
}
