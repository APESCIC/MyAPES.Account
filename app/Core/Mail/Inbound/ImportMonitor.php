<?php

namespace App\Core\Mail\Inbound;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Health, counts, chart and recent imports for Admin → Emails → Import (#813).
 * Never loads message bodies — only metadata, and the subject while the raw
 * file is still within retention.
 */
final class ImportMonitor
{
    public const STATE_OFF = 'off';

    public const STATE_WORKING = 'working';

    public const STATE_BACKING_OFF = 'backing_off';

    public const STATE_FAILING = 'failing';

    public const STATES = [
        self::STATE_OFF,
        self::STATE_WORKING,
        self::STATE_BACKING_OFF,
        self::STATE_FAILING,
    ];

    /** Status keys shown in the count strip (held = quarantined). */
    public const COUNT_KEYS = [
        'fetched',
        'posted',
        'held',
        'rejected',
        'ignored',
        'failed',
        'duplicates',
    ];

    public const RECENT_LIMIT = 25;

    public const CHART_DAYS = 14;

    public function __construct(
        private readonly InboundMailSettings $settings,
        private readonly ReplyMailboxStatus $status,
        private readonly InboundReplyRegistry $registry,
    ) {}

    /**
     * @return array{
     *     health: array{state: string, label: string, last_check: ?Carbon, last_success: ?Carbon, next_check: ?Carbon, failures: int},
     *     counts: array{hours_24: array<string, int>, days_7: array<string, int>},
     *     chart: list<array{day: string, posted: int, held: int, failed: int}>,
     *     recent: list<array{id: int, at: ?Carbon, sender: string, record: ?string, record_url: ?string, status: string, status_label: string, reason: ?string, subject: ?string}>,
     *     status_filter: ?string,
     * }
     */
    public function data(?string $statusFilter = null): array
    {
        $filter = is_string($statusFilter) && in_array($statusFilter, InboundEmail::STATUSES, true)
            ? $statusFilter
            : null;

        return [
            'health' => $this->health(),
            'counts' => [
                'hours_24' => $this->countsSince(now()->subDay()),
                'days_7' => $this->countsSince(now()->subDays(7)),
            ],
            'chart' => $this->chart(),
            'recent' => $this->recent($filter),
            'status_filter' => $filter,
        ];
    }

    /**
     * @return array{state: string, label: string, last_check: ?Carbon, last_success: ?Carbon, next_check: ?Carbon, failures: int}
     */
    public function health(): array
    {
        $state = $this->healthState();

        return [
            'state' => $state,
            'label' => __("admin.emails.import.monitor.health.{$state}"),
            'last_check' => $this->status->lastPoll()['at'] ?? null,
            'last_success' => $this->status->lastSuccessfulPollAt(),
            'next_check' => $this->status->backingOff() ? $this->status->nextPollAt() : null,
            'failures' => $this->status->consecutiveFailures(),
        ];
    }

    public function healthState(): string
    {
        if (! $this->settings->repliesEnabled() || ! $this->settings->pollingConfigured()) {
            return self::STATE_OFF;
        }

        if ($this->status->backingOff()) {
            return self::STATE_BACKING_OFF;
        }

        if ($this->status->consecutiveFailures() > 0) {
            return self::STATE_FAILING;
        }

        return self::STATE_WORKING;
    }

    /**
     * @return array<string, int>
     */
    public function countsSince(Carbon $since): array
    {
        $rows = InboundEmail::query()
            ->where('received_at', '>=', $since)
            ->selectRaw('status, COUNT(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        $posted = (int) ($rows[InboundEmail::STATUS_POSTED] ?? 0);
        $held = (int) ($rows[InboundEmail::STATUS_QUARANTINED] ?? 0);
        $rejected = (int) ($rows[InboundEmail::STATUS_REJECTED] ?? 0);
        $ignored = (int) ($rows[InboundEmail::STATUS_IGNORED] ?? 0);
        $failed = (int) ($rows[InboundEmail::STATUS_FAILED] ?? 0);
        $received = (int) ($rows[InboundEmail::STATUS_RECEIVED] ?? 0);
        $discarded = (int) ($rows[InboundEmail::STATUS_DISCARDED] ?? 0);

        $duplicates = (int) InboundMailboxLogEntry::query()
            ->where('kind', InboundMailboxLogEntry::KIND_POLL)
            ->where('created_at', '>=', $since)
            ->sum('duplicates');

        return [
            'fetched' => $posted + $held + $rejected + $ignored + $failed + $received + $discarded,
            'posted' => $posted,
            'held' => $held,
            'rejected' => $rejected,
            'ignored' => $ignored,
            'failed' => $failed,
            'duplicates' => $duplicates,
        ];
    }

    /**
     * @return list<array{day: string, posted: int, held: int, failed: int}>
     */
    public function chart(): array
    {
        $start = now()->startOfDay()->subDays(self::CHART_DAYS - 1);
        $driver = DB::connection()->getDriverName();
        $dayExpression = match ($driver) {
            'sqlite' => "strftime('%Y-%m-%d', received_at)",
            default => 'DATE(received_at)',
        };

        $rows = InboundEmail::query()
            ->where('received_at', '>=', $start)
            ->whereIn('status', [
                InboundEmail::STATUS_POSTED,
                InboundEmail::STATUS_QUARANTINED,
                InboundEmail::STATUS_FAILED,
            ])
            ->selectRaw("{$dayExpression} as day, status, COUNT(*) as aggregate")
            ->groupBy('day', 'status')
            ->get();

        $byDay = [];
        foreach ($rows as $row) {
            $day = (string) $row->day;
            $byDay[$day] ??= ['posted' => 0, 'held' => 0, 'failed' => 0];
            $key = match ((string) $row->status) {
                InboundEmail::STATUS_POSTED => 'posted',
                InboundEmail::STATUS_QUARANTINED => 'held',
                InboundEmail::STATUS_FAILED => 'failed',
                default => null,
            };
            if ($key !== null) {
                $byDay[$day][$key] = (int) $row->aggregate;
            }
        }

        $chart = [];
        for ($i = 0; $i < self::CHART_DAYS; $i++) {
            $day = $start->copy()->addDays($i)->toDateString();
            $chart[] = [
                'day' => $day,
                'posted' => $byDay[$day]['posted'] ?? 0,
                'held' => $byDay[$day]['held'] ?? 0,
                'failed' => $byDay[$day]['failed'] ?? 0,
            ];
        }

        return $chart;
    }

    /**
     * @return list<array{id: int, at: ?Carbon, sender: string, record: ?string, record_url: ?string, status: string, status_label: string, reason: ?string, subject: ?string}>
     */
    public function recent(?string $statusFilter = null): array
    {
        /** @var Collection<int, InboundEmail> $emails */
        $emails = InboundEmail::query()
            ->with('user:id,name,email')
            ->when($statusFilter !== null, static fn (Builder $query) => $query->where('status', $statusFilter))
            ->latest('id')
            ->limit(self::RECENT_LIMIT)
            ->get([
                'id',
                'from_address',
                'from_domain',
                'from_name',
                'subject',
                'status',
                'reason',
                'record_prefix',
                'record_id',
                'user_id',
                'raw_path',
                'raw_pruned_at',
                'received_at',
            ]);

        return $emails->map(function (InboundEmail $email): array {
            $record = $email->record_prefix !== null && $email->record_id !== null
                ? $email->record_prefix.$email->record_id
                : null;

            return [
                'id' => (int) $email->id,
                'at' => $email->received_at,
                'sender' => $email->user?->name
                    ?? $email->from_address
                    ?? $email->from_domain
                    ?? __('admin.emails.import.monitor.unknown_sender'),
                'record' => $record,
                'record_url' => $this->recordUrl($email),
                'status' => (string) $email->status,
                'status_label' => $email->statusLabel(),
                'reason' => $email->reasonLabel(),
                'subject' => $email->hasRaw() ? $email->subject : null,
            ];
        })->all();
    }

    private function recordUrl(InboundEmail $email): ?string
    {
        if ($email->record_prefix === null || $email->record_id === null) {
            return null;
        }

        $handler = $this->registry->handler($email->record_prefix);
        $record = $handler?->find((int) $email->record_id);

        return $handler === null || $record === null ? null : $handler->url($record);
    }
}
