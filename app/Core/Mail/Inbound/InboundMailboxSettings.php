<?php

namespace App\Core\Mail\Inbound;

use App\Core\Mail\Inbound\Diagnostics\MailboxDiagnostics;
use App\Core\Settings\SettingDefinition;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * The reply mailbox settings admins edit in Admin → Emails → Import (#735,
 * #774), stored in the shared settings store (#732). Each has an .env
 * variable that wins when it is set.
 */
final class InboundMailboxSettings
{
    public const GROUP = 'inbound_mailbox';

    public const ENCRYPTIONS = ['ssl', 'tls', 'none'];

    /**
     * @return list<SettingDefinition>
     */
    public static function definitions(): array
    {
        $define = static fn (string $key, string $type, ?string $env, mixed $default, array $rules, bool $secret = false): SettingDefinition => new SettingDefinition(self::GROUP, $key, $type, $secret, $env, $default, $rules);

        return [
            $define('enabled', SettingDefinition::BOOLEAN, 'INBOUND_MAIL_ENABLED', false, ['boolean']),
            $define('address', SettingDefinition::STRING, 'INBOUND_MAIL_ADDRESS', 'communications@myapes.me.uk', ['email:rfc', 'max:255']),
            $define('plus_addressing', SettingDefinition::BOOLEAN, 'INBOUND_MAIL_PLUS_ADDRESSING', true, ['boolean']),
            $define('host', SettingDefinition::STRING, 'INBOUND_MAIL_IMAP_HOST', config('inbound-mail.imap.host') ?: 'mail.cloudron.apes.org.uk', ['string', 'max:255', 'regex:/^[A-Za-z0-9.-]+$/']),
            $define('port', SettingDefinition::INTEGER, 'INBOUND_MAIL_IMAP_PORT', 993, ['integer', 'min:1', 'max:65535']),
            $define('encryption', SettingDefinition::STRING, 'INBOUND_MAIL_IMAP_ENCRYPTION', 'ssl', [Rule::in(self::encryptions())]),
            $define('validate_cert', SettingDefinition::BOOLEAN, 'INBOUND_MAIL_IMAP_VALIDATE_CERT', true, ['boolean']),
            $define('username', SettingDefinition::STRING, 'INBOUND_MAIL_IMAP_USERNAME', null, ['string', 'max:255']),
            $define('password', SettingDefinition::STRING, 'INBOUND_MAIL_IMAP_PASSWORD', null, ['string', 'max:500'], secret: true),
            $define('folder', SettingDefinition::STRING, 'INBOUND_MAIL_IMAP_FOLDER', 'INBOX', ['string', 'max:255']),
        ];
    }

    /**
     * No encryption is offered only on a developer machine (#773).
     *
     * @return list<string>
     */
    public static function encryptions(): array
    {
        return app()->environment(['local', 'testing']) ? self::ENCRYPTIONS : ['ssl', 'tls'];
    }

    /**
     * Refuses a port and encryption that can never work together, such as 993 with STARTTLS (#773).
     *
     * @throws ValidationException
     */
    public static function guardPortAndEncryption(int $port, string $encryption): void
    {
        if (MailboxDiagnostics::mismatched($port, $encryption)) {
            throw ValidationException::withMessages([
                'encryption' => __('admin.emails.import.encryption_mismatch', [
                    'port' => $port,
                    'encryption' => __($port === 993 ? 'admin.emails.import.encryption.ssl' : 'admin.emails.import.encryption.tls'),
                ]),
            ]);
        }
    }
}
