<?php

namespace App\Core\Mail\Inbound;

use App\Core\Attachments\AttachmentLimits;
use App\Core\Attachments\DocumentInspector;
use App\Core\Mail\Inbound\Parsing\InboundAttachmentPart;
use Illuminate\Http\UploadedFile;

/**
 * Applies the v0.58 upload rules to email attachments (#584): the same
 * types, per-file sizes, per-kind counts and submission total as the ticket
 * and case forms. Files that pass become temporary uploads; the rest are
 * turned away with a reason.
 */
final class InboundAttachmentSorter
{
    private const IMAGE_EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp'];

    /** @var list<string> */
    private array $temporaryFiles = [];

    public function __construct(private readonly DocumentInspector $documents) {}

    /**
     * @param  list<InboundAttachmentPart>  $parts
     */
    public function sort(array $parts, bool $allowed): InboundAttachmentSet
    {
        if ($parts === []) {
            return InboundAttachmentSet::none();
        }

        if (! $allowed) {
            return InboundAttachmentSet::none()->withRejected(...array_map(
                static fn (InboundAttachmentPart $part): string => __('admin.emails.inbound.attachment_not_allowed_here', ['name' => $part->name]),
                $parts,
            ));
        }

        $kinds = ['screenshots' => [], 'screencasts' => [], 'documents' => []];
        $rejected = [];
        $totalBytes = 0;

        foreach ($parts as $part) {
            $file = $this->temporaryUpload($part);
            $kind = $this->kind($file);
            $problem = $this->problem($file, $kind, count($kinds[$kind ?? 'documents']), $totalBytes);

            if ($kind === null || $problem !== null) {
                $rejected[] = $problem ?? __('attachments.validation.document_type', ['name' => $part->name]);

                continue;
            }

            $kinds[$kind][] = $file;
            $totalBytes += $part->size();
        }

        return new InboundAttachmentSet($kinds['screenshots'], $kinds['screencasts'], $kinds['documents'], $rejected);
    }

    public function cleanUp(): void
    {
        foreach ($this->temporaryFiles as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }

        $this->temporaryFiles = [];
    }

    private function kind(UploadedFile $file): ?string
    {
        $mime = (string) $file->getMimeType();
        $extension = strtolower($file->getClientOriginalExtension());

        return match (true) {
            in_array($mime, AttachmentLimits::SCREENSHOT_MIMES, true) && in_array($extension, self::IMAGE_EXTENSIONS, true) => 'screenshots',
            in_array($mime, AttachmentLimits::SCREENCAST_MIMES, true) => 'screencasts',
            in_array($extension, AttachmentLimits::DOCUMENT_EXTENSIONS, true) => 'documents',
            default => null,
        };
    }

    private function problem(UploadedFile $file, ?string $kind, int $alreadyAccepted, int $totalBytes): ?string
    {
        $name = $file->getClientOriginalName();
        $size = (int) $file->getSize();

        if ($kind === null) {
            return __('attachments.validation.document_type', ['name' => $name]);
        }

        [$maxFiles, $maxKb] = match ($kind) {
            'screenshots' => [AttachmentLimits::SCREENSHOT_MAX_FILES, AttachmentLimits::SCREENSHOT_MAX_KB],
            'screencasts' => [AttachmentLimits::SCREENCAST_MAX_FILES, AttachmentLimits::SCREENCAST_MAX_KB],
            default => [AttachmentLimits::DOCUMENT_MAX_FILES, AttachmentLimits::DOCUMENT_MAX_KB],
        };

        if ($size > $maxKb * 1024) {
            return __('attachments.validation.file_too_large', ['name' => $name, 'size' => AttachmentLimits::megabytes($maxKb)]);
        }

        if ($alreadyAccepted >= $maxFiles) {
            return __('attachments.validation.too_many', ['max' => $maxFiles, 'type' => __('attachments.types.'.$kind)]).' ('.$name.')';
        }

        if ($totalBytes + $size > AttachmentLimits::SUBMISSION_MAX_KB * 1024) {
            return __('attachments.validation.total_too_large', ['size' => AttachmentLimits::megabytes(AttachmentLimits::SUBMISSION_MAX_KB)]).' ('.$name.')';
        }

        if ($kind === 'documents') {
            $problem = $this->documents->problem($file);

            if ($problem !== null) {
                return __($problem, ['name' => $name]);
            }
        }

        return null;
    }

    private function temporaryUpload(InboundAttachmentPart $part): UploadedFile
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'myapes-inbound-');
        file_put_contents($path, $part->content);
        $this->temporaryFiles[] = $path;

        return new UploadedFile($path, $part->name, $part->mimeType, null, true);
    }
}
