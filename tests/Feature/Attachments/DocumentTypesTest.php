<?php

namespace Tests\Feature\Attachments;

use App\Core\Accounts\User;
use App\Core\Attachments\DocumentInspector;
use App\Core\Attachments\SupportAttachment;
use App\Services\ModuleInstallationSynchronizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\BuildsAttachmentFiles;
use Tests\TestCase;

class DocumentTypesTest extends TestCase
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
     * @return array<string, array{0: string}>
     */
    public static function allowedDocuments(): array
    {
        return [
            'pdf' => ['report.pdf'],
            'docx' => ['letter.docx'],
            'xlsx' => ['budget.xlsx'],
            'odt' => ['notes.odt'],
            'ods' => ['sheet.ods'],
            'txt' => ['readme.txt'],
            'csv' => ['export.csv'],
        ];
    }

    #[DataProvider('allowedDocuments')]
    public function test_allowed_document_types_are_stored(string $name): void
    {
        $owner = User::factory()->create();
        $ticket = $this->cicTicketFor($owner);

        $this->actingAs($owner)
            ->put(route('apes-cic.tickets.update', $ticket), ['documents' => [$this->documentNamed($name)]])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $attachment = $ticket->attachments()->firstOrFail();
        $this->assertSame(SupportAttachment::KIND_DOCUMENT, $attachment->kind);
        $this->assertSame($name, $attachment->original_name);
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function refusedDocuments(): array
    {
        return [
            'macro-enabled Word' => ['macro.docm', 'is not an allowed document'],
            'macro-enabled Excel' => ['macro.xlsm', 'is not an allowed document'],
            'legacy Word' => ['old.doc', 'is not an allowed document'],
            'legacy Excel' => ['old.xls', 'is not an allowed document'],
            'executable' => ['setup.exe', 'is not an allowed document'],
            'script' => ['run.bat', 'is not an allowed document'],
        ];
    }

    #[DataProvider('refusedDocuments')]
    public function test_refused_extensions_are_rejected(string $name, string $message): void
    {
        $owner = User::factory()->create();
        $ticket = $this->cicTicketFor($owner);

        $response = $this->actingAs($owner)
            ->put(route('apes-cic.tickets.update', $ticket), ['documents' => [$this->textUpload($name, 'content')]]);

        $response->assertSessionHasErrors('documents.0');
        $this->assertSame(0, SupportAttachment::query()->count());
        $this->assertStringContainsString(
            'Each documents file must be one of',
            session('errors')->first('documents.0'),
            "{$name}: {$message}",
        );
    }

    public function test_office_files_with_macros_inside_are_rejected(): void
    {
        $owner = User::factory()->create();
        $ticket = $this->cicTicketFor($owner);

        $this->actingAs($owner)
            ->put(route('apes-cic.tickets.update', $ticket), [
                'documents' => [$this->officeUpload('renamed.docx', withMacros: true)],
            ])
            ->assertSessionHasErrors(['documents.0' => 'renamed.docx contains macros. Save it without macros and try again.']);
    }

    public function test_opendocument_files_with_basic_macros_are_rejected(): void
    {
        $file = UploadedFile::fake()->createWithContent('macro.odt', "PK\x03\x04mimetypeapplication/vnd.oasis.opendocument.textBasic/Standard/Module1.xml");

        $this->assertSame('attachments.validation.document_macros', app(DocumentInspector::class)->problem($file));
    }

    public function test_files_whose_contents_do_not_match_their_extension_are_rejected(): void
    {
        $inspector = app(DocumentInspector::class);

        $this->assertSame('attachments.validation.document_type', $inspector->problem(
            UploadedFile::fake()->createWithContent('fake.pdf', 'this is not a pdf'),
        ));
        $this->assertSame('attachments.validation.document_type', $inspector->problem(
            UploadedFile::fake()->createWithContent('program.pdf', "MZ\x90\0executable"),
        ));
        $this->assertSame('attachments.validation.document_type', $inspector->problem(
            UploadedFile::fake()->createWithContent('not-a-zip.docx', 'plain text pretending'),
        ));
        $this->assertSame('attachments.validation.document_type', $inspector->problem(
            UploadedFile::fake()->createWithContent('binary.txt', "text\0with nulls"),
        ));
        $this->assertNull($inspector->problem($this->pdfUpload()));
    }

    public function test_documents_over_ten_megabytes_or_eleven_files_are_refused(): void
    {
        $owner = User::factory()->create();
        $ticket = $this->cicTicketFor($owner);

        $this->actingAs($owner)
            ->put(route('apes-cic.tickets.update', $ticket), [
                'documents' => [UploadedFile::fake()->create('huge.pdf', 10241, 'application/pdf')],
            ])
            ->assertSessionHasErrors(['documents.0' => 'Each documents file must be 10 MB or smaller.']);

        $this->actingAs($owner)
            ->put(route('apes-cic.tickets.update', $ticket), [
                'documents' => array_map(fn (int $i): UploadedFile => $this->pdfUpload("doc-{$i}.pdf"), range(1, 11)),
            ])
            ->assertSessionHasErrors(['documents' => 'You can attach up to 10 documents at a time.']);

        $this->assertSame(0, SupportAttachment::query()->count());
    }

    private function documentNamed(string $name): UploadedFile
    {
        return match (pathinfo($name, PATHINFO_EXTENSION)) {
            'pdf' => $this->pdfUpload($name),
            'docx', 'xlsx', 'odt', 'ods' => $this->officeUpload($name),
            'csv' => $this->textUpload($name, "name,species\nSpike,bearded dragon\n"),
            default => $this->textUpload($name),
        };
    }
}
