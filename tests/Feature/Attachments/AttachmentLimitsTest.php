<?php

namespace Tests\Feature\Attachments;

use App\Core\Accounts\User;
use App\Core\Attachments\AttachmentLimits;
use App\Core\Attachments\SupportAttachment;
use App\Services\ModuleInstallationSynchronizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Plugins\Tickets\Models\SupportTicket;
use Tests\Concerns\BuildsAttachmentFiles;
use Tests\TestCase;

class AttachmentLimitsTest extends TestCase
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

    public function test_ten_screenshots_of_five_megabytes_are_accepted(): void
    {
        $owner = User::factory()->create();
        $screenshots = array_map(
            fn (int $i): UploadedFile => UploadedFile::fake()->image("shot-{$i}.png", 20, 20)->size(5000),
            range(1, 10),
        );

        $this->actingAs($owner)
            ->post(route('apes-cic.tickets.store'), $this->cicTicketPayload(['screenshots' => $screenshots]))
            ->assertSessionHasNoErrors();

        $this->assertSame(10, SupportTicket::query()->firstOrFail()->attachments()->count());
    }

    public function test_an_eleventh_screenshot_is_refused_with_a_clear_message(): void
    {
        $owner = User::factory()->create();
        $screenshots = array_map(
            fn (int $i): UploadedFile => UploadedFile::fake()->image("shot-{$i}.png", 20, 20),
            range(1, 11),
        );

        $this->actingAs($owner)
            ->post(route('apes-cic.tickets.store'), $this->cicTicketPayload(['screenshots' => $screenshots]))
            ->assertSessionHasErrors(['screenshots' => 'You can attach up to 10 screenshots at a time.']);

        $this->assertSame(0, SupportTicket::query()->count());
    }

    public function test_screenshots_over_five_megabytes_are_refused(): void
    {
        $owner = User::factory()->create();

        $this->actingAs($owner)
            ->post(route('apes-cic.tickets.store'), $this->cicTicketPayload([
                'screenshots' => [UploadedFile::fake()->image('huge.png', 20, 20)->size(5200)],
            ]))
            ->assertSessionHasErrors(['screenshots.0' => 'Each screenshots file must be 5 MB or smaller.']);
    }

    public function test_three_screencasts_are_accepted_and_a_fourth_is_refused(): void
    {
        $owner = User::factory()->create();
        $video = fn (int $i): UploadedFile => UploadedFile::fake()->create("clip-{$i}.mp4", 100, 'video/mp4');

        $this->actingAs($owner)
            ->post(route('apes-cic.tickets.store'), $this->cicTicketPayload([
                'screencasts' => [$video(1), $video(2), $video(3)],
            ]))
            ->assertSessionHasNoErrors();
        $this->assertSame(3, SupportAttachment::query()->where('kind', SupportAttachment::KIND_SCREENCAST)->count());

        $this->actingAs($owner)
            ->post(route('apes-cic.tickets.store'), $this->cicTicketPayload([
                'subject' => 'Too many clips',
                'screencasts' => [$video(4), $video(5), $video(6)],
                'screencast' => $video(7),
            ]))
            ->assertSessionHasErrors(['screencasts' => 'You can attach up to 3 screencasts at a time.']);
    }

    public function test_the_old_single_screencast_field_is_still_accepted(): void
    {
        $owner = User::factory()->create();

        $this->actingAs($owner)
            ->post(route('apes-cic.tickets.store'), $this->cicTicketPayload([
                'screencast' => UploadedFile::fake()->create('legacy.webm', 100, 'video/webm'),
            ]))
            ->assertSessionHasNoErrors();

        $this->assertSame('legacy.webm', SupportAttachment::query()->firstOrFail()->original_name);
    }

    public function test_a_submission_over_one_hundred_megabytes_is_refused(): void
    {
        $owner = User::factory()->create();
        $video = fn (int $i): UploadedFile => UploadedFile::fake()->create("clip-{$i}.mp4", 45000, 'video/mp4');

        $this->actingAs($owner)
            ->post(route('apes-cic.tickets.store'), $this->cicTicketPayload([
                'screencasts' => [$video(1), $video(2)],
                'documents' => [$this->pdfUpload()],
                'screenshots' => array_map(
                    fn (int $i): UploadedFile => UploadedFile::fake()->image("shot-{$i}.png", 20, 20)->size(5000),
                    range(1, 3),
                ),
            ]))
            ->assertSessionHasErrors(['attachments' => 'Files in one submission must add up to 100 MB or less.']);

        $this->assertSame(0, SupportTicket::query()->count());
    }

    public function test_limits_match_the_issue(): void
    {
        $this->assertSame(10, AttachmentLimits::SCREENSHOT_MAX_FILES);
        $this->assertSame(5 * 1024, AttachmentLimits::SCREENSHOT_MAX_KB);
        $this->assertSame(3, AttachmentLimits::SCREENCAST_MAX_FILES);
        $this->assertSame(50 * 1024, AttachmentLimits::SCREENCAST_MAX_KB);
        $this->assertSame(10, AttachmentLimits::DOCUMENT_MAX_FILES);
        $this->assertSame(10 * 1024, AttachmentLimits::DOCUMENT_MAX_KB);
        $this->assertSame(100 * 1024, AttachmentLimits::SUBMISSION_MAX_KB);
    }
}
