<?php

namespace Tests\Feature\Attachments;

use App\Core\Accounts\AuditLog;
use App\Core\Accounts\User;
use App\Services\AuthorizationProfile;
use App\Services\ModuleInstallationSynchronizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Plugins\Cases\Notifications\ApesCicCaseUpdatedNotification;
use Plugins\Cases\Notifications\ShelterCaseUpdatedNotification;
use Plugins\Tickets\Models\SupportTicket;
use Plugins\Tickets\Notifications\TicketUpdatedNotification;
use Tests\Concerns\BuildsAttachmentFiles;
use Tests\TestCase;

class AttachmentAuditAndEmailTest extends TestCase
{
    use BuildsAttachmentFiles;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(ModuleInstallationSynchronizer::class)->synchronize();
        Storage::fake('private');
        Notification::fake();
    }

    public function test_uploads_downloads_and_deletions_are_audited(): void
    {
        $owner = User::factory()->create();
        $ticket = $this->cicTicketFor($owner);

        $this->actingAs($owner)
            ->put(route('apes-cic.tickets.update', $ticket), ['documents' => [$this->pdfUpload()]])
            ->assertRedirect();
        $attachment = $ticket->attachments()->firstOrFail();

        $this->actingAs($owner)->get(route('support.attachments.download', $attachment))->assertOk();
        $this->actingAs($owner)->delete(route('support.attachments.destroy', $attachment))->assertRedirect();

        foreach (['support_attachment.uploaded', 'support_attachment.downloaded', 'support_attachment.deleted'] as $event) {
            $log = AuditLog::query()->where('event', $event)->firstOrFail();
            $this->assertSame((int) $owner->id, (int) $log->user_id, $event);
            $this->assertSame((int) $attachment->id, (int) $log->auditable_id, $event);
            $this->assertSame('document', $log->context['kind'], $event);
        }
    }

    public function test_the_save_confirmation_counts_uploaded_files(): void
    {
        $owner = User::factory()->create();

        $this->actingAs($owner)
            ->post(route('apes-cic.tickets.store'), $this->cicTicketPayload([
                'documents' => [$this->pdfUpload('a.pdf'), $this->pdfUpload('b.pdf'), $this->textUpload()],
            ]))
            ->assertSessionHas('status', __('tickets::ui.flash.your_ticket_has_been_saved').' 3 files uploaded.');

        $ticket = SupportTicket::query()->firstOrFail();
        $this->actingAs($owner)
            ->put(route('apes-cic.tickets.update', $ticket), ['documents' => [$this->pdfUpload('c.pdf')]])
            ->assertSessionHas('status', __('tickets::ui.flash.your_update_has_been_saved').' 1 file uploaded.');

        $this->actingAs($owner)
            ->put(route('apes-cic.tickets.update', $ticket), ['message' => 'No files this time.'])
            ->assertSessionHas('status', __('tickets::ui.flash.your_update_has_been_saved'));
    }

    public function test_ticket_emails_mention_new_attachments_without_attaching_files(): void
    {
        $owner = User::factory()->create();
        $staff = User::factory()->protectedRole(AuthorizationProfile::ROLE_STAFF)->create();
        $ticket = $this->cicTicketFor($owner);

        $this->actingAs($staff)
            ->put(route('apes-cic.tickets.update', $ticket), [
                'message' => 'Here are the documents you asked for.',
                'documents' => [$this->pdfUpload('a.pdf'), $this->pdfUpload('b.pdf')],
            ])
            ->assertRedirect();

        Notification::assertSentTo($owner, TicketUpdatedNotification::class, function (TicketUpdatedNotification $notification) use ($owner): bool {
            $mail = $notification->toMail($owner);

            return in_array('2 new attachments – log in to view.', $mail->introLines, true)
                && $mail->attachments === []
                && $mail->rawAttachments === [];
        });
    }

    public function test_case_emails_mention_new_attachments(): void
    {
        $owner = User::factory()->create();
        $staff = User::factory()->protectedRole(AuthorizationProfile::ROLE_STAFF)->create();
        $cicCase = $this->cicCaseFor($owner);
        $shelterCase = $this->shelterCaseFor($owner);

        $this->actingAs($staff)
            ->post(route('apes-cic.cases.updates.store', $cicCase), [
                'body' => 'Evidence received.',
                'documents' => [$this->pdfUpload()],
            ])
            ->assertRedirect();
        $this->actingAs($staff)
            ->post(route('shelter.cases.updates.store', $shelterCase), [
                'body' => 'Vet report attached.',
                'documents' => [$this->pdfUpload(), $this->textUpload()],
            ])
            ->assertRedirect();

        Notification::assertSentTo($owner, ApesCicCaseUpdatedNotification::class, fn ($notification): bool => in_array('1 new attachment – log in to view.', $notification->toMail($owner)->introLines, true));
        Notification::assertSentTo($owner, ShelterCaseUpdatedNotification::class, fn ($notification): bool => in_array('2 new attachments – log in to view.', $notification->toMail($owner)->introLines, true));
    }

    public function test_emails_without_attachments_have_no_attachment_line(): void
    {
        $owner = User::factory()->create();
        $staff = User::factory()->protectedRole(AuthorizationProfile::ROLE_STAFF)->create();
        $ticket = $this->cicTicketFor($owner);

        $this->actingAs($staff)
            ->put(route('apes-cic.tickets.update', $ticket), ['message' => 'Just a note.'])
            ->assertRedirect();

        Notification::assertSentTo($owner, TicketUpdatedNotification::class, function (TicketUpdatedNotification $notification) use ($owner): bool {
            foreach ($notification->toMail($owner)->introLines as $line) {
                if (str_contains((string) $line, 'attachment')) {
                    return false;
                }
            }

            return true;
        });
    }
}
