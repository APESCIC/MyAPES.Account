<?php

namespace Tests\Feature\Attachments;

use App\Core\Accounts\User;
use App\Core\Attachments\SupportAttachment;
use App\Services\AuthorizationProfile;
use App\Services\ModuleInstallationSynchronizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Plugins\Cases\Models\ShelterCase;
use Tests\Concerns\BuildsAttachmentFiles;
use Tests\TestCase;

class CaseDocumentAttachmentTest extends TestCase
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

    public function test_owner_attaches_documents_when_opening_a_case(): void
    {
        $owner = User::factory()->create();

        $this->actingAs($owner)
            ->post(route('apes-cic.cases.store'), [
                'category' => 'data_access_request',
                'sub_category' => 'copy_of_data',
                'affected_website_key' => 'myapes_account',
                'priority' => 'high',
                'title' => 'SAR with documents',
                'documents' => [$this->pdfUpload('id-check.pdf'), $this->officeUpload('details.odt')],
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $case = ShelterCase::query()->where('title', 'SAR with documents')->firstOrFail();
        $this->assertSame(2, $case->attachments()->where('kind', SupportAttachment::KIND_DOCUMENT)->count());
    }

    public function test_case_update_documents_appear_in_the_timeline(): void
    {
        $owner = User::factory()->create();
        $staff = User::factory()->protectedRole(AuthorizationProfile::ROLE_STAFF)->create();
        $case = $this->cicCaseFor($owner);

        $this->actingAs($owner)
            ->post(route('apes-cic.cases.updates.store', $case), [
                'body' => 'Attaching my proof of address.',
                'documents' => [$this->pdfUpload('proof-of-address.pdf')],
            ])
            ->assertRedirect(route('apes-cic.cases.show', $case))
            ->assertSessionHasNoErrors();

        $attachment = $case->attachments()->firstOrFail();
        $update = $case->updates()->firstOrFail();
        $this->assertTrue($attachment->isContextOf($update));

        foreach ([$owner, $staff] as $viewer) {
            $this->actingAs($viewer)
                ->get(route('apes-cic.cases.show', $case))
                ->assertOk()
                ->assertSeeText('proof-of-address.pdf');
            $this->actingAs($viewer)
                ->get(route('support.attachments.download', $attachment))
                ->assertOk();
        }
    }
}
