<?php

namespace App\Core\Attachments;

use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

/**
 * Upload limits shared by every ticket and case form (#546).
 *
 * The single `screencast` field is still accepted for one release alongside
 * `screencasts[]`; both count towards the screencast limit.
 */
final class AttachmentLimits
{
    public const SCREENSHOT_MAX_FILES = 10;

    public const SCREENSHOT_MAX_KB = 5120;

    public const SCREENCAST_MAX_FILES = 3;

    public const SCREENCAST_MAX_KB = 51200;

    public const DOCUMENT_MAX_FILES = 10;

    public const DOCUMENT_MAX_KB = 10240;

    public const SUBMISSION_MAX_KB = 102400;

    public const SCREENSHOT_MIMES = ['image/jpeg', 'image/png', 'image/webp'];

    public const SCREENCAST_MIMES = ['video/mp4', 'video/webm'];

    public const DOCUMENT_EXTENSIONS = ['pdf', 'docx', 'xlsx', 'odt', 'ods', 'txt', 'csv'];

    public const FIELDS = ['screenshots', 'screencasts', 'screencast', 'documents'];

    public static function requested(Request $request): bool
    {
        foreach (self::FIELDS as $field) {
            if ($request->hasFile($field)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, list<string>>
     */
    public static function rules(): array
    {
        $videoTypes = implode(',', self::SCREENCAST_MIMES);

        return [
            'screenshots' => ['nullable', 'array', 'max:'.self::SCREENSHOT_MAX_FILES],
            'screenshots.*' => ['image', 'mimes:jpg,jpeg,png,webp', 'max:'.self::SCREENSHOT_MAX_KB],
            'screencasts' => ['nullable', 'array', 'max:'.self::SCREENCAST_MAX_FILES],
            'screencasts.*' => ['file', 'mimetypes:'.$videoTypes, 'max:'.self::SCREENCAST_MAX_KB],
            'screencast' => ['nullable', 'file', 'mimetypes:'.$videoTypes, 'max:'.self::SCREENCAST_MAX_KB],
            'documents' => ['nullable', 'array', 'max:'.self::DOCUMENT_MAX_FILES],
            'documents.*' => ['file', 'extensions:'.implode(',', self::DOCUMENT_EXTENSIONS), 'max:'.self::DOCUMENT_MAX_KB],
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function messages(): array
    {
        $screenshots = __('attachments.types.screenshots');
        $screencasts = __('attachments.types.screencasts');
        $documents = __('attachments.types.documents');

        return [
            'screenshots.max' => __('attachments.validation.too_many', ['max' => self::SCREENSHOT_MAX_FILES, 'type' => $screenshots]),
            'screenshots.*.max' => __('attachments.validation.each_too_large', ['type' => $screenshots, 'size' => self::megabytes(self::SCREENSHOT_MAX_KB)]),
            'screenshots.*.image' => __('attachments.validation.wrong_type', ['type' => $screenshots, 'types' => 'JPEG, PNG, WebP']),
            'screenshots.*.mimes' => __('attachments.validation.wrong_type', ['type' => $screenshots, 'types' => 'JPEG, PNG, WebP']),
            'screencasts.max' => __('attachments.validation.too_many', ['max' => self::SCREENCAST_MAX_FILES, 'type' => $screencasts]),
            'screencasts.*.max' => __('attachments.validation.each_too_large', ['type' => $screencasts, 'size' => self::megabytes(self::SCREENCAST_MAX_KB)]),
            'screencasts.*.mimetypes' => __('attachments.validation.wrong_type', ['type' => $screencasts, 'types' => 'MP4, WebM']),
            'screencast.max' => __('attachments.validation.each_too_large', ['type' => $screencasts, 'size' => self::megabytes(self::SCREENCAST_MAX_KB)]),
            'screencast.mimetypes' => __('attachments.validation.wrong_type', ['type' => $screencasts, 'types' => 'MP4, WebM']),
            'documents.max' => __('attachments.validation.too_many', ['max' => self::DOCUMENT_MAX_FILES, 'type' => $documents]),
            'documents.*.max' => __('attachments.validation.each_too_large', ['type' => $documents, 'size' => self::megabytes(self::DOCUMENT_MAX_KB)]),
            'documents.*.extensions' => __('attachments.validation.wrong_type', ['type' => $documents, 'types' => 'PDF, DOCX, XLSX, ODT, ODS, TXT, CSV']),
        ];
    }

    /**
     * Checks that need the whole submission: combined screencast count and total size.
     *
     * @throws ValidationException
     */
    public static function assertSubmissionWithinLimits(Request $request): void
    {
        if (count(self::screencasts($request)) > self::SCREENCAST_MAX_FILES) {
            throw ValidationException::withMessages([
                'screencasts' => __('attachments.validation.too_many', [
                    'max' => self::SCREENCAST_MAX_FILES,
                    'type' => __('attachments.types.screencasts'),
                ]),
            ]);
        }

        $totalBytes = 0;
        foreach (self::allFiles($request) as $file) {
            $totalBytes += (int) $file->getSize();
        }

        if ($totalBytes > self::SUBMISSION_MAX_KB * 1024) {
            throw ValidationException::withMessages([
                'attachments' => __('attachments.validation.total_too_large', [
                    'size' => self::megabytes(self::SUBMISSION_MAX_KB),
                ]),
            ]);
        }
    }

    /**
     * @return list<UploadedFile>
     */
    public static function files(Request $request, string $field): array
    {
        $files = $request->file($field);
        $files = is_array($files) ? $files : [$files];

        return array_values(array_filter($files, static fn (mixed $file): bool => $file instanceof UploadedFile));
    }

    /**
     * @return list<UploadedFile>
     */
    public static function screencasts(Request $request): array
    {
        return [...self::files($request, 'screencasts'), ...self::files($request, 'screencast')];
    }

    /**
     * @return list<UploadedFile>
     */
    public static function allFiles(Request $request): array
    {
        return [
            ...self::files($request, 'screenshots'),
            ...self::screencasts($request),
            ...self::files($request, 'documents'),
        ];
    }

    public static function megabytes(int $kilobytes): string
    {
        return rtrim(rtrim(number_format($kilobytes / 1024, 1), '0'), '.').' MB';
    }

    /**
     * Limits for the upload component (#547).
     *
     * @return array<string, array{maxFiles: int, maxBytes: int, accept: string}>
     */
    public static function forComponent(): array
    {
        return [
            'screenshots' => [
                'maxFiles' => self::SCREENSHOT_MAX_FILES,
                'maxBytes' => self::SCREENSHOT_MAX_KB * 1024,
                'accept' => implode(',', self::SCREENSHOT_MIMES),
            ],
            'screencasts' => [
                'maxFiles' => self::SCREENCAST_MAX_FILES,
                'maxBytes' => self::SCREENCAST_MAX_KB * 1024,
                'accept' => implode(',', self::SCREENCAST_MIMES),
            ],
            'documents' => [
                'maxFiles' => self::DOCUMENT_MAX_FILES,
                'maxBytes' => self::DOCUMENT_MAX_KB * 1024,
                'accept' => implode(',', array_map(static fn (string $extension): string => '.'.$extension, self::DOCUMENT_EXTENSIONS)),
            ],
        ];
    }
}
