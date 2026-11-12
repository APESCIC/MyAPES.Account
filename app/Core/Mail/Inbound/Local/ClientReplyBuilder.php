<?php

namespace App\Core\Mail\Inbound\Local;

use App\Core\Accounts\User;
use App\Core\Mail\Inbound\InboundMailSettings;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/**
 * Builds the email a client would send when replying to a ticket or case
 * email (#757), for the local reply loop and the reply routing fixtures
 * (#750). Never used to send anywhere but the local mail settings.
 */
final class ClientReplyBuilder
{
    public const NO_TAG = 'no-tag';

    public const NO_REFERENCES = 'no-references';

    public const EDITED_SUBJECT = 'edited-subject';

    public const HTML_ONLY = 'html-only';

    public const ATTACHMENT = 'attachment';

    public const VARIANTS = [self::NO_TAG, self::NO_REFERENCES, self::EDITED_SUBJECT, self::HTML_ONLY, self::ATTACHMENT];

    public const EDITED_SUBJECT_TEXT = 'Re: A different question';

    public function __construct(private readonly InboundMailSettings $settings) {}

    /**
     * @param  list<string>  $variants  Any of self::VARIANTS. Another sender is chosen by passing a different $sender.
     */
    public function build(OriginalEmail $original, User $sender, string $body, array $variants = []): Email
    {
        $unknown = array_diff($variants, self::VARIANTS);
        if ($unknown !== []) {
            throw new InvalidArgumentException('Unknown reply variant: '.implode(', ', $unknown).'.');
        }

        $domain = substr((string) strrchr((string) $sender->email, '@'), 1) ?: 'example.org';
        $server = $this->settings->authservId() !== '' ? $this->settings->authservId() : 'localhost';
        $email = (new Email)
            ->from(new Address((string) $sender->email, (string) $sender->name))
            ->to($original->replyTo)
            ->subject($this->subject($original->subject, $variants))
            ->date(now()->toDateTimeImmutable());

        $headers = $email->getHeaders();
        $headers->addPathHeader('Return-Path', (string) $sender->email);
        $headers->addIdHeader('Message-ID', 'reply.'.Str::lower(Str::random(16)).'@'.$domain);
        $headers->addTextHeader('Authentication-Results', $server.'; spf=pass smtp.mailfrom='.$domain.'; dkim=pass header.d='.$domain.'; dmarc=pass header.from='.$domain);

        if (! in_array(self::NO_REFERENCES, $variants, true) && $original->messageId !== '') {
            $headers->addIdHeader('In-Reply-To', $original->messageId);
            $headers->addIdHeader('References', $original->messageId);
        }

        $text = $this->text($body, $original->text);
        if (in_array(self::HTML_ONLY, $variants, true)) {
            $email->html('<p>'.nl2br(e($body), false).'</p><blockquote>'.nl2br(e($this->quoted($original->text)), false).'</blockquote>');
        } else {
            $email->text($text);
        }

        if (in_array(self::ATTACHMENT, $variants, true)) {
            $email->attach("Photo notes for the reply.\n", 'notes.txt', 'text/plain');
        }

        return $email;
    }

    /**
     * The raw RFC 5322 message, as a mailbox would hold it.
     */
    public function raw(Email $email): string
    {
        return $email->toString();
    }

    /**
     * @param  list<string>  $variants
     */
    private function subject(string $original, array $variants): string
    {
        if (in_array(self::EDITED_SUBJECT, $variants, true)) {
            return self::EDITED_SUBJECT_TEXT;
        }

        $subject = preg_match('/^\s*re:/i', $original) === 1 ? $original : 'Re: '.$original;

        if (in_array(self::NO_TAG, $variants, true)) {
            $subject = (string) preg_replace('/\s*\[MYAPES-[A-Z]-\d{1,18}\]\s*/i', ' ', $subject);
        }

        return trim($subject);
    }

    private function text(string $body, string $original): string
    {
        return rtrim($body)."\n\n".$this->quoted($original);
    }

    private function quoted(string $original): string
    {
        $lines = ['> '.__('mail.reply_by_email.marker')];
        foreach (preg_split('/\R/', trim($original) !== '' ? trim($original) : '(quoted text from the original email)') ?: [] as $line) {
            $lines[] = '> '.$line;
        }

        return implode("\n", $lines);
    }
}
