<?php

namespace Tests\Concerns;

use App\Core\Accounts\User;
use Illuminate\Http\UploadedFile;
use Plugins\Cases\Models\ShelterCase;
use Plugins\Tickets\Models\SupportTicket;

trait BuildsAttachmentFiles
{
    protected function pdfUpload(string $name = 'report.pdf'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, "%PDF-1.4\n1 0 obj <<>> endobj\ntrailer <<>>\n%%EOF\n");
    }

    protected function officeUpload(string $name = 'letter.docx', bool $withMacros = false): UploadedFile
    {
        $entries = $withMacros
            ? '[Content_Types].xmlword/document.xmlword/vbaProject.bin'
            : '[Content_Types].xmlword/document.xml';

        return UploadedFile::fake()->createWithContent($name, "PK\x03\x04".str_repeat("\x14\0", 13).$entries);
    }

    protected function textUpload(string $name = 'notes.txt', string $contents = "Plain notes\n"): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, $contents);
    }

    protected function cicTicketFor(User $owner, array $overrides = []): SupportTicket
    {
        return SupportTicket::query()->create([
            'sub_core_key' => 'apes-cic',
            'user_id' => $owner->id,
            'service_area' => 'web_development',
            'sub_category' => 'website_issue',
            'affected_website_key' => 'apes_org_uk',
            'subject' => 'Attachment ticket',
            'priority' => 'medium',
            'status' => 'open',
            'description' => 'Ticket for attachment tests.',
            ...$overrides,
        ]);
    }

    protected function cicCaseFor(User $owner, array $overrides = []): ShelterCase
    {
        return ShelterCase::query()->create([
            'sub_core_key' => 'apes-cic',
            'user_id' => $owner->id,
            'category' => 'data_access_request',
            'sub_category' => 'copy_of_data',
            'affected_website_key' => 'myapes_account',
            'priority' => 'medium',
            'status' => 'open',
            'title' => 'Attachment case',
            'details' => 'Case for attachment tests.',
            'opened_at' => now(),
            ...$overrides,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function cicTicketPayload(array $overrides = []): array
    {
        return [
            'service_area' => 'web_development',
            'sub_category' => 'website_issue',
            'affected_website_key' => 'apes_org_uk',
            'subject' => 'Upload ticket',
            'priority' => 'medium',
            'description' => 'Ticket with uploads.',
            ...$overrides,
        ];
    }
}
