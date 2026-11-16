<?php

namespace App\Http\Controllers\Staff;

use App\Core\Accounts\User;
use App\Http\Controllers\Controller;
use App\Services\AuditLogger;
use App\Services\AuthorizationProfile;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Plugins\Cases\CaseCategoryResolver;
use Plugins\Cases\CasesArea;
use Plugins\Cases\Models\ShelterCase;
use Plugins\Tickets\Models\SupportTicket;
use Plugins\Tickets\TicketCategoryResolver;
use Plugins\Tickets\TicketServiceConfiguration;
use Plugins\Tickets\TicketsArea;

/**
 * Create tickets/cases owned by a public client from the profile hub (#520).
 */
class StaffClientRecordController extends Controller
{
    public function __construct(
        private readonly AuthorizationProfile $profile,
        private readonly AuditLogger $auditLogger,
        private readonly TicketCategoryResolver $ticketCategories,
        private readonly TicketServiceConfiguration $ticketServices,
        private readonly CaseCategoryResolver $caseCategories,
    ) {}

    public function storeTicket(Request $request, User $client): RedirectResponse
    {
        $this->assertPublicClient($client);

        $validated = $request->validate([
            'module' => ['required', Rule::in(['apes-cic', 'shelter-rescue', 'pet-care-clinic'])],
            'subject' => ['required', 'string', 'max:255'],
            'description' => ['required', 'string'],
            'priority' => ['required', Rule::in(['low', 'medium', 'high', 'urgent'])],
            'service_area' => ['nullable', 'string', 'max:64'],
            'sub_category' => ['nullable', 'string', 'max:64'],
        ]);

        $module = $validated['module'];
        $area = TicketsArea::forModule($module);
        Gate::authorize($area->permission('view-all'));

        $ticketService = $this->ticketServices->for($module);
        $serviceArea = $validated['service_area'] ?? ($ticketService->serviceAreas[0] ?? 'general');
        $subCategory = $validated['sub_category'] ?? null;

        if ($area->usesHierarchicalCategories) {
            $request->validate([
                'service_area' => ['required', 'string', 'max:64'],
                'sub_category' => ['required', 'string', 'max:64'],
            ]);
            $categoryFields = $this->ticketCategories->validateSelection(
                $module,
                (string) $validated['service_area'],
                (string) $validated['sub_category'],
                null,
            );
            $serviceArea = $categoryFields['service_area'];
            $subCategory = $categoryFields['sub_category'];
        }

        $ticket = SupportTicket::query()->create([
            'sub_core_key' => $module,
            'user_id' => $client->id,
            'service_area' => $serviceArea,
            'sub_category' => $subCategory,
            'subject' => $validated['subject'],
            'priority' => $validated['priority'],
            'description' => $validated['description'],
            'status' => 'open',
        ]);

        $ticket->messages()->create([
            'user_id' => $request->user()->id,
            'message' => 'Ticket created from the client profile hub.',
            'is_staff_note' => true,
        ]);

        $this->auditLogger->record("{$ticketService->auditPrefix}.created", $request->user(), $ticket, [
            'target_user_id' => $client->id,
            'actor_id' => $request->user()->id,
            'sub_core_key' => $module,
            'module_key' => 'tickets',
            'source_key' => 'profile_hub',
        ]);

        return redirect()
            ->route($area->showRouteName(), $ticket)
            ->with('status', __('tickets::ui.flash.your_ticket_has_been_saved'));
    }

    public function storeCase(Request $request, User $client): RedirectResponse
    {
        $this->assertPublicClient($client);

        $validated = $request->validate([
            'module' => ['required', Rule::in(['apes-cic'])],
            'title' => ['required', 'string', 'max:255'],
            'details' => ['nullable', 'string'],
            'priority' => ['required', Rule::in(['low', 'medium', 'high', 'urgent'])],
            'category' => ['required', 'string', 'max:64'],
            'sub_category' => ['required', 'string', 'max:64'],
        ]);

        $module = $validated['module'];
        $area = CasesArea::forModule($module);
        Gate::authorize($area->permission('view-all'));

        $categoryFields = $this->caseCategories->validateSelection(
            $module,
            $validated['category'],
            $validated['sub_category'],
            null,
        );

        $case = ShelterCase::query()->create([
            'sub_core_key' => $module,
            'user_id' => $client->id,
            'category' => $categoryFields['category'],
            'sub_category' => $categoryFields['sub_category'],
            'affected_website_key' => $categoryFields['affected_website_key'],
            'priority' => $validated['priority'],
            'title' => $validated['title'],
            'details' => $validated['details'] ?? null,
            'status' => 'open',
            'opened_at' => now(),
        ]);

        $this->auditLogger->record("{$area->auditEventPrefix}.created", $request->user(), $case, [
            'target_user_id' => $client->id,
            'actor_id' => $request->user()->id,
            'sub_core_key' => $module,
            'module_key' => 'cases',
            'source_key' => 'profile_hub',
        ]);

        return redirect()
            ->route($area->showRouteName(), $case)
            ->with('status', __('cases::ui.flash.case_created'));
    }

    private function assertPublicClient(User $client): void
    {
        $actor = request()->user();
        abort_unless(
            $actor instanceof User
            && (
                $actor->can(AuthorizationProfile::PERMISSION_CLIENT_VIEW)
                || $actor->can('admin.users.view')
            ),
            403,
        );
        abort_if($this->profile->hasDirectoryProtectedEligibility($client), 404);
    }
}
