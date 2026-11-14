<?php

namespace App\Core\Mail\Inbound\Local;

use App\Core\Accounts\User;
use App\Core\Mail\Inbound\Herd\HerdMailRunner;
use App\Core\Mail\Inbound\InboundMailSettings;
use App\Core\Mail\Inbound\MailboxUnavailable;
use App\Core\Mail\Inbound\Mailpit\MailpitClient;
use App\Core\Mail\Inbound\ReplyToken;
use Illuminate\Mail\Mailer;
use Illuminate\Support\Carbon;
use JsonException;
use RuntimeException;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mime\Address;

/**
 * The local reply loop (#757, #758, #817): find ticket and case emails in
 * Herd or Mailpit and send a client's reply through the local mail settings.
 */
final class LocalReplies
{
    public function __construct(
        private readonly MailpitClient $mailpit,
        private readonly HerdMailRunner $herd,
        private readonly InboundMailSettings $settings,
        private readonly ClientReplyBuilder $builder,
        private readonly Mailer $mailer,
    ) {}

    /**
     * The latest ticket and case emails, newest first.
     *
     * @return list<array{id: string, subject: string, to: string, created: string, url: string}>
     *
     * @throws MailboxUnavailable
     */
    public function recentRecordEmails(int $limit = 20): array
    {
        return $this->settings->usesHerd()
            ? $this->recentFromHerd($limit)
            : $this->recentFromMailpit($limit);
    }

    /**
     * Catcher ID of the latest email for a record given as T12 or C4.
     *
     * @throws MailboxUnavailable
     */
    public function latestFor(string $record): ?string
    {
        if (preg_match('/^([A-Za-z])(\d{1,18})$/', trim($record), $matches) !== 1) {
            throw new RuntimeException('Give the record as T or C followed by its id, for example T12.');
        }

        $tag = ReplyToken::subjectTag($matches[1], (int) $matches[2]);

        return $this->settings->usesHerd()
            ? $this->latestFromHerd($tag)
            : $this->latestFromMailpit($tag);
    }

    /**
     * Sends the client's reply to the email with this catcher ID.
     *
     * @param  list<string>  $variants
     * @return array{from: string, to: string, subject: string}
     *
     * @throws MailboxUnavailable
     */
    public function reply(string $messageId, string $body, ?string $senderEmail = null, array $variants = []): array
    {
        $original = $this->settings->usesHerd()
            ? OriginalEmail::fromHerd($this->herdMessage($messageId))
            : OriginalEmail::fromMailpit($this->mailpit->message($messageId));

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

    /**
     * @return list<array{id: string, subject: string, to: string, created: string, url: string}>
     */
    private function recentFromMailpit(int $limit): array
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

    private function latestFromMailpit(string $tag): ?string
    {
        foreach ($this->mailpit->search('subject:"'.$tag.'"', 50) as $message) {
            $subject = (string) ($message['Subject'] ?? '');
            if (str_contains(strtoupper($subject), $tag) && preg_match('/^\s*re:/i', $subject) !== 1) {
                return (string) ($message['ID'] ?? '') ?: null;
            }
        }

        return null;
    }

    /**
     * @return list<array{id: string, subject: string, to: string, created: string, url: string}>
     */
    private function recentFromHerd(int $limit): array
    {
        $emails = [];
        foreach ($this->herdList(['--subject=[MYAPES-']) as $message) {
            $subject = (string) ($message['subject'] ?? '');
            if (ReplyToken::fromSubject($subject) === null || preg_match('/^\s*re:/i', $subject) === 1) {
                continue;
            }

            $id = (string) ($message['id'] ?? '');
            $emails[] = [
                'id' => $id,
                'subject' => $subject,
                'to' => (string) ($message['to'][0]['address'] ?? ''),
                'created' => isset($message['date']) ? Carbon::parse((string) $message['date'])->timezone((string) config('app.timezone'))->format('Y-m-d H:i') : '',
                'url' => 'herd://mail/'.$id,
            ];

            if (count($emails) >= $limit) {
                break;
            }
        }

        return $emails;
    }

    private function latestFromHerd(string $tag): ?string
    {
        foreach ($this->herdList(['--subject='.$tag]) as $message) {
            $subject = (string) ($message['subject'] ?? '');
            if (str_contains(strtoupper($subject), $tag) && preg_match('/^\s*re:/i', $subject) !== 1) {
                return (string) ($message['id'] ?? '') ?: null;
            }
        }

        return null;
    }

    /**
     * @param  list<string>  $filters
     * @return list<array<string, mixed>>
     */
    private function herdList(array $filters): array
    {
        $output = $this->herd->run(['mail:list', '--json', '--limit=50', ...$filters]);

        try {
            $decoded = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new MailboxUnavailable('Herd mail:list returned invalid JSON.', previous: $exception);
        }

        return is_array($decoded) ? array_values($decoded) : [];
    }

    /**
     * @return array<string, mixed>
     */
    private function herdMessage(string $id): array
    {
        $output = $this->herd->run(['mail:show', $id, '--format=json']);

        try {
            $decoded = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new MailboxUnavailable('Herd mail:show returned invalid JSON.', previous: $exception);
        }

        if (! is_array($decoded)) {
            throw new MailboxUnavailable('Herd mail:show returned invalid JSON.');
        }

        return $decoded;
    }
}
