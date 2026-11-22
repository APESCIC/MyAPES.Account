<?php

namespace Tests\Feature\Push;

use App\Core\Accounts\User;
use App\Core\Mail\OutboundMailLog;
use App\Core\Push\PushDelivery;
use App\Core\Push\WebPushDeliveryService;
use App\Jobs\SendWebPush;
use App\Services\AuthorizationProfile;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use NotificationChannels\WebPush\PushSubscription;
use Tests\Feature\Push\Concerns\InteractsWithFakeWebPush;
use Tests\Feature\Push\Fixtures\DeskTestPushNotification;
use Tests\TestCase;

/**
 * Queued delivery, retries, logging and pruning (#800).
 */
class PushDeliveryTest extends TestCase
{
    use InteractsWithFakeWebPush;
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'app-settings.env' => array_fill_keys(array_keys((array) config('app-settings.env')), null),
            'app-settings.env.VAPID_PUBLIC_KEY' => VapidTestKeys::PUBLIC,
            'app-settings.env.VAPID_PRIVATE_KEY' => VapidTestKeys::PRIVATE,
            'app-settings.env.VAPID_SUBJECT' => VapidTestKeys::SUBJECT,
        ]);

        $this->user = User::factory()->protectedRole(AuthorizationProfile::ROLE_SERVICE_USER)->create([
            'email_verified_at' => now(),
            'onboarding_completed_at' => now(),
        ]);
    }

    public function test_expired_responses_remove_the_subscription(): void
    {
        $subscription = $this->storeSubscription('expired-device');
        Http::fake([$subscription->endpoint => Http::response('', 410)]);
        $this->mockWebPushReports([$this->report($subscription, 410)]);

        $this->deliver($subscription, 1);

        $this->assertDatabaseMissing('push_subscriptions', ['id' => $subscription->id]);
        $this->assertSame(
            PushDelivery::STATUS_EXPIRED,
            PushDelivery::query()->latest('id')->value('status'),
        );
    }

    public function test_not_found_responses_remove_the_subscription(): void
    {
        $subscription = $this->storeSubscription('missing-device');
        Http::fake([$subscription->endpoint => Http::response('', 404)]);
        $this->mockWebPushReports([$this->report($subscription, 404)]);

        $this->deliver($subscription, 1);

        $this->assertDatabaseMissing('push_subscriptions', ['id' => $subscription->id]);
    }

    public function test_retryable_errors_retry_up_to_three_times_without_duplicate_success(): void
    {
        $subscription = $this->storeSubscription('flaky-device');
        $notification = new DeskTestPushNotification;

        Http::fake([
            $subscription->endpoint => Http::sequence()
                ->push('', 503)
                ->push('', 429)
                ->push('', 200),
        ]);
        $this->mockWebPushReports([
            $this->report($subscription, 503),
            $this->report($subscription, 429),
            $this->report($subscription, 200, success: true),
        ]);

        $service = app(WebPushDeliveryService::class);

        $first = $service->send($this->user, $notification, $subscription, 1);
        $this->assertTrue($first['retry']);

        $second = $service->send($this->user, $notification, $subscription->fresh(), 2);
        $this->assertTrue($second['retry']);

        $third = $service->send($this->user, $notification, $subscription->fresh(), 3);
        $this->assertFalse($third['retry']);

        $this->assertSame(1, PushDelivery::query()->where('status', PushDelivery::STATUS_SENT)->count());
        $this->assertSame(2, PushDelivery::query()->where('status', PushDelivery::STATUS_FAILED)->count());
    }

    public function test_delivery_log_never_stores_payload_endpoint_or_keys(): void
    {
        $subscription = $this->storeSubscription('logged-device');
        $this->mockWebPushReports([$this->report($subscription, 200, success: true)]);

        $this->deliver($subscription, 1);

        $columns = Schema::getColumnListing('push_deliveries');
        foreach (['payload', 'endpoint', 'public_key', 'auth_token', 'body', 'title', 'p256dh', 'keys'] as $forbidden) {
            $this->assertNotContains($forbidden, $columns);
        }

        $stored = json_encode(PushDelivery::query()->get()->toArray(), JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString($subscription->endpoint, $stored);
        $this->assertStringNotContainsString($subscription->public_key, $stored);
        $this->assertStringNotContainsString($subscription->auth_token, $stored);
        $this->assertStringNotContainsString('private push body', $stored);
        $this->assertSame(PushDelivery::hashSubscription($subscription->id), PushDelivery::query()->value('subscription_hash'));
    }

    public function test_push_prune_log_deletes_old_rows_and_runs_daily(): void
    {
        $hash = PushDelivery::hashSubscription(99);
        PushDelivery::query()->create([
            'user_id' => $this->user->id,
            'subscription_hash' => $hash,
            'notification_type' => DeskTestPushNotification::class,
            'result_code' => 200,
            'status' => PushDelivery::STATUS_SENT,
            'attempts' => 1,
            'created_at' => now()->subDays(91),
        ]);
        PushDelivery::query()->create([
            'user_id' => $this->user->id,
            'subscription_hash' => $hash,
            'notification_type' => DeskTestPushNotification::class,
            'result_code' => 200,
            'status' => PushDelivery::STATUS_SENT,
            'attempts' => 1,
            'created_at' => now()->subDays(2),
        ]);

        $this->artisan('push:prune-log')
            ->expectsOutputToContain('Deleted 1 push delivery record(s) older than 90 days.')
            ->assertSuccessful();

        $this->assertSame(1, PushDelivery::query()->count());

        $event = collect(app(Schedule::class)->events())
            ->first(fn ($event): bool => str_contains((string) $event->command, 'push:prune-log'));

        $this->assertNotNull($event);
        $this->assertSame('0 0 * * *', $event->expression);
    }

    public function test_a_failing_push_does_not_stop_mail_or_database_notifications(): void
    {
        $subscription = $this->storeSubscription('isolated-device');
        $this->mockWebPushReports([$this->report($subscription, 503)]);

        $this->user->notify(new DeskTestPushNotification);

        $this->assertDatabaseHas('outbound_mail_logs', [
            'notification_type' => DeskTestPushNotification::class,
            'status' => OutboundMailLog::STATUS_SENT,
            'user_id' => $this->user->id,
        ]);
        $this->assertDatabaseHas('notifications', [
            'notifiable_type' => 'user',
            'notifiable_id' => $this->user->id,
        ]);
        $this->assertGreaterThan(0, PushDelivery::query()->where('status', PushDelivery::STATUS_FAILED)->count());
    }

    public function test_push_jobs_use_the_push_queue(): void
    {
        $job = new SendWebPush(
            $this->user,
            new DeskTestPushNotification,
            1,
        );

        $this->assertSame('push', $job->queue);
    }

    public function test_cloudron_queue_worker_includes_the_push_queue(): void
    {
        $script = file_get_contents(base_path('scripts/deploy/cloudron-run.sh'));

        $this->assertStringContainsString('--queue=default,push', $script);
    }

    private function storeSubscription(string $id): PushSubscription
    {
        $payload = VapidTestKeys::subscriptionPayload($id);
        $this->actingAs($this->user)->postJson(route('push.subscriptions.store'), $payload)->assertOk();

        return PushSubscription::query()->where('endpoint', $payload['endpoint'])->firstOrFail();
    }

    private function deliver(PushSubscription $subscription, int $attempt): void
    {
        app(WebPushDeliveryService::class)->send(
            $this->user,
            new DeskTestPushNotification,
            $subscription,
            $attempt,
        );
    }
}