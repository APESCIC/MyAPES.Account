<?php

namespace App\Core\Mail\Inbound;

use App\Core\Accounts\User;
use App\Core\Mail\Inbound\Diagnostics\FailureType;
use App\Notifications\InboundImportFailingNotification;
use App\Notifications\InboundImportRecoveredNotification;
use App\Services\AuthorizationProfile;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;

/**
 * Tells administrators when email import stops working and when it recovers
 * (#779): after 3 failed polls in a row, or 15 minutes of failures, an
 * incident opens with a banner and one email; the next successful poll
 * closes it with one "working again" email.
 */
final class ImportAlerts
{
    public const FAILURES_BEFORE_ALERT = 3;

    public const MINUTES_BEFORE_ALERT = 15;

    public const HEALTH_OK = 'ok';

    public const HEALTH_FAILING = 'failing';

    public const HEALTH_OFF = 'off';

    private const INCIDENT_KEY = 'inbound-mail:incident';

    public function __construct(
        private readonly ReplyMailboxStatus $status,
        private readonly InboundMailSettings $settings,
    ) {}

    /**
     * @param  array{result: string, failure?: string}  $result
     */
    public function afterPoll(array $result): void
    {
        if ($result['result'] === InboundMailPoller::RESULT_POLLED) {
            if ($this->incident() !== null) {
                Cache::forget(self::INCIDENT_KEY);
                Notification::send($this->administrators(), new InboundImportRecoveredNotification);
            }

            return;
        }

        if ($result['result'] !== InboundMailPoller::RESULT_UNAVAILABLE || $this->incident() !== null || ! $this->thresholdReached()) {
            return;
        }

        $failure = FailureType::tryFrom($result['failure'] ?? '') ?? FailureType::ConnectionRefused;
        Cache::forever(self::INCIDENT_KEY, ['opened_at' => now()->toIso8601String(), 'failure' => $failure->value]);
        Notification::send($this->administrators(), new InboundImportFailingNotification($failure, $this->status->consecutiveFailures()));
    }

    /**
     * @return array{opened_at: Carbon, failure: FailureType}|null
     */
    public function incident(): ?array
    {
        $incident = Cache::get(self::INCIDENT_KEY);
        if (! is_array($incident)) {
            return null;
        }

        return [
            'opened_at' => Carbon::parse($incident['opened_at']),
            'failure' => FailureType::tryFrom((string) ($incident['failure'] ?? '')) ?? FailureType::ConnectionRefused,
        ];
    }

    /**
     * For /healthz: only "ok", "failing" or "off", never a host, user or error.
     */
    public function health(): string
    {
        return match (true) {
            ! $this->settings->pollingConfigured() => self::HEALTH_OFF,
            $this->incident() !== null => self::HEALTH_FAILING,
            default => self::HEALTH_OK,
        };
    }

    private function thresholdReached(): bool
    {
        $since = $this->status->failingSince();

        return $this->status->consecutiveFailures() >= self::FAILURES_BEFORE_ALERT
            || ($since !== null && $since->lte(now()->subMinutes(self::MINUTES_BEFORE_ALERT)));
    }

    /**
     * @return Collection<int, User>
     */
    private function administrators(): Collection
    {
        return User::query()
            ->whereNull('suspended_at')
            ->whereHas('roles', static fn ($roles) => $roles->where('name', AuthorizationProfile::ROLE_ADMINISTRATOR))
            ->get();
    }
}
