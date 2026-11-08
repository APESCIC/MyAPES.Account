<?php

namespace App\Services;

use App\Core\Accounts\User;
use App\Core\Attachments\AttachmentLimits;
use App\Core\Attachments\SupportAttachment;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;

class SupportAttachmentService
{
    public function __construct(
        private readonly SecureUploadService $uploads,
    ) {}

    /**
     * Stores every file field from a validated request (#546).
     *
     * @return Collection<int, SupportAttachment>
     */
    public function storeFromRequest(
        Model $attachable,
        string $subCoreKey,
        User $uploader,
        Request $request,
    ): Collection {
        return $this->storeFor(
            $attachable,
            $subCoreKey,
            $uploader,
            AttachmentLimits::files($request, 'screenshots'),
            AttachmentLimits::screencasts($request),
            AttachmentLimits::files($request, 'documents'),
        );
    }

    /**
     * @param  array<int, UploadedFile>|null  $screenshots
     * @param  UploadedFile|array<int, UploadedFile>|null  $screencasts
     * @param  array<int, UploadedFile>|null  $documents
     * @return Collection<int, SupportAttachment>
     */
    public function storeFor(
        Model $attachable,
        string $subCoreKey,
        User $uploader,
        ?array $screenshots = null,
        UploadedFile|array|null $screencasts = null,
        ?array $documents = null,
    ): Collection {
        $directory = $this->uploads->supportAttachmentDirectory(
            $subCoreKey,
            $attachable->getKey(),
        );
        $created = collect();

        foreach ($screenshots ?? [] as $file) {
            if (! $file instanceof UploadedFile) {
                continue;
            }
            $path = $this->uploads->storeImage(
                $file,
                $directory,
                'screenshots',
                'private',
            );
            $created->push($this->createRecord(
                $attachable,
                $uploader,
                $path,
                $file,
                SupportAttachment::KIND_SCREENSHOT,
            ));
        }

        foreach (is_array($screencasts) ? $screencasts : [$screencasts] as $screencast) {
            if (! $screencast instanceof UploadedFile) {
                continue;
            }
            $path = $this->uploads->storeScreencast(
                $screencast,
                $directory,
                'screencasts',
                'private',
            );
            $created->push($this->createRecord(
                $attachable,
                $uploader,
                $path,
                $screencast,
                SupportAttachment::KIND_SCREENCAST,
            ));
        }

        foreach ($documents ?? [] as $file) {
            if (! $file instanceof UploadedFile) {
                continue;
            }
            $path = $this->uploads->storeDocument(
                $file,
                $directory,
                'documents',
                'private',
            );
            $created->push($this->createRecord(
                $attachable,
                $uploader,
                $path,
                $file,
                SupportAttachment::KIND_DOCUMENT,
            ));
        }

        return $created;
    }

    /**
     * @param  Collection<int, SupportAttachment>  $attachments
     */
    public function linkTo(Collection $attachments, Model $context): void
    {
        $attachments->each(fn (SupportAttachment $attachment) => $attachment->forceFill([
            'context_type' => $context->getMorphClass(),
            'context_id' => $context->getKey(),
        ])->save());
    }

    private function createRecord(
        Model $attachable,
        User $uploader,
        string $path,
        UploadedFile $file,
        string $kind,
    ): SupportAttachment {
        return SupportAttachment::query()->create([
            'attachable_type' => $attachable->getMorphClass(),
            'attachable_id' => $attachable->getKey(),
            'user_id' => $uploader->id,
            'disk' => 'private',
            'path' => $path,
            'original_name' => mb_substr($file->getClientOriginalName(), 0, 255),
            'mime_type' => (string) $file->getMimeType(),
            'size_bytes' => (int) $file->getSize(),
            'kind' => $kind,
        ]);
    }
}
