<?php

namespace App\Core\Mail\Inbound\Matching;

use App\Core\Mail\Inbound\InboundMailSettings;
use App\Core\Mail\Inbound\MailReplyThread;
use App\Core\Mail\Inbound\Parsing\ParsedInboundEmail;
use App\Core\Mail\Inbound\ReplyToken;

/**
 * Finds the ticket or case a reply is for (#580, #748): the Reply-To token
 * first (when plus-addressing is on), then the stored Message-ID in
 * In-Reply-To or References, then the [MYAPES-T-1234] subject tag. When
 * these point to different records the reply is held as conflicting.
 */
final class ReplyMatcher
{
    public function __construct(
        private readonly ReplyToken $tokens,
        private readonly InboundMailSettings $settings,
    ) {}

    /**
     * @throws ConflictingReferences
     */
    public function match(ParsedInboundEmail $email): ?ReplyMatch
    {
        $candidates = array_values(array_filter([
            $this->byToken($email),
            ...$this->byThread($email),
            $this->bySubject($email),
        ]));

        if ($candidates === []) {
            return null;
        }

        $records = array_unique(array_map(static fn (ReplyMatch $match): string => $match->prefix.$match->recordId, $candidates));
        if (count($records) > 1) {
            throw new ConflictingReferences('The reply refers to more than one ticket or case.');
        }

        return $candidates[0];
    }

    private function byToken(ParsedInboundEmail $email): ?ReplyMatch
    {
        if (! $this->settings->plusAddressing()) {
            return null;
        }

        foreach ($email->recipients as $address) {
            $token = $this->tokens->fromAddress($address);

            if ($token !== null) {
                return new ReplyMatch($token['prefix'], $token['id'], ReplyMatch::BY_TOKEN, signature: $token['signature']);
            }
        }

        return null;
    }

    /**
     * Every stored thread the headers refer to, the most direct first.
     *
     * @return list<ReplyMatch>
     */
    private function byThread(ParsedInboundEmail $email): array
    {
        $ids = array_values(array_unique([...$email->headers->messageIds('in-reply-to'), ...array_reverse($email->headers->messageIds('references'))]));

        if ($ids === []) {
            return [];
        }

        $threads = MailReplyThread::query()
            ->whereIn('message_id', array_slice($ids, 0, 50))
            ->get()
            ->keyBy('message_id');

        $matches = [];
        foreach ($ids as $id) {
            $thread = $threads->get($id);

            if ($thread instanceof MailReplyThread) {
                $matches[] = new ReplyMatch($thread->record_prefix, $thread->record_id, ReplyMatch::BY_THREAD, threadUserId: $thread->user_id);
            }
        }

        return $matches;
    }

    private function bySubject(ParsedInboundEmail $email): ?ReplyMatch
    {
        $tag = ReplyToken::fromSubject($email->subject);

        return $tag === null ? null : new ReplyMatch($tag['prefix'], $tag['id'], ReplyMatch::BY_SUBJECT);
    }
}
