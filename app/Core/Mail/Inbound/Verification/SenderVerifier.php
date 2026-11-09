<?php

namespace App\Core\Mail\Inbound\Verification;

use App\Core\Accounts\User;
use App\Core\Mail\Inbound\Contracts\InboundReplyHandler;
use App\Core\Mail\Inbound\InboundMailSettings;
use App\Core\Mail\Inbound\Matching\ReplyMatch;
use App\Core\Mail\Inbound\Parsing\ParsedInboundEmail;
use App\Core\Mail\Inbound\ReplyToken;
use Illuminate\Database\Eloquent\Model;

/**
 * Decides whether the sender may post this reply (#580). The sender must
 * pass SPF or DKIM with DMARC not failing, have an active account, match
 * the token's or thread's recipient, and be the owner or eligible staff.
 * Anything doubtful is quarantined for an administrator, never posted.
 */
final class SenderVerifier
{
    public function __construct(
        private readonly InboundMailSettings $settings,
        private readonly ReplyToken $tokens,
    ) {}

    public function check(ParsedInboundEmail $email, AuthenticationResults $auth, ReplyMatch $match, InboundReplyHandler $handler, Model $record): SenderCheck
    {
        if ($this->settings->requiresAuthentication() && ! $auth->passes()) {
            return SenderCheck::quarantined($auth->present() ? 'authentication_failed' : 'unauthenticated');
        }

        $user = $email->fromAddress === null ? null : User::query()
            ->whereRaw('LOWER(email) = ?', [strtolower($email->fromAddress)])
            ->first();

        if (! $user instanceof User) {
            return SenderCheck::quarantined('unknown_sender');
        }

        if ($user->suspended_at !== null) {
            return SenderCheck::rejected('suspended', $user);
        }

        if ($match->method === ReplyMatch::BY_TOKEN
            && ! $this->tokens->verify($match->prefix, $match->recordId, (int) $user->id, (string) $match->signature)) {
            return SenderCheck::quarantined('wrong_sender', $user);
        }

        if ($match->method === ReplyMatch::BY_THREAD && $match->threadUserId !== (int) $user->id) {
            return SenderCheck::quarantined('wrong_sender', $user);
        }

        if (! $handler->canReply($record, $user)) {
            return SenderCheck::quarantined('not_permitted', $user);
        }

        return SenderCheck::passed($user);
    }
}
