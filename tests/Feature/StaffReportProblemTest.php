<?php

namespace Tests\Feature;

use App\Core\Accounts\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Plugins\Tickets\Models\SupportTicket;
use Tests\TestCase;

class StaffReportProblemTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_and_public_cannot_open_report_problem(): void
    {
        $this->get(route('staff.report-problem.create'))->assertRedirect();

        $public = User::factory()->accessLevel(User::ROLE_SERVICE_USER)->create();

        $this->actingAs($public)
            ->get(route('staff.report-problem.create'))
            ->assertForbidden();
    }

    public function test_admin_does_not_use_report_problem_form(): void
    {
        $admin = User::factory()->accessLevel(User::ROLE_ADMIN)->create();

        $this->actingAs($admin)
            ->get(route('staff.report-problem.create'))
            ->assertNotFound();
    }

    public function test_staff_can_create_an_internal_apes_cic_ticket(): void
    {
        $staff = User::factory()->accessLevel(User::ROLE_STAFF)->create();

        $this->actingAs($staff)
            ->get(route('staff.report-problem.create'))
            ->assertOk()
            ->assertSeeText('Report a problem');

        $response = $this->actingAs($staff)->post(route('staff.report-problem.store'), [
            'subject' => 'Broken button on dashboard',
            'description' => 'The notifications bell does nothing when clicked.',
        ]);

        $ticket = SupportTicket::query()->first();
        $this->assertNotNull($ticket);
        $this->assertSame('apes-cic', $ticket->sub_core_key);
        $this->assertSame($staff->id, $ticket->user_id);
        $this->assertSame('web_development', $ticket->service_area);
        $this->assertSame('login_issue', $ticket->sub_category);
        $this->assertSame('myapes_account', $ticket->affected_website_key);

        $response->assertRedirect(route('apes-cic.tickets.show', $ticket));
    }
}
