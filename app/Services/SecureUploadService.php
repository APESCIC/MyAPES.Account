<?php

namespace App\Services;

use App\Core\Attachments\DocumentInspector;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class SecureUploadService
{
    public const SCREENCAST_MAX_KB = 51200;

    public const DOCUMENT_MAX_KB = 10240;

    public function __construct(
        private readonly DocumentInspector $documents = new DocumentInspector,
    ) {}

    /**
     * @param  array<int, string>  $allowedMimes
     */
    public function storeImage(
        UploadedFile $file,
        string $directory,
        string $fieldName = 'file',
        string $disk = 'public',
        array $allowedMimes = ['image/jpeg', 'image/png', 'image/webp']
    ): string {
        $mime = $file->getMimeType();

        if (! is_string($mime) || ! in_array($mime, $allowedMimes, true)) {
            throw ValidationException::withMessages([
                $fieldName => 'Only JPEG, PNG, and WebP uploads are allowed.',
            ]);
        }

        return $file->store($directory, $disk);
    }

    /**
     * @param  array<int, string>  $allowedMimes
     */
    public function storeScreencast(
        UploadedFile $file,
        string $directory,
        string $fieldName = 'screencast',
        string $disk = 'public',
        array $allowedMimes = ['video/mp4', 'video/webm'],
        int $maxKilobytes = self::SCREENCAST_MAX_KB,
    ): string {
        $mime = $file->getMimeType();

        if (! is_string($mime) || ! in_array($mime, $allowedMimes, true)) {
            throw ValidationException::withMessages([
                $fieldName => 'Only MP4 and WebM screencasts are allowed.',
            ]);
        }

        if ($file->getSize() > $maxKilobytes * 1024) {
            throw ValidationException::withMessages([
                $fieldName => 'Screencasts must be '.$maxKilobytes.' KB or smaller.',
            ]);
        }

        return $file->store($directory, $disk);
    }

    public function storeDocument(
        UploadedFile $file,
        string $directory,
        string $fieldName = 'documents',
        string $disk = 'private',
        int $maxKilobytes = self::DOCUMENT_MAX_KB,
    ): string {
        $problem = $this->documents->problem($file);

        if ($problem !== null) {
            throw ValidationException::withMessages([
                $fieldName => __($problem, ['name' => $file->getClientOriginalName()]),
            ]);
        }

        if ($file->getSize() > $maxKilobytes * 1024) {
            throw ValidationException::withMessages([
                $fieldName => __('attachments.validation.file_too_large', [
                    'name' => $file->getClientOriginalName(),
                    'size' => $this->megabytes($maxKilobytes),
                ]),
            ]);
        }

        $extension = strtolower($file->getClientOriginalExtension());

        return $file->storeAs($directory, Str::random(40).'.'.$extension, $disk);
    }

    public function supportAttachmentDirectory(string $subCoreKey, int|string $attachableId): string
    {
        return sprintf(
            'support-attachments/%s/%s/%s',
            $subCoreKey,
            now()->format('Y'),
            $attachableId,
        );
    }

    public function deleteIfPresent(?string $path, string $disk = 'public'): void
    {
        if ($path === null || $path === '') {
            return;
        }

        if (Storage::disk($disk)->exists($path)) {
            Storage::disk($disk)->delete($path);
        }
    }

    private function megabytes(int $kilobytes): string
    {
        return rtrim(rtrim(number_format($kilobytes / 1024, 1), '0'), '.').' MB';
    }
}
