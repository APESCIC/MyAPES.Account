<?php

namespace App\Core\Mail\Inbound;

use App\Core\Mail\Inbound\Diagnostics\DiagnosticReport;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Health of the reply mailbox for Admin → Settings (#736): the last
 * connection test, the last poll and the plus-address probe. Holds no
 * credentials and no message content.
 */
final class ReplyMailboxStatus
{
    private const TEST_KEY = 'inbound-mail:last-test';

    private const POLL_KEY = 'inbound-mail:last-poll';

    private const SUCCESSFUL_POLL_KEY = 'inbound-mail:last-successful-poll';

    private const PROBE_KEY = 'inbound-mail:probe';

    private const HEARTBEAT_KEY = 'inbound-mail:heartbeat';

    public const PROBE_PREFIX = 'probe-';

    public function __construct(private readonly InboundMailSettings $settings) {}

    public function recordTest(DiagnosticReport $report): void
    {
        Cache::forever(self::TEST_KEY, ['at' => now()->toIso8601String(), 'report' => $report->toArray()]);
    }

    /**
     * @return array{at: Carbon, ok: bool, report: DiagnosticReport}|null
     */
    public function lastTest(): ?array
    {
        $test = Cache::get(self::TEST_KEY);
        if (! is_array($test) || ! is_array($test['report'] ?? null)) {
            return null;
        }

        $report = DiagnosticReport::fromArray($test['report']);

        return ['at' => Carbon::parse($test['at']), 'ok' => $report->ok(), 'report' => $report];
    }

    /**
     * @param  array{result: string, fetched: int, stored: int, duplicates: int, failed: int}  $result
     */
    public function recordPoll(array $result): void
    {
        $entry = ['at' => now()->toIso8601String(), ...$result];
        Cache::forever(self::POLL_KEY, $entry);

        if ($result['result'] === InboundMailPoller::RESULT_POLLED) {
            Cache::forever(self::SUCCESSFUL_POLL_KEY, $entry['at']);
        }
    }

    /**
     * @return array{at: Carbon, result: string}|null
     */
    public function lastPoll(): ?array
    {
        $poll = Cache::get(self::POLL_KEY);

        return is_array($poll) ? ['at' => Carbon::parse($poll['at']), 'result' => (string) $poll['result']] : null;
    }

    /**
     * The scheduler ran the poll command, whatever its result (#775, #779).
     */
    public function recordHeartbeat(): void
    {
        Cache::forever(self::HEARTBEAT_KEY, now()->toIso8601String());
    }

    public function heartbeatAt(): ?Carbon
    {
        $at = Cache::get(self::HEARTBEAT_KEY);

        return is_string($at) ? Carbon::parse($at) : null;
    }

    public function lastSuccessfulPollAt(): ?Carbon
    {
        $at = Cache::get(self::SUCCESSFUL_POLL_KEY);

        return is_string($at) ? Carbon::parse($at) : null;
    }

    /**
     * Starts a plus-address probe and returns the address to send it to.
     */
    public function startProbe(): string
    {
        $token = Str::lower(Str::random(12));
        Cache::forever(self::PROBE_KEY, ['token' => $token, 'sent_at' => now()->toIso8601String(), 'arrived_at' => null]);
        $mailbox = $this->settings->mailbox() ?? ['local' => 'communications', 'domain' => 'localhost'];

        return $mailbox['local'].'+'.self::PROBE_PREFIX.$token.'@'.$mailbox['domain'];
    }

    /**
     * @return array{sent_at: Carbon, arrived_at: ?Carbon}|null
     */
    public function probe(): ?array
    {
        $probe = Cache::get(self::PROBE_KEY);

        return is_array($probe) ? [
            'sent_at' => Carbon::parse($probe['sent_at']),
            'arrived_at' => $probe['arrived_at'] !== null ? Carbon::parse($probe['arrived_at']) : null,
        ] : null;
    }

    /**
     * Whether any recipient is the current probe address; if so the probe is marked as arrived.
     *
     * @param  list<string>  $recipients
     */
    public function acceptProbe(array $recipients): bool
    {
        $mailbox = $this->settings->mailbox();
        if ($mailbox === null) {
            return false;
        }

        $pattern = '/^'.preg_quote($mailbox['local'], '/').'\+'.preg_quote(self::PROBE_PREFIX, '/').'([a-z0-9]+)@'.preg_quote($mailbox['domain'], '/').'$/i';
        foreach ($recipients as $recipient) {
            if (preg_match($pattern, trim($recipient), $m) !== 1) {
                continue;
            }

            $probe = Cache::get(self::PROBE_KEY);
            if (is_array($probe) && hash_equals((string) $probe['token'], strtolower($m[1])) && $probe['arrived_at'] === null) {
                Cache::forever(self::PROBE_KEY, ['arrived_at' => now()->toIso8601String()] + $probe);
            }

            return true;
        }

        return false;
    }
}
