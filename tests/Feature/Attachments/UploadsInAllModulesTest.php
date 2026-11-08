<?php

namespace Tests\Feature\Attachments;

use App\Core\Accounts\AuditLog;
use App\Core\Accounts\User;
use App\Core\Extensions\Models\OrganisationModule;
use App\Services\AuthorizationProfile;
use App\Services\ModuleInstallationSynchronizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Plugins\Tickets\Models\SupportTicket;
use Tests\Concerns\BuildsAttachmentFiles;
use Tests\TestCase;

class UploadsInAllModulesTest extends TestCase
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

    /**
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function otherModuleTickets(): array
    {
        return [
            'shelter' => ['shelter.tickets.index', 'shelter.tickets.store', 'rescue'],
            'pet care' => ['petcare.tickets.index', 'petcare.tickets.store', 'appointment'],
        ];
    }

    #[DataProvider('otherModuleTickets')]
    public function test_shelter_and_pet_care_tickets_accept_attachments(string $indexRoute, string $storeRoute, string $area): void
    {
        $owner = User::factory()->create();

        $this->actingAs($owner)
            ->get(route($indexRoute))
            ->assertOk()
            ->assertSee('enctype="multipart/form-data"', false)
            ->assertSee('name="documents[]"', false);

        $this->actingAs($owner)
            ->post(route($storeRoute), [
                'service_area' => $area,
                'subject' => 'Ticket with a document',
                'priority' => 'medium',
                'description' => 'Please see the attached file.',
                'documents' => [$this->pdfUpload('vet-letter.pdf')],
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $ticket = SupportTicket::query()->firstOrFail();
        $this->assertSame(1, $ticket->attachments()->count());

        $this->actingAs($owner)
            ->put(route(str_replace('.store', '.update', $storeRoute), $ticket), [
                'message' => 'One more file.',
                'documents' => [$this->textUpload()],
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();
        $this->assertSame(2, $ticket->attachments()->count());
    }

    public function test_shelter_case_updates_accept_attachments_and_show_them(): void
    {
        $owner = User::factory()->create();
        $case = $this->shelterCaseFor($owner);

        $this->actingAs($owner)
            ->get(route('shelter.cases.show', $case))
            ->assertOk()
            ->assertSee('name="documents[]"', false);

        $this->actingAs($owner)
            ->post(route('shelter.cases.updates.store', $case), [
                'body' => 'Photos of the enclosure.',
                'documents' => [$this->pdfUpload('enclosure-plan.pdf')],
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame(1, $case->attachments()->count());
        $this->actingAs($owner)
            ->get(route('shelter.cases.show', $case))
            ->assertSeeText('enclosure-plan.pdf');
    }

    public function test_admin_turns_uploads_off_for_a_module_with_a_save_confirmation(): void
    {
        $admin = User::factory()->protectedRole(AuthorizationProfile::ROLE_ADMINISTRATOR)->create();
        $owner = User::factory()->create();

        $this->actingAs($admin)
            ->get(route('admin.organisation-modules.index'))
            ->assertOk()
            ->assertSeeText('Ticket and case uploads: On')
            ->assertSeeText('Turn uploads off');

        $this->actingAs($admin)
            ->post(route('admin.organisation-modules.uploads', 'shelter-rescue'), ['uploads_enabled' => 0])
            ->assertRedirect(route('admin.organisation-modules.index'))
            ->assertSessionHas('status', 'Uploads turned off for '.$this->moduleName('shelter-rescue').'.');

        $this->assertFalse((bool) OrganisationModule::query()->where('slug', 'shelter-rescue')->value('uploads_enabled'));
        $this->assertTrue(AuditLog::query()->where('event', 'organisation_module.uploads_disabled')->exists());

        $this->actingAs($owner)
            ->get(route('shelter.tickets.index'))
            ->assertOk()
            ->assertDontSee('name="documents[]"', false);

        $this->actingAs($owner)
            ->post(route('shelter.tickets.store'), [
                'service_area' => 'rescue',
                'subject' => 'Blocked upload',
                'priority' => 'medium',
                'description' => 'Should be refused.',
                'documents' => [$this->pdfUpload()],
            ])
            ->assertSessionHasErrors(['screenshots' => 'Uploads are turned off for this service.']);
        $this->assertSame(0, SupportTicket::query()->count());

        $this->actingAs($owner)
            ->get(route('apes-cic.tickets.index'))
            ->assertSee('name="documents[]"', false);

        $this->actingAs($admin)
            ->post(route('admin.organisation-modules.uploads', 'shelter-rescue'), ['uploads_enabled' => 1])
            ->assertSessionHas('status', 'Uploads turned on for '.$this->moduleName('shelter-rescue').'.');
    }

    public function test_only_module_managers_change_the_upload_switch(): void
    {
        $staff = User::factory()->protectedRole(AuthorizationProfile::ROLE_STAFF)->create();

        $this->actingAs($staff)
            ->post(route('admin.organisation-modules.uploads', 'shelter-rescue'), ['uploads_enabled' => 0])
            ->assertForbidden();
    }

    private function moduleName(string $slug): string
    {
        return app(\App\Core\Extensions\Modules\ModulePackageRegistry::class)->module($slug)->name;
    }
}
