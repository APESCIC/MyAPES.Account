<?php

namespace App\Core\Mail\Inbound;

use App\Core\Mail\Inbound\Parsing\InboundEmailParser;
use App\Core\Mail\Inbound\Parsing\RawHeaders;
use App\Core\Mail\Inbound\Parsing\UnparseableEmail;
use App\Jobs\ProcessInboundEmail;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Stores a received email once, keyed by its Message-ID (#579), and queues
 * it for processing. A message seen before is skipped, so a mailbox that
 * delivers the same email twice never posts twice.
 */
final class InboundMailStore
{
    public function __construct(
        private readonly InboundEmailParser $parser,
    ) {}

    /**
     * @return InboundEmail|null null when the message was already stored
     */
    public function store(string $raw, string $source = 'imap'): ?InboundEmail
    {
        $headers = RawHeaders::fromRaw($raw);
        $messageId = $headers->messageIds('message-id')[0] ?? null;
        $hash = hash('sha256', $messageId ?? 'raw:'.hash('sha256', $raw));

        if (InboundEmail::query()->where('message_id_hash', $hash)->exists()) {
            return null;
        }

        $fromAddress = null;
        $fromName = null;
        $subject = null;
        $unparseable = false;

        try {
            $parsed = $this->parser->parse($raw);
            $fromAddress = $parsed->fromAddress;
            $fromName = $parsed->fromName;
            $subject = $parsed->subject;
        } catch (UnparseableEmail) {
            $unparseable = true;
        }

        $tooLarge = strlen($raw) > (int) config('inbound-mail.max_message_bytes');
        $path = null;

        if (! $tooLarge) {
            $path = 'inbound-mail/'.now()->format('Y/m').'/'.Str::uuid()->toString().'.eml';
            Storage::disk(InboundEmail::RAW_DISK)->put($path, $raw);
        }

        try {
            $email = DB::transaction(fn (): InboundEmail => InboundEmail::query()->create([
                'message_id_hash' => $hash,
                'message_id' => $messageId === null ? null : mb_substr($messageId, 0, 191),
                'source' => $source,
                'from_address' => $fromAddress === null ? null : mb_substr($fromAddress, 0, 255),
                'from_name' => $fromName === null ? null : mb_substr($fromName, 0, 255),
                'from_hash' => $fromAddress === null ? null : InboundEmail::hashAddress($fromAddress),
                'from_domain' => $fromAddress === null || ! str_contains($fromAddress, '@') ? null : mb_substr(substr($fromAddress, strrpos($fromAddress, '@') + 1), 0, 255),
                'subject' => $subject === null ? null : mb_substr($subject, 0, 255),
                'raw_path' => $path,
                'raw_size' => strlen($raw),
                'status' => match (true) {
                    $tooLarge => InboundEmail::STATUS_REJECTED,
                    $unparseable => InboundEmail::STATUS_FAILED,
                    default => InboundEmail::STATUS_RECEIVED,
                },
                'reason' => match (true) {
                    $tooLarge => 'too_large',
                    $unparseable => 'unparseable',
                    default => null,
                },
                'received_at' => now(),
            ]));
        } catch (UniqueConstraintViolationException) {
            if ($path !== null) {
                Storage::disk(InboundEmail::RAW_DISK)->delete($path);
            }

            return null;
        }

        if ($email->status === InboundEmail::STATUS_RECEIVED) {
            ProcessInboundEmail::dispatch((int) $email->id);
        }

        return $email;
    }
}
