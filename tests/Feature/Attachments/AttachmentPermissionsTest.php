<?php

namespace Tests\Feature\Attachments;

use App\Core\Accounts\User;
use App\Core\Attachments\SupportAttachment;
use App\Services\AuthorizationProfile;
use App\Services\ModuleInstallationSynchronizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Plugins\Tickets\Notifications\TicketUpdatedNotification;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\BuildsAttachmentFiles;
use Tests\TestCase;

class AttachmentPermissionsTest extends TestCase
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

    public function test_owner_and_staff_with_view_all_download_but_others_cannot(): void
    {
        $owner = User::factory()->create();
        $staff = User::factory()->protectedRole(AuthorizationProfile::ROLE_STAFF)->create();
        $stranger = User::factory()->create();
        $ticket = $this->cicTicketFor($owner);

        $this->actingAs($owner)
            ->put(route('apes-cic.tickets.update', $ticket), ['documents' => [$this->pdfUpload()]])
            ->assertRedirect();
        $attachment = $ticket->attachments()->firstOrFail();

        $this->actingAs($owner)->get(route('support.attachments.download', $attachment))->assertOk();
        $this->actingAs($staff)->get(route('support.attachments.download', $attachment))->assertOk();
        $this->actingAs($stranger)->get(route('support.attachments.download', $attachment))->assertForbidden();
    }

    public function test_staff_side_accounts_cannot_upload_as_clients(): void
    {
        $role = Role::query()->where('guard_name', 'web')->where('name', AuthorizationProfile::ROLE_STUDENT)->firstOrFail();
        $permission = fn (string $name): int => (int) Permission::query()->where('guard_name', 'web')->where('name', $name)->value('id');
        $role->permissions()->detach($permission('apes-cic.tickets.view-all'));
        $role->permissions()->syncWithoutDetaching([
            $permission('apes-cic.tickets.view-own'),
            $permission('apes-cic.tickets.comment-own'),
        ]);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $student = User::factory()->protectedRole(AuthorizationProfile::ROLE_STUDENT)->create();
        $ticket = $this->cicTicketFor($student);

        $this->actingAs($student)
            ->get(route('apes-cic.tickets.show', $ticket))
            ->assertOk();

        $this->actingAs($student)
            ->put(route('apes-cic.tickets.update', $ticket), [
                'message' => 'Trying to attach as a client.',
                'documents' => [$this->pdfUpload()],
            ])
            ->assertForbidden();

        $this->assertSame(0, SupportAttachment::query()->count());
    }

    public function test_staff_only_attachments_are_hidden_from_clients(): void
    {
        $owner = User::factory()->create();
        $staff = User::factory()->protectedRole(AuthorizationProfile::ROLE_STAFF)->create();
        $ticket = $this->cicTicketFor($owner);

        $this->actingAs($staff)
            ->put(route('apes-cic.tickets.update', $ticket), [
                'message' => 'Internal triage notes.',
                'visibility' => 'internal',
                'documents' => [$this->pdfUpload('triage-notes.pdf')],
            ])
            ->assertRedirect();

        $attachment = $ticket->attachments()->firstOrFail();
        $this->assertTrue($attachment->isInternal());

        $this->actingAs($staff)
            ->get(route('apes-cic.tickets.show', $ticket))
            ->assertSeeText('triage-notes.pdf')
            ->assertSeeText('Staff only');
        $this->actingAs($owner)
            ->get(route('apes-cic.tickets.show', $ticket))
            ->assertDontSeeText('triage-notes.pdf');
        $this->actingAs($owner)
            ->get(route('support.attachments.download', $attachment))
            ->assertForbidden();

        Notification::assertNotSentTo($owner, TicketUpdatedNotification::class);
    }

    public function test_internal_case_update_attachments_are_staff_only(): void
    {
        $owner = User::factory()->create();
        $staff = User::factory()->protectedRole(AuthorizationProfile::ROLE_STAFF)->create();
        $case = $this->cicCaseFor($owner);

        $this->actingAs($staff)
            ->post(route('apes-cic.cases.updates.store', $case), [
                'body' => 'Internal evidence review.',
                'visibility' => 'internal',
                'documents' => [$this->pdfUpload('review.pdf')],
            ])
            ->assertRedirect();

        $this->assertTrue($case->attachments()->firstOrFail()->isInternal());
        $this->actingAs($owner)
            ->get(route('apes-cic.cases.show', $case))
            ->assertDontSeeText('review.pdf');
    }

    public function test_only_the_uploader_or_an_admin_deletes_and_deletion_is_soft(): void
    {
        $owner = User::factory()->create();
        $staff = User::factory()->protectedRole(AuthorizationProfile::ROLE_STAFF)->create();
        $admin = User::factory()->protectedRole(AuthorizationProfile::ROLE_ADMINISTRATOR)->create();
        $ticket = $this->cicTicketFor($owner);

        $this->actingAs($owner)
            ->put(route('apes-cic.tickets.update', $ticket), ['documents' => [$this->pdfUpload('a.pdf'), $this->pdfUpload('b.pdf')]])
            ->assertRedirect();
        [$first, $second] = $ticket->attachments()->orderBy('id')->get()->all();

        $this->actingAs($staff)
            ->delete(route('support.attachments.destroy', $first))
            ->assertForbidden();

        $this->actingAs($owner)
            ->from(route('apes-cic.tickets.show', $ticket))
            ->delete(route('support.attachments.destroy', $first))
            ->assertRedirect(route('apes-cic.tickets.show', $ticket))
            ->assertSessionHas('status', 'a.pdf was deleted.');

        $this->actingAs($admin)
            ->delete(route('support.attachments.destroy', $second))
            ->assertRedirect();

        $this->assertSame(0, $ticket->attachments()->count());
        $trashed = SupportAttachment::withTrashed()->orderBy('id')->get();
        $this->assertCount(2, $trashed);
        $this->assertTrue($trashed->every(fn (SupportAttachment $attachment): bool => $attachment->trashed()));
        $this->assertSame((int) $owner->id, (int) $trashed[0]->deleted_by);
        Storage::disk('private')->assertExists($trashed[0]->path);

        $this->actingAs($owner)
            ->get(route('support.attachments.download', $first))
            ->assertNotFound();
    }

    public function test_the_thread_shows_a_delete_button_only_to_the_uploader(): void
    {
        $owner = User::factory()->create();
        $staff = User::factory()->protectedRole(AuthorizationProfile::ROLE_STAFF)->create();
        $ticket = $this->cicTicketFor($owner);

        $this->actingAs($owner)
            ->put(route('apes-cic.tickets.update', $ticket), ['documents' => [$this->pdfUpload('mine.pdf')]])
            ->assertRedirect();
        $attachment = $ticket->attachments()->firstOrFail();

        $this->actingAs($owner)
            ->get(route('apes-cic.tickets.show', $ticket))
            ->assertSee('aria-label="Delete mine.pdf"', false)
            ->assertSee('data-confirm="Delete this attachment?"', false);
        $this->actingAs($staff)
            ->get(route('apes-cic.tickets.show', $ticket))
            ->assertSee(route('support.attachments.download', $attachment), false)
            ->assertDontSee('aria-label="Delete mine.pdf"', false);
    }
}
