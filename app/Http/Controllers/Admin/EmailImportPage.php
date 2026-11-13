<?php

namespace App\Http\Controllers\Admin;

use App\Core\Mail\Inbound\Diagnostics\DiagnosticReport;
use App\Core\Mail\Inbound\ImportReadiness;
use App\Core\Mail\Inbound\InboundMailboxLogEntry;
use App\Core\Mail\Inbound\InboundMailboxSettings;
use App\Core\Mail\Inbound\InboundMailSettings;
use App\Core\Mail\Inbound\ReplyMailboxStatus;
use App\Core\Settings\AppSettings;
use Illuminate\Support\Carbon;

/**
 * What Admin → Emails → Import shows (#735, #774). The password is never
 * sent to the page: only whether one is saved.
 */
final class EmailImportPage
{
    private const PROBE_WAIT_MINUTES = 10;

    public function __construct(
        private readonly InboundMailSettings $inbound,
        private readonly AppSettings $settings,
        private readonly ReplyMailboxStatus $status,
        private readonly ImportReadiness $readiness,
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
            'readiness' => $this->readiness->items(),
            'log' => InboundMailboxLogEntry::recent(),
            'report' => is_array(session('mailbox_report')) ? DiagnosticReport::fromArray(session('mailbox_report')) : null,
            'lastPoll' => $this->status->lastPoll(),
            'lastSuccessfulPollAt' => $this->status->lastSuccessfulPollAt(),
            'nextPollAt' => $this->status->backingOff() ? $this->status->nextPollAt() : null,
            'consecutiveFailures' => $this->status->consecutiveFailures(),
            'probe' => $this->probe(),
            'encryptions' => collect(InboundMailboxSettings::encryptions())
                ->mapWithKeys(fn (string $encryption): array => [$encryption => __("admin.emails.import.encryption.{$encryption}")])
                ->all(),
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
                $probe['arrived_at'] !== null => __('admin.emails.import.probe_arrived', ['at' => $probe['arrived_at']->format('Y-m-d H:i')]),
                $probe['sent_at']->lt(now()->subMinutes(self::PROBE_WAIT_MINUTES)) => __('admin.emails.import.probe_not_arrived', ['minutes' => self::PROBE_WAIT_MINUTES]),
                default => __('admin.emails.import.probe_waiting'),
            },
        ];
    }
}
