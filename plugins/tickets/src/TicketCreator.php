<?php

namespace Plugins\Tickets;

use App\Core\Accounts\User;
use App\Core\Attachments\AttachmentFeedback;
use App\Core\Attachments\AttachmentLimits;
use App\Core\Attachments\UploadSettings;
use App\Services\AuditLogger;
use App\Services\ClientOwnedRecordResolver;
use App\Services\SupportAttachmentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Plugins\Tickets\Http\Controllers\TicketController;
use Plugins\Tickets\Models\SupportTicket;
use Plugins\Tickets\TicketPushKind;

/**
 * Shared ticket create used by division stores and the public combined form (#664).
 */
final class TicketCreator
{
    public function __construct(
        private readonly TicketServiceConfiguration $ticketServices,
        private readonly TicketCategoryResolver $categories,
        private readonly SupportAttachmentService $attachments,
        private readonly UploadSettings $uploads,
        private readonly TicketStakeholderNotifier $notifier,
        private readonly ClientOwnedRecordResolver $owners,
    ) {}

    public function createFromRequest(
        Request $request,
        User $user,
        string $subCoreKey,
        AuditLogger $auditLogger,
    ): RedirectResponse {
        $area = TicketsArea::forModule($subCoreKey);
        $ticketService = $this->ticketServices->for($subCoreKey);
        $prefix = $area->permissionPrefix();
        $isApesCic = $area->usesHierarchicalCategories;

        $rules = [
            'service_area' => ['required', Rule::in($ticketService->serviceAreas)],
            'subject' => ['required', 'string', 'max:255'],
            'priority' => ['required', Rule::in(TicketController::createPrioritiesFor($user, $prefix))],
            'description' => ['required', 'string'],
            'for_user' => ['nullable', 'integer', 'exists:users,id'],
        ];

        if ($isApesCic) {
            $rules['sub_category'] = ['required', 'string', 'max:64'];
            $rules['affected_website_key'] = ['nullable', 'string', 'max:64'];
        }
        $rules = [...$rules, ...AttachmentLimits::rules()];

        $validated = $request->validate($rules, AttachmentLimits::validationMessages());
        AttachmentLimits::assertSubmissionWithinLimits($request);

        $categoryFields = [
            'service_area' => $validated['service_area'],
            'sub_category' => null,
            'affected_website_key' => null,
        ];

        if ($isApesCic) {
            $categoryFields = $this->categories->validateSelection(
                $subCoreKey,
                $validated['service_area'],
                $validated['sub_category'],
                $validated['affected_website_key'] ?? null,
            );
        }

        $allowsAttachments = $this->attachmentsAllowed(
            $subCoreKey,
            $categoryFields['service_area'],
            $categoryFields['sub_category'],
        );
        if (! $allowsAttachments && AttachmentLimits::requested($request)) {
            throw ValidationException::withMessages([
                'screenshots' => $this->uploads->enabledFor($area->moduleSlug)
                    ? __('tickets::ui.validation.attachments_are_not_available_for_this_subcategory')
                    : __('attachments.validation.uploads_disabled'),
            ]);
        }

        $owner = $this->owners->resolve($request, $user);

        $ticket = SupportTicket::create([
            'service_area' => $categoryFields['service_area'],
            'sub_category' => $categoryFields['sub_category'],
            'affected_website_key' => $categoryFields['affected_website_key'],
            'subject' => $validated['subject'],
            'priority' => $validated['priority'],
            'description' => $validated['description'],
            'sub_core_key' => $subCoreKey,
            'user_id' => $owner->id,
            'status' => 'open',
        ]);

        $ticket->messages()->create([
            'user_id' => $user->id,
            'message' => 'Ticket created.',
            'is_staff_note' => false,
        ]);

        $storedAttachments = collect();
        if ($allowsAttachments) {
            $storedAttachments = $this->attachments->storeFromRequest(
                $ticket,
                $subCoreKey,
                $user,
                $request,
            );
        }

        $this->notifier->notify(
            $ticket,
            $user,
            'created',
            attachmentCount: $storedAttachments->count(),
            pushKind: TicketPushKind::CREATED,
        );
        $auditLogger->record("{$ticketService->auditPrefix}.created", $user, $ticket, [
            'service_area' => $ticket->service_area,
            'sub_category' => $ticket->sub_category,
            'affected_website_key' => $ticket->affected_website_key,
            'priority' => $ticket->priority,
            'sub_core_key' => $ticket->sub_core_key,
            'module_key' => 'tickets',
        ]);

        return redirect()->route($area->showRouteName(), $ticket)
            ->with('status', AttachmentFeedback::status(
                __('tickets::ui.flash.your_ticket_has_been_saved'),
                $storedAttachments->count(),
            ));
    }

    private function attachmentsAllowed(string $subCoreKey, string $serviceArea, ?string $subCategory): bool
    {
        $area = TicketsArea::forModule($subCoreKey);

        if (! $area->supportsAttachments || ! $this->uploads->enabledFor($area->moduleSlug)) {
            return false;
        }

        if (! $area->usesHierarchicalCategories) {
            return true;
        }

        return is_string($subCategory)
            && $this->categories->allowsAttachments($subCoreKey, $serviceArea, $subCategory);
    }

}
