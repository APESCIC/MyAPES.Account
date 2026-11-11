<?php

namespace App\Core\Mail\Inbound;

use Illuminate\Support\Facades\Log;

/**
 * Reads the inbound mail settings (#589). Replies by email are offered only
 * when the feature is on and a dedicated token key is set.
 */
final class InboundMailSettings
{
    public const DRIVER_IMAP = 'imap';

    public const DRIVER_MAILPIT = 'mailpit';

    public function repliesEnabled(): bool
    {
        return (bool) config('inbound-mail.enabled')
            && $this->tokenKey() !== ''
            && $this->mailbox() !== null;
    }

    public function pollingConfigured(): bool
    {
        if (! $this->repliesEnabled()) {
            return false;
        }

        if ($this->driver() === self::DRIVER_MAILPIT) {
            if ($this->mailpitAllowed()) {
                return true;
            }

            Log::warning('INBOUND_MAIL_DRIVER=mailpit works only on local or testing with a loopback INBOUND_MAIL_MAILPIT_URL; polling is off.');

            return false;
        }

        if ($this->driver() !== self::DRIVER_IMAP) {
            Log::warning('INBOUND_MAIL_DRIVER must be imap or mailpit; polling is off.');

            return false;
        }

        return $this->imap('host') !== ''
            && $this->imap('username') !== ''
            && $this->imap('password') !== '';
    }

    public function driver(): string
    {
        return strtolower(trim((string) config('inbound-mail.driver', self::DRIVER_IMAP))) ?: self::DRIVER_IMAP;
    }

    public function usesMailpit(): bool
    {
        return $this->driver() === self::DRIVER_MAILPIT && $this->mailpitAllowed();
    }

    public function mailpitUrl(): string
    {
        return rtrim(trim((string) config('inbound-mail.mailpit.url')), '/');
    }

    /**
     * Mailpit is a local catch-all with no authentication, so it is only ever read on a developer machine.
     */
    public function mailpitAllowed(): bool
    {
        $host = strtolower((string) parse_url($this->mailpitUrl(), PHP_URL_HOST));

        return app()->environment(['local', 'testing'])
            && in_array(trim($host, '[]'), ['127.0.0.1', 'localhost', '::1'], true);
    }

    public function address(): string
    {
        return strtolower(trim((string) config('inbound-mail.address')));
    }

    /**
     * @return array{local: string, domain: string}|null
     */
    public function mailbox(): ?array
    {
        $address = $this->address();
        $at = strrpos($address, '@');

        if ($at === false || $at === 0 || $at === strlen($address) - 1) {
            return null;
        }

        return ['local' => substr($address, 0, $at), 'domain' => substr($address, $at + 1)];
    }

    public function plusAddressing(): bool
    {
        return (bool) config('inbound-mail.plus_addressing');
    }

    public function tokenKey(): string
    {
        return trim((string) config('inbound-mail.token_key'));
    }

    public function replyAddress(string $prefix, int $recordId, int $userId): string
    {
        $mailbox = $this->mailbox() ?? ['local' => 'communications', 'domain' => 'localhost'];

        if (! $this->plusAddressing()) {
            return $mailbox['local'].'@'.$mailbox['domain'];
        }

        return $mailbox['local'].'+'.app(ReplyToken::class)->localTag($prefix, $recordId, $userId).'@'.$mailbox['domain'];
    }

    public function messageDomain(): string
    {
        return $this->mailbox()['domain'] ?? 'localhost';
    }

    public function imap(string $key): string
    {
        return trim((string) config('inbound-mail.imap.'.$key));
    }

    public function authservId(): string
    {
        return strtolower(trim((string) config('inbound-mail.authserv_id')));
    }

    public function requiresAuthentication(): bool
    {
        return (bool) config('inbound-mail.require_authentication');
    }

    public function retentionDays(): int
    {
        return max(1, (int) config('inbound-mail.retention_days', 30));
    }

    public function rateLimit(string $key): int
    {
        return max(1, (int) config('inbound-mail.rate_limits.'.$key, 1));
    }
}
