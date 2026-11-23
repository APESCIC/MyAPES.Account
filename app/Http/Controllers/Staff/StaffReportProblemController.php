<?php

namespace App\Http\Controllers\Staff;

use App\Core\Accounts\AccountClassifier;
use App\Http\Controllers\Controller;
use App\Services\AuditLogger;
use App\Services\AuthorizationProfile;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Plugins\Tickets\Models\SupportTicket;
use Plugins\Tickets\TicketsArea;

/**
 * Staff-only path to open an internal APES CIC ticket about the app (#879).
 *
 * Does not use the public ticket create route (account.public), so staff can
 * report a problem without GitHub access.
 */
class StaffReportProblemController extends Controller
{
    public function __construct(
        private readonly AccountClassifier $classifier,
        private readonly AuthorizationProfile $profile,
        private readonly AuditLogger $auditLogger,
    ) {}

    public function create(Request $request): View|RedirectResponse
    {
        $user = $request->user();
        abort_unless($user !== null && $this->classifier->isStaffSide($user), 403);
        abort_if($this->profile->isAdministrator($user), 404);

        return view('staff.report-problem');
    }

    public function store(Request $request): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user !== null && $this->classifier->isStaffSide($user), 403);
        abort_if($this->profile->isAdministrator($user), 404);

        $validated = $request->validate([
            'subject' => ['required', 'string', 'max:255'],
            'description' => ['required', 'string'],
        ]);

        $module = 'apes-cic';
        $area = TicketsArea::forModule($module);

        $ticket = SupportTicket::query()->create([
            'sub_core_key' => $module,
            'user_id' => $user->id,
            'service_area' => 'web_development',
            'sub_category' => 'login_issue',
            'affected_website_key' => 'myapes_account',
            'subject' => $validated['subject'],
            'priority' => 'medium',
            'description' => $validated['description'],
            'status' => 'open',
        ]);

        $ticket->messages()->create([
            'user_id' => $user->id,
            'message' => 'Ticket opened via Report a problem.',
            'is_staff_note' => true,
        ]);

        $this->auditLogger->record('apes_cic.tickets.created', $user, $ticket, [
            'actor_id' => $user->id,
            'sub_core_key' => $module,
            'module_key' => 'tickets',
            'source_key' => 'report_problem',
        ]);

        return redirect()
            ->route($area->showRouteName(), $ticket)
            ->with('status', __('workspace.dock.report_problem_saved'));
    }
}
