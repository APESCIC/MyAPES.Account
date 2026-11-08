<?php

namespace Tests\Feature\Attachments;

use App\Core\Accounts\User;
use App\Http\Middleware\RedirectTargetForUploadProgress;
use App\Services\ModuleInstallationSynchronizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Plugins\Tickets\Models\SupportTicket;
use Tests\Concerns\BuildsAttachmentFiles;
use Tests\TestCase;

class UploadComponentTest extends TestCase
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

    public function test_ticket_and_case_forms_use_the_shared_upload_component(): void
    {
        $owner = User::factory()->create();
        $ticket = $this->cicTicketFor($owner);
        $case = $this->cicCaseFor($owner);

        foreach ([
            route('apes-cic.tickets.index'),
            route('apes-cic.tickets.show', $ticket),
            route('apes-cic.cases.index'),
            route('apes-cic.cases.show', $case),
        ] as $url) {
            $this->actingAs($owner)
                ->get($url)
                ->assertOk()
                ->assertSee('data-file-upload-group', false)
                ->assertSee('name="screenshots[]"', false)
                ->assertSee('name="screencasts[]"', false)
                ->assertSee('name="documents[]"', false)
                ->assertSee('data-max-total-bytes="104857600"', false)
                ->assertSee('data-lucide="upload"', false)
                ->assertSeeText('Up to 10 files, 5 MB each. JPEG, PNG, WebP.')
                ->assertSeeText('Up to 3 files, 50 MB each. MP4, WebM.')
                ->assertSeeText('Up to 10 files, 10 MB each. PDF, DOCX, XLSX, ODT, ODS, TXT, CSV.')
                ->assertSeeText('All files together can be up to 100 MB.');
        }
    }

    public function test_upload_requests_get_the_redirect_target_and_keep_the_flash_message(): void
    {
        $owner = User::factory()->create();

        $response = $this->actingAs($owner)
            ->withHeader(RedirectTargetForUploadProgress::HEADER, '1')
            ->post(route('apes-cic.tickets.store'), $this->cicTicketPayload([
                'documents' => [$this->pdfUpload()],
            ]));

        $ticket = SupportTicket::query()->firstOrFail();
        $response->assertOk()->assertExactJson(['redirect' => route('apes-cic.tickets.show', $ticket)]);
        $response->assertSessionHas('status');
    }

    public function test_upload_validation_errors_come_back_as_a_redirect_with_errors(): void
    {
        $owner = User::factory()->create();

        $response = $this->actingAs($owner)
            ->from(route('apes-cic.tickets.index'))
            ->withHeader(RedirectTargetForUploadProgress::HEADER, '1')
            ->post(route('apes-cic.tickets.store'), $this->cicTicketPayload([
                'documents' => [$this->officeUpload('macro.docx', withMacros: true)],
            ]));

        $response->assertOk()->assertExactJson(['redirect' => route('apes-cic.tickets.index')]);
        $response->assertSessionHasErrors(['documents.0' => 'macro.docx contains macros. Save it without macros and try again.']);
        $this->assertSame(0, SupportTicket::query()->count());
    }

    public function test_normal_form_posts_still_redirect(): void
    {
        $owner = User::factory()->create();

        $this->actingAs($owner)
            ->post(route('apes-cic.tickets.store'), $this->cicTicketPayload())
            ->assertRedirect();
    }
}
