<?php

namespace App\Core\Mail\Inbound;

use App\Core\Mail\Inbound\Diagnostics\MailboxDiagnostics;
use App\Core\Secrets\SecretRegistry;
use App\Core\Settings\AppSettings;
use Illuminate\Support\Facades\Log;

/**
 * Reads the inbound mail settings (#589, #808). Connection values are built
 * into config (overridable by a non-empty INBOUND_MAIL_* .env value). The
 * mailbox password comes from Admin → Keys and secrets first, then .env.
 * Legacy inbound_mailbox rows keep import running until #809 retires them.
 */
final class InboundMailSettings
{
    public const DRIVER_IMAP = 'imap';

    public const DRIVER_MAILPIT = 'mailpit';

    public const DRIVER_HERD = 'herd';

    public const PASSWORD_SOURCE_KEYS = SecretRegistry::SOURCE_KEYS;

    public const PASSWORD_SOURCE_ENV = SecretRegistry::SOURCE_ENV;

    public const PASSWORD_SOURCE_LEGACY = 'legacy';

    public const PASSWORD_SOURCE_MISSING = SecretRegistry::SOURCE_MISSING;

    private const CONFIG_KEYS = [
        'enabled' => 'inbound-mail.enabled',
        'address' => 'inbound-mail.address',
        'plus_addressing' => 'inbound-mail.plus_addressing',
        'host' => 'inbound-mail.imap.host',
        'port' => 'inbound-mail.imap.port',
        'encryption' => 'inbound-mail.imap.encryption',
        'validate_cert' => 'inbound-mail.imap.validate_cert',
        'username' => 'inbound-mail.imap.username',
        'password' => 'inbound-mail.imap.password',
        'folder' => 'inbound-mail.imap.folder',
    ];

    public function __construct(
        private readonly AppSettings $settings,
        private readonly SecretRegistry $secrets,
    ) {}

    public function repliesEnabled(): bool
    {
        if ($this->explicitlyDisabled()) {
            return false;
        }

        return $this->tokenKey() !== ''
            && $this->imapPassword() !== ''
            && $this->mailbox() !== null
            && $this->connectionMismatchMessage() === null;
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

        if ($this->driver() === self::DRIVER_HERD) {
            if ($this->herdAllowed()) {
                return true;
            }

            Log::warning('INBOUND_MAIL_DRIVER=herd works only on local or testing; polling is off.');

            return false;
        }

        if ($this->driver() !== self::DRIVER_IMAP) {
            Log::warning('INBOUND_MAIL_DRIVER must be imap, mailpit or herd; polling is off.');

            return false;
        }

        return $this->imap('host') !== ''
            && $this->imap('username') !== ''
            && $this->imapPassword() !== '';
    }

    public function driver(): string
    {
        return strtolower(trim((string) config('inbound-mail.driver', self::DRIVER_IMAP))) ?: self::DRIVER_IMAP;
    }

    public function usesMailpit(): bool
    {
        return $this->driver() === self::DRIVER_MAILPIT && $this->mailpitAllowed();
    }

    public function usesHerd(): bool
    {
        return $this->driver() === self::DRIVER_HERD && $this->herdAllowed();
    }

    /**
     * Local-only catchers (Mailpit or Herd) that skip the IMAP connection test.
     */
    public function usesLocalCatcher(): bool
    {
        return $this->usesMailpit() || $this->usesHerd();
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

    /**
     * Herd's mail catcher is only available on a developer machine (#816).
     */
    public function herdAllowed(): bool
    {
        return app()->environment(['local', 'testing']);
    }

    public function address(): string
    {
        return strtolower(trim((string) $this->connectionValue('address')));
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
        return (bool) $this->connectionValue('plus_addressing');
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
        if ($key === 'password') {
            return $this->imapPassword();
        }

        if ($key === 'username') {
            $username = trim((string) $this->connectionValue('username'));

            return $username !== '' ? $username : $this->address();
        }

        if (isset(self::CONFIG_KEYS[$key])) {
            return trim((string) $this->connectionValue($key));
        }

        return trim((string) config('inbound-mail.imap.'.$key));
    }

    public function validateCert(): bool
    {
        return (bool) $this->connectionValue('validate_cert');
    }

    /**
     * @return self::PASSWORD_SOURCE_*
     */
    public function passwordSource(): string
    {
        $source = $this->secrets->source(SecretRegistry::INBOUND_MAIL_IMAP_PASSWORD);

        if ($source !== SecretRegistry::SOURCE_MISSING) {
            return $source;
        }

        $legacy = $this->legacyPassword();

        return $legacy !== '' ? self::PASSWORD_SOURCE_LEGACY : self::PASSWORD_SOURCE_MISSING;
    }

    public function imapPassword(): string
    {
        $fromSecrets = $this->secrets->get(SecretRegistry::INBOUND_MAIL_IMAP_PASSWORD);

        if (is_string($fromSecrets) && $fromSecrets !== '') {
            return $fromSecrets;
        }

        return $this->legacyPassword();
    }

    /**
     * The existing port/encryption mismatch message, or null when the pair is valid (#808).
     */
    public function connectionMismatchMessage(): ?string
    {
        $port = (int) $this->imap('port');
        $encryption = $this->imap('encryption') ?: 'ssl';

        if (! MailboxDiagnostics::mismatched($port, $encryption)) {
            return null;
        }

        return __('admin.emails.import.encryption_mismatch', [
            'port' => $port,
            'encryption' => __($port === 993 ? 'admin.emails.import.encryption.ssl' : 'admin.emails.import.encryption.tls'),
        ]);
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

    /**
     * INBOUND_MAIL_ENABLED=false turns replies off even when a password is set (#808).
     */
    private function explicitlyDisabled(): bool
    {
        $raw = config('inbound-mail.enabled');

        if ($raw === null || $raw === '') {
            return false;
        }

        return filter_var($raw, FILTER_VALIDATE_BOOLEAN) === false;
    }

    /**
     * Built-in / .env values, or the legacy Admin form row while #809 still keeps it.
     */
    private function connectionValue(string $key): mixed
    {
        if ($this->passwordSource() === self::PASSWORD_SOURCE_LEGACY
            && $this->settings->source(InboundMailboxSettings::GROUP, $key) === AppSettings::SOURCE_DATABASE) {
            return $this->settings->get(InboundMailboxSettings::GROUP, $key);
        }

        return config(self::CONFIG_KEYS[$key]);
    }

    private function legacyPassword(): string
    {
        if (! $this->settings->stored(InboundMailboxSettings::GROUP, 'password')) {
            return '';
        }

        // Prefer the stored legacy row even when .env is empty; Secrets already covered env.
        $value = $this->settings->getStored(InboundMailboxSettings::GROUP, 'password');

        return is_string($value) ? $value : '';
    }
}
