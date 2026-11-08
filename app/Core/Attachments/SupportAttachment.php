<?php

namespace App\Core\Attachments;

use App\Core\Accounts\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Facades\Storage;

#[Fillable([
    'attachable_type',
    'attachable_id',
    'user_id',
    'disk',
    'path',
    'original_name',
    'mime_type',
    'size_bytes',
    'kind',
    'context_type',
    'context_id',
])]
class SupportAttachment extends Model
{
    public const KIND_SCREENSHOT = 'screenshot';

    public const KIND_SCREENCAST = 'screencast';

    public const KIND_DOCUMENT = 'document';

    public const KINDS = [
        self::KIND_SCREENSHOT,
        self::KIND_SCREENCAST,
        self::KIND_DOCUMENT,
    ];

    public function attachable(): MorphTo
    {
        return $this->morphTo();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function context(): MorphTo
    {
        return $this->morphTo();
    }

    public function isContextOf(Model $model): bool
    {
        return $this->context_type === $model->getMorphClass()
            && (int) $this->context_id === (int) $model->getKey();
    }

    public function icon(): string
    {
        return match ($this->kind) {
            self::KIND_SCREENSHOT => 'image',
            self::KIND_SCREENCAST => 'video',
            default => 'file-text',
        };
    }

    public function humanSize(): string
    {
        $bytes = (int) $this->size_bytes;

        return $bytes >= 1048576
            ? number_format($bytes / 1048576, 1).' MB'
            : number_format(max($bytes, 0) / 1024, 1).' KB';
    }

    public function deleteFile(): void
    {
        if ($this->path !== '' && Storage::disk($this->disk)->exists($this->path)) {
            Storage::disk($this->disk)->delete($this->path);
        }
    }

    protected static function booted(): void
    {
        static::deleting(function (SupportAttachment $attachment): void {
            $attachment->deleteFile();
        });
    }
}
