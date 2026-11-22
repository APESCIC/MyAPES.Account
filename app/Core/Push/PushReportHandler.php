<?php

declare(strict_types=1);

namespace App\Core\Push;

use Illuminate\Contracts\Events\Dispatcher;
use Minishlink\WebPush\MessageSentReport;
use NotificationChannels\WebPush\Events\NotificationFailed;
use NotificationChannels\WebPush\Events\NotificationSent;
use NotificationChannels\WebPush\PushSubscription;
use NotificationChannels\WebPush\ReportHandlerInterface;
use NotificationChannels\WebPush\WebPushMessageInterface;

/**
 * Logs anonymised delivery metadata and expires stale devices (#800).
 */
class PushReportHandler implements ReportHandlerInterface
{
    public function __construct(
        protected Dispatcher $events,
        protected PushDeliveryRecorder $recorder,
    ) {}

    public function handleReport(MessageSentReport $report, PushSubscription $subscription, WebPushMessageInterface $message): void
    {
        $context = PushDeliveryContext::current();
        $notificationType = $context?->notificationType ?? 'webpush';
        $attempt = $context?->attempt ?? 1;
        $userId = $context?->userId;

        $resultCode = $report->getResponse()?->getStatusCode();
        $subscriptionHash = PushDelivery::hashSubscription((int) $subscription->getKey());

        if ($report->isSuccess()) {
            $this->recorder->recordSent($userId, $subscriptionHash, $notificationType, $resultCode, $attempt);
            $this->resetConsecutiveFailures($subscription);
            $subscription->forceFill(['last_used_at' => now()])->save();
            $this->events->dispatch(new NotificationSent($report, $subscription, $message));

            return;
        }

        if ($report->isSubscriptionExpired()) {
            $this->recorder->recordExpired($userId, $subscriptionHash, $notificationType, $resultCode, $attempt);
            $subscription->delete();
            $this->events->dispatch(new NotificationFailed($report, $subscription, $message));

            return;
        }

        $this->recorder->recordFailed($userId, $subscriptionHash, $notificationType, $resultCode, $attempt);
        $this->incrementConsecutiveFailures($subscription);

        $this->events->dispatch(new NotificationFailed($report, $subscription, $message));
    }

    private function resetConsecutiveFailures(PushSubscription $subscription): void
    {
        if ((int) $subscription->consecutive_failures === 0) {
            return;
        }

        $subscription->forceFill(['consecutive_failures' => 0])->save();
    }

    private function incrementConsecutiveFailures(PushSubscription $subscription): void
    {
        $failures = (int) $subscription->consecutive_failures + 1;
        $subscription->forceFill(['consecutive_failures' => $failures])->save();

        $limit = (int) config('myapes.push.remove_after_consecutive_failures', 5);
        if ($failures >= $limit) {
            $subscription->delete();
        }
    }
}
