<?php

namespace App\Core\Mail\Inbound\Matching;

use App\Core\Mail\Inbound\MailReplyThread;
use App\Core\Mail\Inbound\Parsing\ParsedInboundEmail;
use App\Core\Mail\Inbound\ReplyToken;

/**
 * Finds the ticket or case a reply is for (#580): the Reply-To token first,
 * then the [MYAPES-T-1234] subject tag, then In-Reply-To and References.
 */
final class ReplyMatcher
{
    public function __construct(private readonly ReplyToken $tokens) {}

    public function match(ParsedInboundEmail $email): ?ReplyMatch
    {
        foreach ($email->recipients as $address) {
            $token = $this->tokens->fromAddress($address);

            if ($token !== null) {
                return new ReplyMatch($token['prefix'], $token['id'], ReplyMatch::BY_TOKEN, signature: $token['signature']);
            }
        }

        $tag = ReplyToken::fromSubject($email->subject);

        if ($tag !== null) {
            return new ReplyMatch($tag['prefix'], $tag['id'], ReplyMatch::BY_SUBJECT);
        }

        $ids = [...$email->headers->messageIds('in-reply-to'), ...array_reverse($email->headers->messageIds('references'))];

        if ($ids === []) {
            return null;
        }

        $threads = MailReplyThread::query()
            ->whereIn('message_id', array_slice(array_values(array_unique($ids)), 0, 50))
            ->get()
            ->keyBy('message_id');

        foreach ($ids as $id) {
            $thread = $threads->get($id);

            if ($thread instanceof MailReplyThread) {
                return new ReplyMatch($thread->record_prefix, $thread->record_id, ReplyMatch::BY_THREAD, threadUserId: $thread->user_id);
            }
        }

        return null;
    }
}
