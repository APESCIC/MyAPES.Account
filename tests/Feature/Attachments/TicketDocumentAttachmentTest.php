<?php

namespace Tests\Feature\Attachments;

use App\Core\Accounts\User;
use App\Core\Attachments\SupportAttachment;
use App\Services\AuthorizationProfile;
use App\Services\ModuleInstallationSynchronizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Plugins\Tickets\Models\SupportTicket;
use Tests\Concerns\BuildsAttachmentFiles;
use Tests\TestCase;

class TicketDocumentAttachmentTest extends TestCase
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

    public function test_owner_attaches_documents_when_creating_a_ticket(): void
    {
        $owner = User::factory()->create();

        $this->actingAs($owner)
            ->post(route('apes-cic.tickets.store'), $this->cicTicketPayload([
                'documents' => [$this->pdfUpload(), $this->officeUpload('plan.xlsx')],
            ]))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $ticket = SupportTicket::query()->firstOrFail();
        $documents = $ticket->attachments()->get();

        $this->assertCount(2, $documents);
        $this->assertSame([SupportAttachment::KIND_DOCUMENT], $documents->pluck('kind')->unique()->values()->all());
        $this->assertSame(['report.pdf', 'plan.xlsx'], $documents->pluck('original_name')->all());
        foreach ($documents as $document) {
            Storage::disk('private')->assertExists($document->path);
            $this->assertStringNotContainsString('report', $document->path);
            $this->assertMatchesRegularExpression('/\/[A-Za-z0-9]{40}\.(pdf|xlsx)$/', $document->path);
        }
    }

    public function test_reply_documents_are_linked_to_the_message_and_shown_in_the_thread(): void
    {
        $owner = User::factory()->create();
        $ticket = $this->cicTicketFor($owner);

        $this->actingAs($owner)
            ->put(route('apes-cic.tickets.update', $ticket), [
                'message' => 'Here is the signed form.',
                'documents' => [$this->pdfUpload('signed-form.pdf')],
            ])
            ->assertRedirect(route('apes-cic.tickets.show', $ticket));

        $attachment = $ticket->attachments()->firstOrFail();
        $message = $ticket->messages()->where('message', 'Here is the signed form.')->firstOrFail();
        $this->assertTrue($attachment->isContextOf($message));

        $this->actingAs($owner)
            ->get(route('apes-cic.tickets.show', $ticket))
            ->assertOk()
            ->assertSee('data-lucide="file-text"', false)
            ->assertSeeText('signed-form.pdf')
            ->assertSee(route('support.attachments.download', $attachment), false);
    }

    public function test_owner_and_staff_download_ticket_documents(): void
    {
        $owner = User::factory()->create();
        $staff = User::factory()->protectedRole(AuthorizationProfile::ROLE_STAFF)->create();
        $ticket = $this->cicTicketFor($owner);

        $this->actingAs($owner)
            ->put(route('apes-cic.tickets.update', $ticket), [
                'documents' => [$this->textUpload('notes.txt')],
            ])
            ->assertRedirect();

        $attachment = $ticket->attachments()->firstOrFail();

        $this->actingAs($owner)->get(route('support.attachments.download', $attachment))->assertOk();
        $this->actingAs($staff)->get(route('support.attachments.download', $attachment))->assertOk();
    }
}
