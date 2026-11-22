<?php

namespace App\Core\Push;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Notification;
use Minishlink\WebPush\ContentEncoding;
use Minishlink\WebPush\MessageSentReport;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;
use NotificationChannels\WebPush\PushSubscription;
use NotificationChannels\WebPush\ReportHandlerInterface;
use NotificationChannels\WebPush\WebPushMessageInterface;

/**
 * Sends one web push to a single stored subscription (#800).
 */
class WebPushDeliveryService
{
    public function __construct(
        protected WebPush $webPush,
        protected ReportHandlerInterface $reportHandler,
    ) {}

    /**
     * @return array{retry: bool, retry_after_seconds: int|null, expired: bool}
     */
    public function send(
        Model $notifiable,
        Notification $notification,
        PushSubscription $subscription,
        int $attempt,
    ): array {
        if (! method_exists($notification, 'toWebPush')) {
            return ['retry' => false, 'retry_after_seconds' => null, 'expired' => false];
        }

        /** @var WebPushMessageInterface $message */
        $message = $notification->toWebPush($notifiable, $notification);
        $payload = json_encode($message->toArray(), JSON_THROW_ON_ERROR);
        $options = $message->getOptions();

        $userId = $notifiable instanceof Model && $notifiable->getKey() !== null
            ? (int) $notifiable->getKey()
            : null;

        $this->webPush->queueNotification(
            new Subscription(
                $subscription->endpoint,
                $subscription->public_key,
                $subscription->auth_token,
                $subscription->content_encoding ?? ContentEncoding::aes128gcm
            ),
            $payload,
            $options,
        );

        $reports = $this->webPush->flush();
        $report = $this->firstMatchingReport($reports, $subscription);

        if ($report === null) {
            return ['retry' => true, 'retry_after_seconds' => 60, 'expired' => false];
        }

        PushDeliveryContext::run(
            new PushDeliveryContext($notification::class, $attempt, $userId),
            fn (): null => $this->reportHandler->handleReport($report, $subscription, $message) ?? null,
        );

        if ($report->isSuccess()) {
            return ['retry' => false, 'retry_after_seconds' => null, 'expired' => false];
        }

        if ($report->isSubscriptionExpired()) {
            return ['retry' => false, 'retry_after_seconds' => null, 'expired' => true];
        }

        $statusCode = $report->getResponse()?->getStatusCode();
        if ($statusCode !== null && $this->isRetryableStatus($statusCode) && $attempt < (int) config('myapes.push.max_attempts', 3)) {
            return [
                'retry' => true,
                'retry_after_seconds' => $this->retryAfterSeconds($report, $attempt),
                'expired' => false,
            ];
        }

        return ['retry' => false, 'retry_after_seconds' => null, 'expired' => false];
    }

    private function firstMatchingReport(\Generator $reports, PushSubscription $subscription): ?MessageSentReport
    {
        foreach ($reports as $report) {
            /** @var MessageSentReport $report */
            if ($report->getEndpoint() === $subscription->endpoint) {
                return $report;
            }
        }

        return null;
    }

    private function isRetryableStatus(int $statusCode): bool
    {
        return $statusCode === 429 || $statusCode >= 500;
    }

    private function retryAfterSeconds(MessageSentReport $report, int $attempt): int
    {
        $retryAfter = $report->getResponse()?->getHeaderLine('Retry-After');
        if ($retryAfter !== '' && ctype_digit($retryAfter)) {
            return max(1, min(3600, (int) $retryAfter));
        }

        return match ($attempt) {
            1 => 30,
            2 => 120,
            default => 300,
        };
    }
}