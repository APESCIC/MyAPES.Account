<?php

namespace App\Http\Controllers\Admin;

use App\Core\Mail\Inbound\ConnectionPanel;
use App\Core\Mail\Inbound\Diagnostics\DiagnosticReport;
use App\Core\Mail\Inbound\ImportMonitor;
use App\Core\Mail\Inbound\ImportReadiness;
use App\Core\Mail\Inbound\InboundEmail;
use App\Core\Mail\Inbound\InboundMailboxLogEntry;
use App\Core\Mail\Inbound\InboundMailSettings;
use App\Core\Mail\Inbound\ReplyMailboxStatus;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * What Admin → Emails → Import shows (#735, #774, #811, #813). Secrets are
 * never sent to the page: only status and source.
 */
final class EmailImportPage
{
    private const PROBE_WAIT_MINUTES = 10;

    public function __construct(
        private readonly InboundMailSettings $inbound,
        private readonly ReplyMailboxStatus $status,
        private readonly ImportReadiness $readiness,
        private readonly ConnectionPanel $connection,
        private readonly ImportMonitor $monitor,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function viewData(Request $request): array
    {
        $statusFilter = $request->query('status');
        $statusFilter = is_string($statusFilter) ? $statusFilter : null;
        $monitor = $this->monitor->data($statusFilter);

        return [
            'repliesEnabled' => $this->inbound->repliesEnabled(),
            'pollingConfigured' => $this->inbound->pollingConfigured(),
            'address' => $this->inbound->address(),
            'connectionRows' => $this->connection->rows(),
            'lastTest' => $this->status->lastTest(),
            'readiness' => $this->readiness->items(),
            'log' => InboundMailboxLogEntry::recent(),
            'report' => is_array(session('mailbox_report')) ? DiagnosticReport::fromArray(session('mailbox_report')) : null,
            'lastPoll' => $this->status->lastPoll(),
            'lastSuccessfulPollAt' => $this->status->lastSuccessfulPollAt(),
            'nextPollAt' => $this->status->backingOff() ? $this->status->nextPollAt() : null,
            'consecutiveFailures' => $this->status->consecutiveFailures(),
            'probe' => $this->probe(),
            'monitor' => $monitor,
            'monitorStatuses' => array_combine(
                InboundEmail::STATUSES,
                array_map(InboundEmail::labelForStatus(...), InboundEmail::STATUSES),
            ),
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
