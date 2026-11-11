<?php

namespace App\Core\Mail\Inbound\Local;

use App\Core\Accounts\User;
use App\Core\Mail\Inbound\MailboxUnavailable;
use App\Core\Mail\Inbound\Mailpit\MailpitClient;
use App\Core\Mail\Inbound\ReplyToken;
use Illuminate\Mail\Mailer;
use Illuminate\Support\Carbon;
use RuntimeException;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mime\Address;

/**
 * The local reply loop (#757, #758): find ticket and case emails in Laragon's
 * Mailpit and send a client's reply to them through the local mail settings.
 */
final class LocalReplies
{
    public function __construct(
        private readonly MailpitClient $mailpit,
        private readonly ClientReplyBuilder $builder,
        private readonly Mailer $mailer,
    ) {}

    /**
     * The latest ticket and case emails in Mailpit, newest first.
     *
     * @return list<array{id: string, subject: string, to: string, created: string, url: string}>
     *
     * @throws MailboxUnavailable
     */
    public function recentRecordEmails(int $limit = 20): array
    {
        $emails = [];
        foreach ($this->mailpit->search('subject:"[MYAPES-"', 200) as $message) {
            if (ReplyToken::fromSubject((string) ($message['Subject'] ?? '')) === null || preg_match('/^\s*re:/i', (string) ($message['Subject'] ?? '')) === 1) {
                continue;
            }

            $id = (string) ($message['ID'] ?? '');
            $emails[] = [
                'id' => $id,
                'subject' => (string) ($message['Subject'] ?? ''),
                'to' => (string) ($message['To'][0]['Address'] ?? ''),
                'created' => isset($message['Created']) ? Carbon::parse((string) $message['Created'])->timezone((string) config('app.timezone'))->format('Y-m-d H:i') : '',
                'url' => $this->mailpit->webUrl($id),
            ];

            if (count($emails) >= $limit) {
                break;
            }
        }

        return $emails;
    }

    /**
     * Mailpit ID of the latest email for a record given as T12 or C4.
     *
     * @throws MailboxUnavailable
     */
    public function latestFor(string $record): ?string
    {
        if (preg_match('/^([A-Za-z])(\d{1,18})$/', trim($record), $matches) !== 1) {
            throw new RuntimeException('Give the record as T or C followed by its id, for example T12.');
        }

        $tag = ReplyToken::subjectTag($matches[1], (int) $matches[2]);
        foreach ($this->mailpit->search('subject:"'.$tag.'"', 50) as $message) {
            $subject = (string) ($message['Subject'] ?? '');
            if (str_contains(strtoupper($subject), $tag) && preg_match('/^\s*re:/i', $subject) !== 1) {
                return (string) ($message['ID'] ?? '') ?: null;
            }
        }

        return null;
    }

    /**
     * Sends the client's reply to the email with this Mailpit ID.
     *
     * @param  list<string>  $variants
     * @return array{from: string, to: string, subject: string}
     *
     * @throws MailboxUnavailable
     */
    public function reply(string $mailpitId, string $body, ?string $senderEmail = null, array $variants = []): array
    {
        $original = OriginalEmail::fromMailpit($this->mailpit->message($mailpitId));
        if ($original->replyTo === '') {
            throw new RuntimeException('That email has no Reply-To address to reply to.');
        }

        $address = strtolower(trim($senderEmail ?: $original->recipient));
        $sender = User::query()->whereRaw('LOWER(email) = ?', [$address])->first();
        if (! $sender instanceof User) {
            throw new RuntimeException('No account has the email address '.$address.'.');
        }

        $email = $this->builder->build($original, $sender, trim($body) !== '' ? $body : 'Thanks, this is a reply sent by email.', $variants);
        $this->mailer->getSymfonyTransport()->send($email, new Envelope(new Address((string) $sender->email), [new Address($original->replyTo)]));

        return ['from' => (string) $sender->email, 'to' => $original->replyTo, 'subject' => $email->getSubject() ?? ''];
    }
}
