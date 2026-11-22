<?php

namespace Tests\Feature\Push\Concerns;

use Generator;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Minishlink\WebPush\MessageSentReport;
use Minishlink\WebPush\WebPush;
use NotificationChannels\WebPush\PushSubscription;

trait InteractsWithFakeWebPush
{
    /**
     * @param  list<MessageSentReport>  $reports
     */
    protected function mockWebPushReports(array $reports): void
    {
        $index = 0;
        $mock = $this->mock(WebPush::class);
        $mock->shouldReceive('queueNotification')->times(count($reports));
        $mock->shouldReceive('flush')->times(count($reports))->andReturnUsing(
            function () use ($reports, &$index): Generator {
                $report = $reports[$index];
                $index++;

                yield $report;
            }
        );
    }

    protected function report(PushSubscription $subscription, int $statusCode, bool $success = false): MessageSentReport
    {
        return new MessageSentReport(
            new Request('POST', $subscription->endpoint),
            new Response($statusCode),
            $success,
            $success ? 'OK' : 'delivery failed',
        );
    }
}