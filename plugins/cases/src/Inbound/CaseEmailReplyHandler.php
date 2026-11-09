<?php

namespace Plugins\Cases\Inbound;

use App\Core\Accounts\User;
use App\Core\Attachments\SupportAttachment;
use App\Core\Mail\Inbound\Contracts\InboundReplyHandler;
use App\Core\Mail\Inbound\InboundAttachmentSet;
use App\Core\Mail\Inbound\InboundReplyText;
use App\Services\AuditLogger;
use App\Services\SupportAttachmentService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;
use Plugins\Cases\CaseAttachmentGate;
use Plugins\Cases\CasesArea;
use Plugins\Cases\CaseUpdateNotifier;
use Plugins\Cases\Models\CaseUpdate;
use Plugins\Cases\Models\ShelterCase;

/**
 * Posts email replies on cases (#582). Replies are always public updates.
 */
final class CaseEmailReplyHandler implements InboundReplyHandler
{
    public function __construct(
        private readonly CaseUpdateNotifier $notifier,
        private readonly SupportAttachmentService $attachments,
        private readonly CaseAttachmentGate $attachmentGate,
        private readonly AuditLogger $audit,
    ) {}

    public function prefix(): string
    {
        return 'C';
    }

    public function plugin(): string
    {
        return 'cases';
    }

    public function find(int $id): ?Model
    {
        return ShelterCase::query()->find($id);
    }

    public function moduleSlug(Model $record): string
    {
        return (string) $this->case($record)->sub_core_key;
    }

    public function canReply(Model $record, User $sender): bool
    {
        $case = $this->case($record);
        $area = $this->area($case);

        if ($area === null || ($area->isShelter() && ! $case->petProfile()->exists())) {
            return false;
        }

        return Gate::forUser($sender)->allows('view', $case)
            && $sender->can($area->permission('comment-own'));
    }

    public function isClosed(Model $record): bool
    {
        return $this->case($record)->status === 'closed';
    }

    public function attachmentsAllowed(Model $record): bool
    {
        $case = $this->case($record);
        $area = $this->area($case);

        return $area !== null
            && $this->attachmentGate->allows($area, (string) $case->sub_core_key, $case->category, $case->sub_category);
    }

    public function reference(Model $record): string
    {
        return __('cases::mail.reply_reference', ['id' => $record->getKey()]);
    }

    public function url(Model $record): string
    {
        $case = $this->case($record);

        return route(CasesArea::forModule((string) $case->sub_core_key)->showRouteName(), $case);
    }

    public function post(Model $record, User $sender, string $body, InboundAttachmentSet $attachments): Model
    {
        $case = $this->case($record);
        $area = CasesArea::forModule((string) $case->sub_core_key);

        [$update, $stored] = DB::transaction(function () use ($case, $sender, $body, $attachments): array {
            $stored = $attachments->isEmpty() ? collect() : $this->attachments->storeFor(
                $case,
                (string) $case->sub_core_key,
                $sender,
                $attachments->screenshots,
                $attachments->screencasts,
                $attachments->documents,
                SupportAttachment::VISIBILITY_PUBLIC,
            );
            $update = $case->updates()->create([
                'user_id' => $sender->id,
                'body' => InboundReplyText::toHtml($body),
                'visibility' => CaseUpdate::VISIBILITY_PUBLIC,
                'via_email' => true,
            ]);
            $this->attachments->linkTo($stored, $update);
            $case->touch();

            return [$update, $stored];
        });

        $this->notifier->notify($case, $sender, CaseUpdate::VISIBILITY_PUBLIC, $stored->count());
        $this->audit->record("{$area->auditEventPrefix}.update_added", $sender, $case, [
            'via' => 'email',
            'sub_core_key' => $case->sub_core_key,
            'visibility' => CaseUpdate::VISIBILITY_PUBLIC,
            'attachments_added' => $stored->count(),
        ]);

        return $update;
    }

    private function case(Model $record): ShelterCase
    {
        if (! $record instanceof ShelterCase) {
            throw new InvalidArgumentException('Expected a case.');
        }

        return $record;
    }

    private function area(ShelterCase $case): ?CasesArea
    {
        try {
            return CasesArea::forModule((string) $case->sub_core_key);
        } catch (InvalidArgumentException) {
            return null;
        }
    }
}
