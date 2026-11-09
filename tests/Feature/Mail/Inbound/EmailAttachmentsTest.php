<?php

namespace Tests\Feature\Mail\Inbound;

use App\Core\Accounts\User;
use App\Core\Attachments\AttachmentLimits;
use App\Core\Attachments\SupportAttachment;
use App\Core\Mail\Inbound\InboundAttachmentSorter;
use App\Core\Mail\Inbound\InboundEmail;
use App\Core\Mail\Inbound\InboundMailStore;
use App\Core\Mail\Inbound\Parsing\InboundAttachmentPart;
use App\Services\ModuleInstallationSynchronizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Plugins\Cases\Models\CaseUpdate;
use Plugins\Tickets\Models\SupportTicketMessage;
use Tests\TestCase;

/**
 * Email attachments through the v0.58 upload pipeline (#584).
 */
class EmailAttachmentsTest extends TestCase
{
    use InboundMailTestHelpers;
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        app(ModuleInstallationSynchronizer::class)->synchronize();
        Storage::fake('private');
        Notification::fake();
        $this->enableEmailReplies();
        config(['myapes.attachments.scanner' => 'disabled']);
        $this->owner = User::factory()->create(['email' => 'owner@example.org']);
    }

    public function test_allowed_files_are_stored_privately_and_disallowed_types_are_turned_away(): void
    {
        $case = $this->shelterCaseFor($this->owner);

        $email = $this->receive(['TO' => $this->replyAddressFor('C', $case->id, $this->owner), 'BODY' => 'Photos and report attached.']);

        $this->assertSame(InboundEmail::STATUS_POSTED, $email->status, (string) $email->reason);
        $this->assertSame(2, $email->attachments_stored);
        $this->assertSame(1, $email->attachments_rejected);
        $this->assertStringContainsString('setup.exe', (string) $email->detail);

        $update = CaseUpdate::query()->sole();
        $attachments = SupportAttachment::query()->orderBy('original_name')->get();
        $this->assertSame(['boiler.png', 'report.pdf'], $attachments->pluck('original_name')->all());

        foreach ($attachments as $attachment) {
            $this->assertSame('private', $attachment->disk);
            $this->assertSame(SupportAttachment::VISIBILITY_PUBLIC, $attachment->visibility);
            $this->assertSame(SupportAttachment::SCAN_NOT_SCANNED, $attachment->scan_status);
            $this->assertSame($this->owner->id, $attachment->user_id);
            $this->assertTrue($attachment->isContextOf($update));
            Storage::disk('private')->assertExists($attachment->path);
        }

        $this->actingAs($this->owner)
            ->get(route('support.attachments.download', $attachments->first()))
            ->assertOk();
        $this->actingAs(User::factory()->create())
            ->get(route('support.attachments.download', $attachments->first()))
            ->assertForbidden();
    }

    public function test_inline_signature_images_are_not_stored(): void
    {
        $case = $this->shelterCaseFor($this->owner);

        $this->receive(['TO' => $this->replyAddressFor('C', $case->id, $this->owner)]);

        $this->assertFalse(SupportAttachment::query()->where('original_name', 'logo.png')->exists());
    }

    public function test_files_are_turned_away_where_uploads_are_not_allowed(): void
    {
        $ticket = $this->ticketFor($this->owner, ['sub_category' => 'no-uploads-here']);

        $email = $this->receive(['TO' => $this->replyAddressFor('T', $ticket->id, $this->owner), 'BODY' => 'Text still arrives.']);

        $this->assertSame(InboundEmail::STATUS_POSTED, $email->status);
        $this->assertSame(0, $email->attachments_stored);
        $this->assertSame(3, $email->attachments_rejected);
        $this->assertSame(0, SupportAttachment::query()->count());
        $this->assertStringContainsString('Text still arrives.', (string) SupportTicketMessage::query()->sole()->message);
    }

    public function test_per_kind_counts_and_sizes_follow_the_v058_limits(): void
    {
        $sorter = app(InboundAttachmentSorter::class);
        $parts = [];

        for ($i = 1; $i <= AttachmentLimits::DOCUMENT_MAX_FILES + 1; $i++) {
            $parts[] = new InboundAttachmentPart("note-{$i}.txt", 'text/plain', "Note {$i}\n");
        }
        $parts[] = new InboundAttachmentPart('huge.txt', 'text/plain', str_repeat('a', AttachmentLimits::DOCUMENT_MAX_KB * 1024 + 1));

        $set = $sorter->sort($parts, true);

        $this->assertCount(AttachmentLimits::DOCUMENT_MAX_FILES, $set->documents);
        $this->assertCount(2, $set->rejected);
        $this->assertStringContainsString('note-11.txt', $set->rejected[0]);
        $this->assertStringContainsString('huge.txt', $set->rejected[1]);
        $sorter->cleanUp();
    }

    public function test_an_attachments_only_reply_is_posted(): void
    {
        $case = $this->shelterCaseFor($this->owner);

        $email = $this->receive(['TO' => $this->replyAddressFor('C', $case->id, $this->owner), 'BODY' => '']);

        $this->assertSame(InboundEmail::STATUS_POSTED, $email->status);
        $this->assertStringContainsString(__('mail.reply_by_email.attachments_only'), (string) CaseUpdate::query()->sole()->body);
    }

    /**
     * @param  array<string, string>  $values
     */
    private function receive(array $values): InboundEmail
    {
        $email = app(InboundMailStore::class)->store($this->fixture('with-attachments', $values));
        $this->assertNotNull($email);

        return $email->fresh();
    }
}
