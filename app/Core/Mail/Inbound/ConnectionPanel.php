<?php

namespace App\Core\Mail\Inbound;

/**
 * Read-only connection values and where each one comes from (#811).
 */
final class ConnectionPanel
{
    public const SOURCE_BUILT_IN = 'built_in';

    public const SOURCE_ENV = 'env';

    public const SOURCE_KEYS = 'keys_and_secrets';

    public const SOURCE_MISSING = 'missing';

    public function __construct(
        private readonly InboundMailSettings $inbound,
    ) {}

    /**
     * @return list<array{key: string, value: ?string, source: string, secret: bool}>
     */
    public function rows(): array
    {
        return [
            $this->row('address', $this->inbound->address(), $this->configSource('INBOUND_MAIL_ADDRESS', 'inbound-mail.address', 'communications@myapes.me.uk')),
            $this->row('host', $this->inbound->imap('host'), $this->configSource('INBOUND_MAIL_IMAP_HOST', 'inbound-mail.imap.host', 'mail.cloudron.apes.org.uk')),
            $this->row('port', $this->inbound->imap('port'), $this->configSource('INBOUND_MAIL_IMAP_PORT', 'inbound-mail.imap.port', '993')),
            $this->row('encryption', $this->inbound->imap('encryption') ?: 'ssl', $this->configSource('INBOUND_MAIL_IMAP_ENCRYPTION', 'inbound-mail.imap.encryption', 'ssl')),
            $this->row('validate_cert', $this->inbound->validateCert() ? '1' : '0', $this->configSource('INBOUND_MAIL_IMAP_VALIDATE_CERT', 'inbound-mail.imap.validate_cert', '1')),
            $this->row('folder', $this->inbound->imap('folder') ?: 'INBOX', $this->configSource('INBOUND_MAIL_IMAP_FOLDER', 'inbound-mail.imap.folder', 'INBOX')),
            $this->row('plus_addressing', $this->inbound->plusAddressing() ? '1' : '0', $this->configSource('INBOUND_MAIL_PLUS_ADDRESSING', 'inbound-mail.plus_addressing', '1')),
            $this->usernameRow(),
            $this->passwordRow(),
            $this->tokenKeyRow(),
        ];
    }

    /**
     * @return array{key: string, value: ?string, source: string, secret: bool}
     */
    private function usernameRow(): array
    {
        $env = config('app-settings.env.INBOUND_MAIL_IMAP_USERNAME');
        if (is_string($env) && $env !== '') {
            return $this->row('username', $this->inbound->imap('username'), self::SOURCE_ENV);
        }

        $configured = trim((string) config('inbound-mail.imap.username'));
        if ($configured !== '') {
            return $this->row('username', $configured, self::SOURCE_BUILT_IN);
        }

        return $this->row('username', $this->inbound->imap('username'), self::SOURCE_BUILT_IN);
    }

    /**
     * @return array{key: string, value: ?string, source: string, secret: bool}
     */
    private function passwordRow(): array
    {
        $source = match ($this->inbound->passwordSource()) {
            InboundMailSettings::PASSWORD_SOURCE_KEYS => self::SOURCE_KEYS,
            InboundMailSettings::PASSWORD_SOURCE_ENV => self::SOURCE_ENV,
            InboundMailSettings::PASSWORD_SOURCE_LEGACY => self::SOURCE_KEYS,
            default => self::SOURCE_MISSING,
        };

        return $this->row('password', null, $source, secret: true);
    }

    /**
     * @return array{key: string, value: ?string, source: string, secret: bool}
     */
    private function tokenKeyRow(): array
    {
        $present = $this->inbound->tokenKey() !== '';

        return $this->row('token_key', null, $present ? self::SOURCE_ENV : self::SOURCE_MISSING, secret: true);
    }

    /**
     * @return array{key: string, value: ?string, source: string, secret: bool}
     */
    private function row(string $key, ?string $value, string $source, bool $secret = false): array
    {
        return [
            'key' => $key,
            'value' => $secret ? null : $value,
            'source' => $source,
            'secret' => $secret,
        ];
    }

    private function configSource(string $envName, string $configKey, string $builtIn): string
    {
        $env = config('app-settings.env.'.$envName);
        if ($env !== null && $env !== '') {
            return self::SOURCE_ENV;
        }

        $value = config($configKey);

        if ($value === null || $value === '' || (string) $value === $builtIn) {
            return self::SOURCE_BUILT_IN;
        }

        // Non-empty config that differs from the built-in default still came from env() at boot.
        return self::SOURCE_ENV;
    }
}
