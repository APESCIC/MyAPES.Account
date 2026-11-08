<?php

namespace Tests\Feature\Attachments;

use App\Core\Accounts\AuditLog;
use App\Core\Accounts\User;
use App\Core\Attachments\Scanning\ClamdScanner;
use App\Core\Attachments\Scanning\FakeScanner;
use App\Core\Attachments\Scanning\ScannerUnavailable;
use App\Core\Attachments\Scanning\VirusScanner;
use App\Core\Attachments\SupportAttachment;
use App\Jobs\ScanSupportAttachment;
use App\Notifications\AttachmentQuarantinedNotification;
use App\Services\AuthorizationProfile;
use App\Services\ModuleInstallationSynchronizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsAttachmentFiles;
use Tests\TestCase;

class AttachmentScanningTest extends TestCase
{
    use BuildsAttachmentFiles;
    use RefreshDatabase;

    private FakeScanner $scanner;

    protected function setUp(): void
    {
        parent::setUp();
        app(ModuleInstallationSynchronizer::class)->synchronize();
        Storage::fake('private');
        Notification::fake();
        $this->scanner = new FakeScanner;
        $this->app->instance(VirusScanner::class, $this->scanner);
    }

    public function test_uploads_stay_pending_and_cannot_be_downloaded_until_scanned_clean(): void
    {
        Queue::fake();
        $owner = User::factory()->create();
        $ticket = $this->cicTicketFor($owner);

        $this->actingAs($owner)
            ->put(route('apes-cic.tickets.update', $ticket), ['documents' => [$this->pdfUpload('statement.pdf')]])
            ->assertRedirect();

        $attachment = $ticket->attachments()->firstOrFail();
        $this->assertSame(SupportAttachment::SCAN_PENDING, $attachment->scan_status);
        Queue::assertPushed(ScanSupportAttachment::class, fn (ScanSupportAttachment $job): bool => $job->attachmentId === (int) $attachment->id);

        $this->actingAs($owner)->get(route('support.attachments.download', $attachment))->assertForbidden();
        $this->actingAs($owner)
            ->get(route('apes-cic.tickets.show', $ticket))
            ->assertSeeText('Checking for viruses')
            ->assertDontSee('href="'.route('support.attachments.download', $attachment).'"', false);

        (new ScanSupportAttachment((int) $attachment->id))->handle($this->scanner, app(\App\Services\AuditLogger::class));

        $attachment->refresh();
        $this->assertSame(SupportAttachment::SCAN_CLEAN, $attachment->scan_status);
        $this->assertNotNull($attachment->scanned_at);
        $this->actingAs($owner)->get(route('support.attachments.download', $attachment))->assertOk();
    }

    public function test_infected_files_are_quarantined_logged_and_the_uploader_is_told(): void
    {
        $owner = User::factory()->create();
        $staff = User::factory()->protectedRole(AuthorizationProfile::ROLE_STAFF)->create();
        $ticket = $this->cicTicketFor($owner);

        $this->actingAs($owner)
            ->put(route('apes-cic.tickets.update', $ticket), [
                'documents' => [$this->textUpload('infected.txt', FakeScanner::TEST_SIGNATURE)],
            ])
            ->assertRedirect();

        $attachment = $ticket->attachments()->firstOrFail();
        $this->assertSame(SupportAttachment::SCAN_INFECTED, $attachment->scan_status);
        $this->assertStringStartsWith(ScanSupportAttachment::QUARANTINE_DIRECTORY.'/', $attachment->path);
        Storage::disk('private')->assertExists($attachment->path);

        foreach ([$owner, $staff] as $viewer) {
            $this->actingAs($viewer)->get(route('support.attachments.download', $attachment))->assertForbidden();
        }
        $this->actingAs($owner)
            ->get(route('apes-cic.tickets.show', $ticket))
            ->assertSeeText('Blocked: a virus was found');

        $audit = AuditLog::query()->where('event', 'support_attachment.infected')->firstOrFail();
        $this->assertSame('MyAPES-Fake-Test-Signature', $audit->context['signature']);
        Notification::assertSentTo($owner, AttachmentQuarantinedNotification::class);
    }

    public function test_an_unavailable_scanner_leaves_the_file_pending(): void
    {
        $this->scanner->unavailable = true;
        $owner = User::factory()->create();
        $ticket = $this->cicTicketFor($owner);

        $this->actingAs($owner)
            ->put(route('apes-cic.tickets.update', $ticket), ['documents' => [$this->pdfUpload()]])
            ->assertRedirect();

        $attachment = $ticket->attachments()->firstOrFail();
        $this->assertSame(SupportAttachment::SCAN_PENDING, $attachment->scan_status);
        $this->actingAs($owner)->get(route('support.attachments.download', $attachment))->assertForbidden();

        $this->scanner->unavailable = false;
        $this->artisan('attachments:scan-pending')->assertSuccessful();
        $this->assertSame(SupportAttachment::SCAN_CLEAN, $attachment->refresh()->scan_status);
    }

    public function test_without_a_scanner_files_are_not_scanned_and_downloadable(): void
    {
        $this->app->instance(VirusScanner::class, new \App\Core\Attachments\Scanning\DisabledScanner);
        Queue::fake();
        $owner = User::factory()->create();
        $ticket = $this->cicTicketFor($owner);

        $this->actingAs($owner)
            ->put(route('apes-cic.tickets.update', $ticket), ['documents' => [$this->pdfUpload()]])
            ->assertRedirect();

        $attachment = $ticket->attachments()->firstOrFail();
        $this->assertSame(SupportAttachment::SCAN_NOT_SCANNED, $attachment->scan_status);
        Queue::assertNothingPushed();
        $this->actingAs($owner)->get(route('support.attachments.download', $attachment))->assertOk();
        $this->artisan('attachments:scan-pending')->assertFailed();
    }

    public function test_clamd_replies_are_understood(): void
    {
        $clamd = new ClamdScanner('127.0.0.1', 3310);

        $this->assertFalse($clamd->parse('stream: OK')->infected);
        $infected = $clamd->parse('stream: Win.Test.EICAR_HDB-1 FOUND');
        $this->assertTrue($infected->infected);
        $this->assertSame('Win.Test.EICAR_HDB-1', $infected->signature);

        $this->expectException(ScannerUnavailable::class);
        $clamd->parse('INSTREAM size limit exceeded. ERROR');
    }

    public function test_clamd_with_no_host_is_disabled(): void
    {
        $this->assertFalse((new ClamdScanner('', 3310))->enabled());
        $this->assertTrue((new ClamdScanner('clamav.internal', 3310))->enabled());
    }
}
