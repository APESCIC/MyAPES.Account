<?php

namespace Tests\Feature;

use App\Core\Accounts\User;
use App\Core\Attachments\SupportAttachment;
use App\Services\AuthorizationProfile;
use App\Services\ModuleInstallationSynchronizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Plugins\Cases\Models\ShelterCase;
use Plugins\Tickets\Models\SupportTicket;
use Tests\TestCase;

class SupportAttachmentPrivateStorageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(ModuleInstallationSynchronizer::class)->synchronize();
        Storage::fake('public');
        Storage::fake('private');
    }

    public function test_new_case_attachments_use_the_private_disk(): void
    {
        $owner = User::factory()->create();

        $this->actingAs($owner)
            ->post(route('apes-cic.cases.store'), [
                'category' => 'data_access_request',
                'sub_category' => 'copy_of_data',
                'affected_website_key' => 'myapes_account',
                'priority' => 'high',
                'title' => 'Private storage SAR',
                'details' => 'Evidence should not sit on the public disk.',
                'screenshots' => [
                    UploadedFile::fake()->image('evidence.jpg', 800, 600),
                ],
            ])
            ->assertRedirect();

        $case = ShelterCase::query()->where('title', 'Private storage SAR')->firstOrFail();
        $attachment = SupportAttachment::query()
            ->where('attachable_id', $case->id)
            ->firstOrFail();

        $this->assertSame('private', $attachment->disk);
        Storage::disk('private')->assertExists($attachment->path);
        Storage::disk('public')->assertMissing($attachment->path);
    }

    public function test_owner_and_staff_can_download_but_strangers_cannot(): void
    {
        $owner = User::factory()->create();
        $staff = User::factory()->protectedRole(AuthorizationProfile::ROLE_STAFF)->create();
        $stranger = User::factory()->create();

        $ticket = SupportTicket::query()->create([
            'sub_core_key' => 'apes-cic',
            'user_id' => $owner->id,
            'service_area' => 'operations_facilities',
            'sub_category' => 'premises',
            'subject' => 'Attachment download',
            'priority' => 'medium',
            'status' => 'open',
            'description' => 'Needs a private attachment.',
        ]);

        Storage::disk('private')->put(
            'support-attachments/apes-cic/2026/1/screenshots/note.jpg',
            'fake-image-bytes',
        );

        $attachment = SupportAttachment::query()->create([
            'attachable_type' => $ticket->getMorphClass(),
            'attachable_id' => $ticket->id,
            'user_id' => $owner->id,
            'disk' => 'private',
            'path' => 'support-attachments/apes-cic/2026/1/screenshots/note.jpg',
            'original_name' => 'note.jpg',
            'mime_type' => 'image/jpeg',
            'size_bytes' => 16,
            'kind' => 'screenshot',
        ]);

        $this->actingAs($owner)
            ->get(route('support.attachments.download', $attachment))
            ->assertOk();

        $this->actingAs($staff)
            ->get(route('support.attachments.download', $attachment))
            ->assertOk();

        $this->actingAs($stranger)
            ->get(route('support.attachments.download', $attachment))
            ->assertForbidden();
    }

    public function test_legacy_public_storage_url_for_support_attachments_is_not_found(): void
    {
        $owner = User::factory()->create();
        $path = 'support-attachments/apes-cic/2026/9/screenshots/old.jpg';

        Storage::disk('public')->put($path, 'should-not-be-served');

        $ticket = SupportTicket::query()->create([
            'sub_core_key' => 'apes-cic',
            'user_id' => $owner->id,
            'service_area' => 'operations_facilities',
            'sub_category' => 'premises',
            'subject' => 'Legacy path',
            'priority' => 'medium',
            'status' => 'open',
            'description' => 'Old public path must 404.',
        ]);

        SupportAttachment::query()->create([
            'attachable_type' => $ticket->getMorphClass(),
            'attachable_id' => $ticket->id,
            'user_id' => $owner->id,
            'disk' => 'public',
            'path' => $path,
            'original_name' => 'old.jpg',
            'mime_type' => 'image/jpeg',
            'size_bytes' => 18,
            'kind' => 'screenshot',
        ]);

        // Selective media / Apache rewrite keep these out of the public tree;
        // Laravel itself must not serve the file contents either (404 or 403).
        $status = $this->get('/storage/'.$path)->getStatusCode();
        $this->assertContains($status, [403, 404]);
    }

    public function test_move_to_private_command_dry_run_and_force_are_reversible(): void
    {
        $owner = User::factory()->create();
        $ticket = SupportTicket::query()->create([
            'sub_core_key' => 'apes-cic',
            'user_id' => $owner->id,
            'service_area' => 'operations_facilities',
            'sub_category' => 'premises',
            'subject' => 'Move command',
            'priority' => 'medium',
            'status' => 'open',
            'description' => 'Migrate me.',
        ]);

        $path = 'support-attachments/apes-cic/2026/2/screenshots/migrate.jpg';
        Storage::disk('public')->put($path, 'migrate-bytes');

        $attachment = SupportAttachment::query()->create([
            'attachable_type' => $ticket->getMorphClass(),
            'attachable_id' => $ticket->id,
            'user_id' => $owner->id,
            'disk' => 'public',
            'path' => $path,
            'original_name' => 'migrate.jpg',
            'mime_type' => 'image/jpeg',
            'size_bytes' => 13,
            'kind' => 'screenshot',
        ]);

        $this->artisan('attachments:move-to-private', ['--dry-run' => true])
            ->assertSuccessful();

        $attachment->refresh();
        $this->assertSame('public', $attachment->disk);
        Storage::disk('public')->assertExists($path);

        $this->artisan('attachments:move-to-private', ['--force' => true])
            ->assertSuccessful();

        $attachment->refresh();
        $this->assertSame('private', $attachment->disk);
        Storage::disk('private')->assertExists($path);
        Storage::disk('public')->assertMissing($path);

        $this->artisan('attachments:move-to-private', ['--force' => true, '--reverse' => true])
            ->assertSuccessful();

        $attachment->refresh();
        $this->assertSame('public', $attachment->disk);
        Storage::disk('public')->assertExists($path);
        Storage::disk('private')->assertMissing($path);
    }

    public function test_move_command_refuses_to_run_without_dry_run_or_force(): void
    {
        $this->artisan('attachments:move-to-private')
            ->assertFailed();
    }
}
