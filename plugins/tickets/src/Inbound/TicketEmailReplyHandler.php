<?php

namespace Plugins\Tickets\Inbound;

use App\Core\Accounts\User;
use App\Core\Attachments\SupportAttachment;
use App\Core\Attachments\UploadSettings;
use App\Core\Mail\Inbound\Contracts\InboundReplyHandler;
use App\Core\Mail\Inbound\InboundAttachmentSet;
use App\Core\Mail\Inbound\InboundReplyText;
use App\Core\Mail\Inbound\Verification\InboundReplyEligibility;
use App\Services\AuditLogger;
use App\Services\SupportAttachmentService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Plugins\Tickets\Models\SupportTicket;
use Plugins\Tickets\TicketCategoryResolver;
use Plugins\Tickets\TicketsArea;
use Plugins\Tickets\TicketServiceConfiguration;
use Plugins\Tickets\TicketStakeholderNotifier;

/**
 * Posts email replies on tickets (#582). Replies are always public messages.
 */
final class TicketEmailReplyHandler implements InboundReplyHandler
{
    public function __construct(
        private readonly TicketStakeholderNotifier $notifier,
        private readonly SupportAttachmentService $attachments,
        private readonly TicketCategoryResolver $categories,
        private readonly TicketServiceConfiguration $ticketServices,
        private readonly UploadSettings $uploads,
        private readonly AuditLogger $audit,
        private readonly InboundReplyEligibility $eligibility,
    ) {}

    public function prefix(): string
    {
        return 'T';
    }

    public function plugin(): string
    {
        return 'tickets';
    }

    public function find(int $id): ?Model
    {
        return SupportTicket::query()->find($id);
    }

    public function moduleSlug(Model $record): string
    {
        return (string) $this->ticket($record)->sub_core_key;
    }

    public function canReply(Model $record, User $sender): bool
    {
        $ticket = $this->ticket($record);

        return $this->area($ticket) !== null
            && $this->eligibility->mayReply($sender, (string) $ticket->sub_core_key, 'tickets', $ticket->user_id === null ? null : (int) $ticket->user_id);
    }

    public function isClosed(Model $record): bool
    {
        return $this->ticket($record)->status === 'closed';
    }

    public function attachmentsAllowed(Model $record): bool
    {
        $ticket = $this->ticket($record);
        $area = $this->area($ticket);

        if ($area === null || ! $area->supportsAttachments || ! $this->uploads->enabledFor($area->moduleSlug)) {
            return false;
        }

        if (! $area->usesHierarchicalCategories) {
            return true;
        }

        return is_string($ticket->sub_category)
            && $this->categories->allowsAttachments((string) $ticket->sub_core_key, (string) $ticket->service_area, $ticket->sub_category);
    }

    public function reference(Model $record): string
    {
        return __('tickets::mail.reply_reference', ['id' => $record->getKey()]);
    }

    public function url(Model $record): string
    {
        $ticket = $this->ticket($record);

        return route(TicketsArea::forModule((string) $ticket->sub_core_key)->showRouteName(), $ticket);
    }

    public function post(Model $record, User $sender, string $body, InboundAttachmentSet $attachments): Model
    {
        $ticket = $this->ticket($record);
        $ticketService = $this->ticketServices->for((string) $ticket->sub_core_key);

        [$message, $stored] = DB::transaction(function () use ($ticket, $sender, $body, $attachments): array {
            $message = $ticket->messages()->create([
                'user_id' => $sender->id,
                'message' => InboundReplyText::toHtml($body),
                'is_staff_note' => false,
                'via_email' => true,
            ]);

            $stored = $attachments->isEmpty() ? collect() : $this->attachments->storeFor(
                $ticket,
                (string) $ticket->sub_core_key,
                $sender,
                $attachments->screenshots,
                $attachments->screencasts,
                $attachments->documents,
                SupportAttachment::VISIBILITY_PUBLIC,
            );
            $this->attachments->linkTo($stored, $message);
            $ticket->touch();

            return [$message, $stored];
        });

        $this->notifier->notify($ticket, $sender, 'updated', attachmentCount: $stored->count());
        $this->audit->record("{$ticketService->auditPrefix}.updated", $sender, $ticket, [
            'via' => 'email',
            'sub_core_key' => $ticket->sub_core_key,
            'attachments_added' => $stored->count(),
        ]);

        return $message;
    }

    private function ticket(Model $record): SupportTicket
    {
        if (! $record instanceof SupportTicket) {
            throw new InvalidArgumentException('Expected a support ticket.');
        }

        return $record;
    }

    private function area(SupportTicket $ticket): ?TicketsArea
    {
        try {
            return TicketsArea::forModule((string) $ticket->sub_core_key);
        } catch (InvalidArgumentException) {
            return null;
        }
    }
}
