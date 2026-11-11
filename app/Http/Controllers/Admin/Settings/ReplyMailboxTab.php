<?php

namespace App\Http\Controllers\Admin\Settings;

use App\Core\Mail\Inbound\InboundMailboxSettings;
use App\Core\Mail\Inbound\InboundMailSettings;
use App\Core\Settings\AppSettings;

/**
 * What Admin → Settings → Reply mailbox shows (#733, #735). The password is
 * never sent to the page: only whether one is saved.
 */
final class ReplyMailboxTab
{
    public function __construct(
        private readonly InboundMailSettings $inbound,
        private readonly AppSettings $settings,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function viewData(): array
    {
        $fields = [];
        foreach ($this->settings->definitions(InboundMailboxSettings::GROUP) as $key => $definition) {
            $fields[$key] = [
                'value' => $definition->secret ? null : $this->settings->get(InboundMailboxSettings::GROUP, $key),
                'env' => $this->settings->overriddenByEnv(InboundMailboxSettings::GROUP, $key),
            ];
        }

        return [
            'repliesEnabled' => $this->inbound->repliesEnabled(),
            'pollingConfigured' => $this->inbound->pollingConfigured(),
            'address' => $this->inbound->address(),
            'fields' => $fields,
            'passwordSaved' => $this->settings->stored(InboundMailboxSettings::GROUP, 'password'),
            'encryptions' => [
                'ssl' => __('admin.settings.reply_mailbox.encryption.ssl'),
                'tls' => __('admin.settings.reply_mailbox.encryption.tls'),
                'none' => __('admin.settings.reply_mailbox.encryption.none'),
            ],
        ];
    }
}
