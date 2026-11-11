<?php

namespace App\Http\Controllers\Admin\Settings;

use App\Core\Mail\Inbound\InboundMailboxSettings;
use App\Core\Mail\Inbound\InboundMailSettings;
use App\Core\Mail\Inbound\ReplyMailboxStatus;
use App\Core\Settings\AppSettings;
use Illuminate\Support\Carbon;

/**
 * What Admin → Settings → Reply mailbox shows (#733, #735). The password is
 * never sent to the page: only whether one is saved.
 */
final class ReplyMailboxTab
{
    private const PROBE_WAIT_MINUTES = 10;

    public function __construct(
        private readonly InboundMailSettings $inbound,
        private readonly AppSettings $settings,
        private readonly ReplyMailboxStatus $status,
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
            'lastTest' => $this->status->lastTest(),
            'lastPoll' => $this->status->lastPoll(),
            'lastSuccessfulPollAt' => $this->status->lastSuccessfulPollAt(),
            'probe' => $this->probe(),
            'encryptions' => [
                'ssl' => __('admin.settings.reply_mailbox.encryption.ssl'),
                'tls' => __('admin.settings.reply_mailbox.encryption.tls'),
                'none' => __('admin.settings.reply_mailbox.encryption.none'),
            ],
        ];
    }

    /**
     * @return array{sent_at: Carbon, state: string}|null
     */
    private function probe(): ?array
    {
        $probe = $this->status->probe();
        if ($probe === null) {
            return null;
        }

        return [
            'sent_at' => $probe['sent_at'],
            'state' => match (true) {
                $probe['arrived_at'] !== null => __('admin.settings.reply_mailbox.probe_arrived', ['at' => $probe['arrived_at']->format('Y-m-d H:i')]),
                $probe['sent_at']->lt(now()->subMinutes(self::PROBE_WAIT_MINUTES)) => __('admin.settings.reply_mailbox.probe_not_arrived', ['minutes' => self::PROBE_WAIT_MINUTES]),
                default => __('admin.settings.reply_mailbox.probe_waiting'),
            },
        ];
    }
}
