<?php

namespace App\Http\Controllers\Admin\Settings;

use App\Core\Accounts\User;
use App\Core\Mail\Inbound\InboundMailSettings;
use App\Core\Secrets\SecretRegistry;

/**
 * What Admin → Settings → Keys and secrets shows (#810). Secret values are
 * never sent to the page: only status, date and who set them.
 */
final class KeysAndSecretsTab
{
    public function __construct(
        private readonly SecretRegistry $secrets,
        private readonly InboundMailSettings $inbound,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function viewData(): array
    {
        $mailbox = $this->secrets->status(SecretRegistry::INBOUND_MAIL_IMAP_PASSWORD);
        $setBy = $mailbox['set_by'] !== null
            ? User::query()->find($mailbox['set_by'])
            : null;

        return [
            'mailboxPassword' => [
                'key' => SecretRegistry::INBOUND_MAIL_IMAP_PASSWORD,
                'source' => $mailbox['source'],
                'set_at' => $mailbox['set_at'],
                'set_by_name' => $setBy?->name,
                'active_source' => $this->inbound->passwordSource(),
            ],
            'serverOnly' => [
                'APP_KEY' => config('app.key') ? 'set' : 'missing',
                'INBOUND_MAIL_TOKEN_KEY' => $this->inbound->tokenKey() !== '' ? 'set' : 'missing',
            ],
        ];
    }
}
