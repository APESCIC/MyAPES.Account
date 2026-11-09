<?php

namespace App\Core\Mail\Inbound;

use Illuminate\Http\UploadedFile;

/**
 * Email attachments sorted into the v0.58 upload kinds (#584), plus the
 * reasons any were turned away.
 */
final readonly class InboundAttachmentSet
{
    /**
     * @param  list<UploadedFile>  $screenshots
     * @param  list<UploadedFile>  $screencasts
     * @param  list<UploadedFile>  $documents
     * @param  list<string>  $rejected
     */
    public function __construct(
        public array $screenshots = [],
        public array $screencasts = [],
        public array $documents = [],
        public array $rejected = [],
    ) {}

    public static function none(): self
    {
        return new self;
    }

    public function count(): int
    {
        return count($this->screenshots) + count($this->screencasts) + count($this->documents);
    }

    public function isEmpty(): bool
    {
        return $this->count() === 0;
    }

    public function withRejected(string ...$reasons): self
    {
        return new self($this->screenshots, $this->screencasts, $this->documents, [...$this->rejected, ...$reasons]);
    }
}
