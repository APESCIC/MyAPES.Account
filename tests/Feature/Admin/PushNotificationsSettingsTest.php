<?php

namespace Tests\Feature\Admin;

use App\Core\Accounts\AuditLog;
use App\Core\Accounts\User;
use App\Core\Push\CanReceivePush;
use App\Core\Push\PushDelivery;
use App\Core\Push\PushNotificationTypeKeys;
use App\Core\Push\PushPreferenceInitializer;
use App\Core\Push\PushRecipientContext;
use App\Core\Push\PushSettings;
use App\Core\Push\VapidCredentials;
use App\Core\Push\VapidPublicKeyFingerprint;
use App\Core\Secrets\SecretRegistry;
use App\Services\AuthorizationProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use NotificationChannels\WebPush\PushSubscription;
use Tests\Feature\Push\Concerns\InteractsWithFakeWebPush;
use Minishlink\WebPush\VAPID;
use Tests\Feature\Push\VapidTestKeys;
use Tests\TestCase;

/**
 * Admin → Settings → Push notifications (#803).
 */
class PushNotificationsSettingsTest extends TestCase
{
    use InteractsWithFakeWebPush;
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'app-settings.env' => array_fill_keys(array_keys((array) config('app-settings.env')), null),
        ]);

        $this->admin = User::factory()->protectedRole(AuthorizationProfile::ROLE_ADMINISTRATOR)->create();
    }

    public function test_non_admins_get_403_and_admins_need_step_up_before_vapid_changes(): void
    {
        $user = User::factory()->protectedRole(AuthorizationProfile::ROLE_SERVICE_USER)->create();

        $this->actingAs($user)->get(route('admin.settings.show', 'push-notifications'))->assertForbidden();

        $this->actingAs($this->admin)
            ->put(route('admin.settings.push-notifications.vapid.store'), $this->vapidPayload())
            ->assertRedirect(route('password.confirm'));

        $this->actingAs($this->admin)
            ->withSession(['auth.password_confirmed_at' => now()->unix()])
            ->put(route('admin.settings.push-notifications.vapid.store'), $this->vapidPayload())
            ->assertRedirect(route('admin.settings.show', 'push-notifications'))
            ->assertSessionHasNoErrors();
    }

    public function test_saved_vapid_keys_are_write_only_and_fingerprint_is_shown_not_the_public_key(): void
    {
        $this->saveVapidKeys();

        $row = \App\Core\Settings\AppSetting::query()
            ->where('group', SecretRegistry::GROUP)
            ->where('key', SecretRegistry::VAPID_PRIVATE_KEY)
            ->sole();

        $this->assertTrue($row->is_secret);
        $this->assertNotSame(VapidTestKeys::PRIVATE, $row->value);
        $this->assertSame(VapidTestKeys::PRIVATE, Crypt::decryptString($row->value));

        $page = $this->actingAs($this->admin)->get(route('admin.settings.show', 'push-notifications'))->assertOk();
        $html = $page->getContent();

        $this->assertStringNotContainsString(VapidTestKeys::PUBLIC, $html);
        $this->assertStringNotContainsString(VapidTestKeys::PRIVATE, $html);
        $this->assertStringNotContainsString(VapidTestKeys::SUBJECT, $html);
        $page->assertSee(__('admin.settings.push_notifications.vapid.fingerprint', [
            'value' => VapidPublicKeyFingerprint::format(VapidTestKeys::PUBLIC),
        ]), false);

        $json = $this->getJson(route('push.vapid-public-key'))->assertOk()->getContent();
        $this->assertStringNotContainsString(VapidTestKeys::PRIVATE, $json);

        $audit = AuditLog::query()->where('event', 'admin.settings.secret_set')->first();
        $this->assertNotNull($audit);
        $encoded = json_encode($audit->context);
        $this->assertStringNotContainsString(VapidTestKeys::PRIVATE, $encoded);
        $this->assertStringNotContainsString(VapidTestKeys::PUBLIC, $encoded);
    }

    public function test_missing_keys_show_not_configured_and_profiles_show_unavailable_message(): void
    {
        $this->actingAs($this->admin)->get(route('admin.settings.show', 'push-notifications'))
            ->assertOk()
            ->assertSee(VapidCredentials::STATUS_NOT_CONFIGURED, false);

        $html = $this->actingAs($this->admin)->get(route('profile.edit'))->assertOk()->getContent();
        $this->assertStringContainsString(__('push.device.unavailable'), $html);
        $this->assertMatchesRegularExpression('/data-push-actions[^>]*hidden/', $html);
    }

    public function test_pause_stops_pushes_and_is_audited(): void
    {
        $this->seedVapidEnv();
        $staff = User::factory()->protectedRole(AuthorizationProfile::ROLE_STAFF)->create();
        app(PushPreferenceInitializer::class)->seedDefaultsForUser($staff);
        $staff->updatePushSubscription(
            VapidTestKeys::fcmEndpoint('pause-device'),
            VapidTestKeys::SUBSCRIPTION_P256DH,
            VapidTestKeys::SUBSCRIPTION_AUTH,
        );

        $context = new PushRecipientContext(PushNotificationTypeKeys::TICKET_ASSIGNED, null, null);
        $this->assertTrue(app(CanReceivePush::class)($staff->fresh(), $context));

        $this->actingAs($this->admin)
            ->withSession(['auth.password_confirmed_at' => now()->unix()])
            ->put(route('admin.settings.push-notifications.pause.update'), ['paused' => '1'])
            ->assertRedirect(route('admin.settings.show', 'push-notifications'));

        $this->assertTrue(app(PushSettings::class)->paused());
        $this->assertFalse(app(CanReceivePush::class)($staff->fresh(), $context));

        $audit = AuditLog::query()->where('event', 'admin.push.pause_updated')->sole();
        $this->assertSame(['paused' => true], $audit->context);
    }

    public function test_stats_show_counts_only(): void
    {
        $this->seedVapidEnv();
        $named = User::factory()->protectedRole(AuthorizationProfile::ROLE_STAFF)->create(['name' => 'Stats Person Unique']);
        $named->updatePushSubscription(
            VapidTestKeys::fcmEndpoint('stats-device'),
            VapidTestKeys::SUBSCRIPTION_P256DH,
            VapidTestKeys::SUBSCRIPTION_AUTH,
        );

        PushDelivery::query()->create([
            'user_id' => $named->id,
            'subscription_hash' => PushDelivery::hashSubscription(1),
            'notification_type' => PushNotificationTypeKeys::TICKET_ASSIGNED,
            'result_code' => 201,
            'status' => PushDelivery::STATUS_SENT,
            'attempts' => 1,
            'created_at' => now(),
        ]);

        $html = $this->actingAs($this->admin)->get(route('admin.settings.show', 'push-notifications'))->assertOk()->getContent();

        $this->assertStringContainsString('data-push-device-counts', $html);
        $this->assertStringNotContainsString('Stats Person Unique', $html);
        $this->assertStringNotContainsString(VapidTestKeys::fcmEndpoint('stats-device'), $html);
        $this->assertMatchesRegularExpression('/<dd>1<\/dd>/', $html);
    }

    public function test_key_rotation_removes_subscriptions_when_confirmed(): void
    {
        $this->saveVapidKeys();

        $this->admin->updatePushSubscription(
            VapidTestKeys::fcmEndpoint('admin-device'),
            VapidTestKeys::SUBSCRIPTION_P256DH,
            VapidTestKeys::SUBSCRIPTION_AUTH,
        );
        $this->assertSame(1, PushSubscription::query()->count());

        $this->actingAs($this->admin)
            ->withSession(['auth.password_confirmed_at' => now()->unix()])
            ->put(route('admin.settings.push-notifications.vapid.store'), $this->vapidPayload())
            ->assertSessionHasNoErrors();

        $this->assertSame(0, PushSubscription::query()->count());
    }

    public function test_generate_clear_and_replace_each_write_one_audit_row_per_key(): void
    {
        $session = ['auth.password_confirmed_at' => now()->unix()];

        $canGenerateOpenSslEc = true;
        try {
            VAPID::createVapidKeys();
        } catch (\Throwable) {
            $canGenerateOpenSslEc = false;
        }

        if ($canGenerateOpenSslEc) {
            $this->actingAs($this->admin)->withSession($session)
                ->post(route('admin.settings.push-notifications.vapid.generate'), ['subscriptions_confirm' => '1'])
                ->assertSessionHasNoErrors();
        } else {
            $this->actingAs($this->admin)->withSession($session)
                ->put(route('admin.settings.push-notifications.vapid.store'), $this->vapidPayload())
                ->assertSessionHasNoErrors();
        }

        $this->assertSame(3, AuditLog::query()->where('event', 'admin.settings.secret_set')->count());

        $this->actingAs($this->admin)->withSession($session)
            ->put(route('admin.settings.push-notifications.vapid.store'), $this->vapidPayload())
            ->assertSessionHasNoErrors();

        $this->assertSame(3, AuditLog::query()->where('event', 'admin.settings.secret_replaced')->count());

        AuditLog::query()->delete();
        PushSubscription::query()->delete();

        $this->actingAs($this->admin)->withSession($session)
            ->delete(route('admin.settings.push-notifications.vapid.clear'), [
                'confirm' => '1',
                'subscriptions_confirm' => '1',
            ]);

        $this->assertSame(3, AuditLog::query()->where('event', 'admin.settings.secret_cleared')->count());
    }

    public function test_send_test_push_reaches_admins_subscribed_device_after_keys_saved(): void
    {
        $this->saveVapidKeys();

        $this->admin->updatePushSubscription(
            VapidTestKeys::fcmEndpoint('admin-test'),
            VapidTestKeys::SUBSCRIPTION_P256DH,
            VapidTestKeys::SUBSCRIPTION_AUTH,
        );
        $device = PushSubscription::query()->where('subscribable_id', $this->admin->id)->firstOrFail();

        $this->mockWebPushReports([$this->report($device, 201, true)]);

        $this->actingAs($this->admin)
            ->withSession(['auth.password_confirmed_at' => now()->unix()])
            ->post(route('admin.settings.push-notifications.test'))
            ->assertRedirect(route('admin.settings.show', 'push-notifications'))
            ->assertSessionHas('status');

        $this->assertSame(1, AuditLog::query()->where('event', 'admin.push.test_sent')->count());
    }

    /**
     * @param  array<string, string>  $overrides
     * @return array<string, string>
     */
    private function vapidPayload(array $overrides = []): array
    {
        return array_merge([
            'subject' => VapidTestKeys::SUBJECT,
            'public_key' => VapidTestKeys::PUBLIC,
            'private_key' => VapidTestKeys::PRIVATE,
            'subscriptions_confirm' => '1',
        ], $overrides);
    }

    private function saveVapidKeys(): void
    {
        $this->actingAs($this->admin)
            ->withSession(['auth.password_confirmed_at' => now()->unix()])
            ->put(route('admin.settings.push-notifications.vapid.store'), $this->vapidPayload())
            ->assertSessionHasNoErrors();
    }

    private function seedVapidEnv(): void
    {
        config([
            'app-settings.env.VAPID_PUBLIC_KEY' => VapidTestKeys::PUBLIC,
            'app-settings.env.VAPID_PRIVATE_KEY' => VapidTestKeys::PRIVATE,
            'app-settings.env.VAPID_SUBJECT' => VapidTestKeys::SUBJECT,
        ]);
        app(\App\Core\Push\VapidConfigurationSync::class)->sync();
    }
}