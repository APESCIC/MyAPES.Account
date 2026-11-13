<?php

namespace App\Core\Mail\Inbound;

use App\Core\Mail\Inbound\Diagnostics\DiagnosticReport;
use App\Core\Mail\Inbound\Diagnostics\FailureType;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One connection test or poll in the recent connection log (#780). Only the
 * newest 50 are kept, and none older than 30 days. Holds no host, username,
 * password, IMAP line or message content: every column is a fixed value.
 *
 * @property int $id
 * @property string $kind
 * @property string $result
 * @property string|null $step
 * @property int $duration_ms
 * @property int|null $fetched
 * @property int|null $stored
 * @property int|null $duplicates
 * @property int|null $failed
 * @property Carbon $created_at
 */
class InboundMailboxLogEntry extends Model
{
    public const KIND_TEST = 'test';

    public const KIND_POLL = 'poll';

    public const KEEP = 50;

    public const RETENTION_DAYS = 30;

    public const UPDATED_AT = null;

    protected $table = 'inbound_mailbox_log';

    protected $fillable = ['kind', 'result', 'step', 'duration_ms', 'fetched', 'stored', 'duplicates', 'failed'];

    protected function casts(): array
    {
        return [
            'duration_ms' => 'integer',
            'fetched' => 'integer',
            'stored' => 'integer',
            'duplicates' => 'integer',
            'failed' => 'integer',
        ];
    }

    public static function recordTest(DiagnosticReport $report): self
    {
        return self::keep([
            'kind' => self::KIND_TEST,
            'result' => $report->failure?->value ?? 'ok',
            'step' => $report->failedStep?->value,
            'duration_ms' => $report->milliseconds(),
        ]);
    }

    /**
     * @param  array{result: string, failure?: string, fetched: int, stored: int, duplicates: int, failed: int}  $result
     */
    public static function recordPoll(array $result, int $milliseconds): self
    {
        return self::keep([
            'kind' => self::KIND_POLL,
            'result' => FailureType::tryFrom($result['failure'] ?? '')?->value ?? $result['result'],
            'duration_ms' => $milliseconds,
            'fetched' => $result['fetched'],
            'stored' => $result['stored'],
            'duplicates' => $result['duplicates'],
            'failed' => $result['failed'],
        ]);
    }

    /**
     * @return Collection<int, self>
     */
    public static function recent(): Collection
    {
        return self::query()->latest('id')->limit(self::KEEP)->get();
    }

    public static function prune(?int $days = null): int
    {
        return self::query()->where('created_at', '<', now()->subDays($days ?? self::RETENTION_DAYS))->delete();
    }

    public function resultLabel(): string
    {
        $failure = FailureType::tryFrom($this->result);

        return $failure?->message() ?? __("admin.emails.import.log.results.{$this->result}");
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private static function keep(array $attributes): self
    {
        $entry = self::query()->create($attributes);

        $oldestKept = self::query()->latest('id')->skip(self::KEEP - 1)->value('id');
        if ($oldestKept !== null) {
            self::query()->where('id', '<', $oldestKept)->delete();
        }

        return $entry;
    }
}
