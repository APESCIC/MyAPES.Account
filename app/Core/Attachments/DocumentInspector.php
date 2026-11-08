<?php

namespace App\Core\Attachments;

use Illuminate\Http\UploadedFile;

/**
 * Content checks for document uploads (#543).
 *
 * Extension and detected MIME type must agree, the file must start with the
 * signature of its format, and Office/OpenDocument packages must not carry
 * macros. Zip entry names are stored uncompressed, so a byte search finds
 * macro parts without needing the zip extension.
 */
final class DocumentInspector
{
    /** @var array<string, list<string>> */
    public const MIME_TYPES = [
        'pdf' => ['application/pdf'],
        'docx' => [
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/zip',
            'application/octet-stream',
        ],
        'xlsx' => [
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'application/zip',
            'application/octet-stream',
        ],
        'odt' => ['application/vnd.oasis.opendocument.text', 'application/zip'],
        'ods' => ['application/vnd.oasis.opendocument.spreadsheet', 'application/zip'],
        'txt' => ['text/plain'],
        'csv' => ['text/csv', 'text/plain', 'application/csv'],
    ];

    private const ZIP_PACKAGES = ['docx', 'xlsx', 'odt', 'ods'];

    private const MACRO_MARKERS = [
        'vbaProject.bin',
        'vbaData.xml',
        'macroEnabled',
        'Basic/',
        'Scripts/',
    ];

    /**
     * @return string|null  A translation key describing the problem, or null when the file is acceptable.
     */
    public function problem(UploadedFile $file): ?string
    {
        $extension = strtolower($file->getClientOriginalExtension());

        if (! array_key_exists($extension, self::MIME_TYPES)) {
            return 'attachments.validation.document_type';
        }

        $mime = $file->getMimeType();
        if (! is_string($mime) || ! in_array($mime, self::MIME_TYPES[$extension], true)) {
            return 'attachments.validation.document_type';
        }

        $contents = $this->contents($file);
        if ($contents === null) {
            return 'attachments.validation.document_unreadable';
        }

        if (str_starts_with($contents, 'MZ') || str_starts_with($contents, "\x7FELF")) {
            return 'attachments.validation.document_type';
        }

        if ($extension === 'pdf' && ! str_starts_with($contents, '%PDF-')) {
            return 'attachments.validation.document_type';
        }

        if (in_array($extension, self::ZIP_PACKAGES, true)) {
            if (! str_starts_with($contents, "PK\x03\x04")) {
                return 'attachments.validation.document_type';
            }

            foreach (self::MACRO_MARKERS as $marker) {
                if (str_contains($contents, $marker)) {
                    return 'attachments.validation.document_macros';
                }
            }
        }

        if (in_array($extension, ['txt', 'csv'], true) && str_contains($contents, "\0")) {
            return 'attachments.validation.document_type';
        }

        return null;
    }

    private function contents(UploadedFile $file): ?string
    {
        $path = $file->getRealPath();
        if (! is_string($path) || $path === '' || ! is_readable($path)) {
            return null;
        }

        $contents = file_get_contents($path);

        return is_string($contents) ? $contents : null;
    }
}
