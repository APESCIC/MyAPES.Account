<?php

namespace App\Core\Mail\Inbound;

use App\Core\Mail\Inbound\Matching\ReplyMatcher;
use App\Core\Mail\Inbound\Parsing\InboundEmailParser;
use App\Core\Mail\Inbound\Parsing\ParsedInboundEmail;
use App\Core\Mail\Inbound\Parsing\ReplyTextExtractor;
use App\Core\Mail\Inbound\Parsing\UnparseableEmail;
use App\Core\Mail\Inbound\Verification\AuthenticationResults;
use App\Core\Mail\Inbound\Verification\SenderVerifier;
use Illuminate\Support\Facades\Storage;

/**
 * Works out what a stored inbound email is and what to do with it (#579).
 *
 * Order: parse, match the record (#580), check the sender (#580), extract
 * the reply text (#581), then post it through the plugin's handler. Each step that fails records a
 * status and a reason; nothing doubtful is ever posted.
 */
final class InboundMailProcessor
{
    public function __construct(
        private readonly InboundEmailParser $parser,
        private readonly InboundMailSettings $settings,
        private readonly InboundReplyRegistry $handlers,
        private readonly ReplyMatcher $matcher,
        private readonly SenderVerifier $verifier,
        private readonly ReplyTextExtractor $extractor,
    ) {}

    public function process(InboundEmail $email): void
    {
        $email->increment('attempts');
        $parsed = $this->parse($email);

        if ($parsed === null) {
            return;
        }

        $auth = AuthenticationResults::from($parsed->headers, $this->settings->authservId());
        $email->forceFill(['spf' => $auth->spf, 'dkim' => $auth->dkim, 'dmarc' => $auth->dmarc]);

        $match = $this->matcher->match($parsed);
        $handler = $match === null ? null : $this->handlers->handler($match->prefix);

        if ($match === null || $handler === null) {
            $this->finish($email, InboundEmail::STATUS_QUARANTINED, 'unmatched');

            return;
        }

        $email->forceFill(['record_prefix' => $match->prefix, 'record_id' => $match->recordId, 'plugin' => $handler->plugin()]);
        $record = $handler->find($match->recordId);

        if ($record === null) {
            $this->finish($email, InboundEmail::STATUS_QUARANTINED, 'record_not_found');

            return;
        }

        $email->forceFill(['module_slug' => $handler->moduleSlug($record)]);
        $sender = $this->verifier->check($parsed, $auth, $match, $handler, $record);
        $email->forceFill(['user_id' => $sender->user?->id]);

        if (! $sender->passes()) {
            $this->finish($email, (string) $sender->status, $sender->reason);

            return;
        }

        $body = $this->extractor->extract($parsed);

        if ($body === '') {
            $this->finish($email, InboundEmail::STATUS_REJECTED, 'empty_reply');

            return;
        }

        $posted = $handler->post($record, $sender->user, $body, InboundAttachmentSet::none());

        $this->finish($email, InboundEmail::STATUS_POSTED, null, [
            'posted_type' => $posted->getMorphClass(),
            'posted_id' => $posted->getKey(),
        ]);
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
