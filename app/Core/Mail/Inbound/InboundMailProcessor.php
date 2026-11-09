<?php

namespace App\Core\Mail\Inbound;

use App\Core\Mail\Inbound\Parsing\InboundEmailParser;
use App\Core\Mail\Inbound\Parsing\ParsedInboundEmail;
use App\Core\Mail\Inbound\Parsing\UnparseableEmail;
use Illuminate\Support\Facades\Storage;

/**
 * Works out what a stored inbound email is and what to do with it (#579).
 */
final class InboundMailProcessor
{
    public function __construct(
        private readonly InboundEmailParser $parser,
    ) {}

    public function process(InboundEmail $email): void
    {
        $email->increment('attempts');
        $parsed = $this->parse($email);

        if ($parsed === null) {
            return;
        }

        $this->finish($email, InboundEmail::STATUS_QUARANTINED, 'unmatched');
    }

    private function parse(InboundEmail $email): ?ParsedInboundEmail
    {
        $raw = $email->hasRaw() ? Storage::disk(InboundEmail::RAW_DISK)->get((string) $email->raw_path) : null;

        if (! is_string($raw) || $raw === '') {
            $this->finish($email, InboundEmail::STATUS_FAILED, 'raw_missing');

            return null;
        }

        try {
            return $this->parser->parse($raw);
        } catch (UnparseableEmail) {
            $this->finish($email, InboundEmail::STATUS_FAILED, 'unparseable');

            return null;
        }
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function finish(InboundEmail $email, string $status, ?string $reason, array $attributes = []): void
    {
        $email->forceFill([
            ...$attributes,
            'status' => $status,
            'reason' => $reason,
            'processed_at' => now(),
        ])->save();
    }
}
