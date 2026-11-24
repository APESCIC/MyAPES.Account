<?php

namespace Tests\Feature\Push;

use App\Core\Accounts\AuditLog;
use App\Core\Accounts\User;
use App\Core\Push\CanReceivePush;
use App\Core\Push\PushNotificationTypeKeys;
use App\Core\Push\PushPreferenceInitializer;
use App\Core\Push\PushRecipientContext;
use App\Core\Push\PushRecipientFilter;
use App\Core\Push\UserNotificationSetting;
use App\Core\Push\UserPushDivisionPreference;
use App\Core\Push\UserPushPreference;
use App\Services\AuthorizationProfile;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Minishlink\WebPush\MessageSentReport;
use Minishlink\WebPush\WebPush;
use NotificationChannels\WebPush\PushSubscription;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Push\Concerns\InteractsWithFakeWebPush;
use Tests\TestCase;

class ProfileNotificationsTest extends TestCase
{
    use InteractsWithFakeWebPush;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'app-settings.env' => array_fill_keys(array_keys((array) config('app-settings.env')), null),
            'app-settings.env.VAPID_PUBLIC_KEY' => VapidTestKeys::PUBLIC,
            'app-settings.env.VAPID_PRIVATE_KEY' => VapidTestKeys::PRIVATE,
            'app-settings.env.VAPID_SUBJECT' => VapidTestKeys::SUBJECT,
        ]);
    }

    public function test_legacy_profile_notifications_hash_maps_to_privacy(): void
    {
        $user = User::factory()->protectedRole(AuthorizationProfile::ROLE_STAFF)->create();

        $this->actingAs($user)
            ->get(route('profile.edit'))
            ->assertOk()
            ->assertSee('account\\/privacy#notifications', false);
    }

    #[DataProvider('roleTypeExpectations')]
    public function test_privacy_lists_only_types_available_to_each_role(string $role, array $mustSeeKeys, array $mustNotSeeKeys): void
    {
        $user = User::factory()->protectedRole($role)->create([
            'email_verified_at' => now(),
            'onboarding_completed_at' => now(),
        ]);

        $response = $this->actingAs($user)->get(route('account.privacy'));
        $response->assertOk();

        foreach ($mustSeeKeys as $labelKey) {
            $response->assertSee(__($labelKey), false);
        }

        foreach ($mustNotSeeKeys as $labelKey) {
            $response->assertDontSee(__($labelKey), false);
        }
    }

    /**
     * @return array<string, array{0: string, 1: list<string>, 2: list<string>}>
     */
    public static function roleTypeExpectations(): array
    {
        return [
            'staff' => [
                AuthorizationProfile::ROLE_STAFF,
                ['push.types.ticket_created', 'push.types.account_security_change'],
                ['push.types.ticket_staff_reply'],
            ],
            'service_user' => [
                AuthorizationProfile::ROLE_SERVICE_USER,
                ['push.types.ticket_staff_reply', 'push.types.account_security_change'],
                ['push.types.ticket_created'],
            ],
            'administrator' => [
                AuthorizationProfile::ROLE_ADMINISTRATOR,
                ['push.types.mail_import_attention'],
                ['push.types.ticket_staff_reply'],
            ],
            'volunteer' => [
                AuthorizationProfile::ROLE_VOLUNTEER,
                ['push.types.ticket_client_reply', 'push.types.ticket_created'],
                ['push.types.ticket_staff_reply'],
            ],
            'student' => [
                AuthorizationProfile::ROLE_STUDENT,
                ['push.types.ticket_assigned', 'push.types.recruitment_application_update'],
                ['push.types.mail_import_attention'],
            ],
        ];
    }

    public function test_saving_shows_confirmation_and_audits_without_endpoints_or_keys(): void
    {
        $staff = User::factory()->protectedRole(AuthorizationProfile::ROLE_STAFF)->create();
        app(PushPreferenceInitializer::class)->seedDefaultsForUser($staff);

        $response = $this->actingAs($staff)
            ->from(route('account.privacy'))
            ->put(route('profile.notifications.update'), [
                'push' => [PushNotificationTypeKeys::TICKET_CLIENT_REPLY => '1'],
                'email' => [PushNotificationTypeKeys::TICKET_CLIENT_REPLY => '1'],
                'quiet_hours_enabled' => '1',
                'quiet_hours_start' => '22:00',
                'quiet_hours_end' => '07:00',
            ]);

        $response->assertRedirect(route('account.privacy').'#notifications');
        $response->assertSessionHas('status', __('flash.notifications_updated'));

        $log = AuditLog::query()->where('event', 'profile.notifications.updated')->latest('id')->first();
        $this->assertNotNull($log);
        $context = json_encode($log->context, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('endpoint', strtolower($context));
        $this->assertStringNotContainsString('p256dh', strtolower($context));
        $this->assertStringNotContainsString('auth', strtolower($context));
    }

    public function test_security_email_cannot_be_turned_off(): void
    {
        $user = User::factory()->protectedRole(AuthorizationProfile::ROLE_SERVICE_USER)->create();
        app(PushPreferenceInitializer::class)->seedDefaultsForUser($user);

        $this->actingAs($user)
            ->put(route('profile.notifications.update'), [
                'push' => [PushNotificationTypeKeys::ACCOUNT_SECURITY_CHANGE => '0'],
                'email' => [PushNotificationTypeKeys::ACCOUNT_SECURITY_CHANGE => '0'],
            ])
            ->assertRedirect();

        $preference = UserPushPreference::query()
            ->where('user_id', $user->id)
            ->where('type', PushNotificationTypeKeys::ACCOUNT_SECURITY_CHANGE)
            ->firstOrFail();

        $this->assertTrue($preference->email_enabled);
    }

    public function test_quiet_hours_block_push_in_recipient_filter(): void
    {
        $staff = $this->staffWithDevice();
        UserPushPreference::query()->updateOrCreate(
            ['user_id' => $staff->id, 'type' => PushNotificationTypeKeys::TICKET_CLIENT_REPLY],
            ['enabled' => true, 'email_enabled' => true],
        );
        UserNotificationSetting::query()->create([
            'user_id' => $staff->id,
            'quiet_hours_enabled' => true,
            'quiet_hours_start' => '22:00:00',
            'quiet_hours_end' => '07:00:00',
        ]);

        $this->travelTo(now()->setTimezone('Europe/London')->setTime(23, 0));

        $context = new PushRecipientContext(
            PushNotificationTypeKeys::TICKET_CLIENT_REPLY,
            actorUserId: $staff->id + 1,
        );

        $this->assertFalse(app(PushRecipientFilter::class)->allows($staff->fresh(['notificationSetting']), $context));
        $this->assertFalse(app(CanReceivePush::class)($staff->fresh(['notificationSetting']), $context));
    }

    public function test_division_toggle_blocks_push_for_that_division(): void
    {
        $staff = $this->staffWithDevice();
        UserPushPreference::query()->updateOrCreate(
            ['user_id' => $staff->id, 'type' => PushNotificationTypeKeys::TICKET_CREATED],
            ['enabled' => true, 'email_enabled' => true],
        );
        UserPushDivisionPreference::query()->create([
            'user_id' => $staff->id,
            'division_key' => 'apes-cic',
            'enabled' => false,
        ]);

        $context = new PushRecipientContext(
            PushNotificationTypeKeys::TICKET_CREATED,
            actorUserId: $staff->id + 1,
            divisionPermission: 'apes-cic.tickets.view-all',
        );

        $this->assertFalse(app(PushRecipientFilter::class)->allows($staff, $context));
    }

    public function test_remove_deletes_only_the_chosen_device(): void
    {
        $user = User::factory()->protectedRole(AuthorizationProfile::ROLE_STAFF)->create();
        $first = VapidTestKeys::subscriptionPayload('device-one');
        $second = VapidTestKeys::subscriptionPayload('device-two');
        $this->actingAs($user)->postJson(route('push.subscriptions.store'), $first)->assertOk();
        $this->actingAs($user)->postJson(route('push.subscriptions.store'), $second)->assertOk();

        $removeId = (int) PushSubscription::query()->where('endpoint', $first['endpoint'])->value('id');

        $this->actingAs($user)
            ->delete(route('profile.notifications.devices.destroy', $removeId))
            ->assertRedirect();

        $this->assertDatabaseMissing('push_subscriptions', ['endpoint' => $first['endpoint']]);
        $this->assertDatabaseHas('push_subscriptions', ['endpoint' => $second['endpoint']]);
    }

    public function test_send_test_rate_limits_to_five_in_ten_minutes(): void
    {
        $user = User::factory()->protectedRole(AuthorizationProfile::ROLE_STAFF)->create();
        $payload = VapidTestKeys::subscriptionPayload('rate-limit-device');
        $this->actingAs($user)->postJson(route('push.subscriptions.store'), $payload)->assertOk();

        $device = PushSubscription::query()->where('endpoint', $payload['endpoint'])->firstOrFail();

        $this->mockWebPushForSubscription($device);

        RateLimiter::clear('profile-push-test:'.$user->id);

        for ($i = 0; $i < 5; $i++) {
            $this->actingAs($user)
                ->post(route('profile.notifications.devices.test', $device->id))
                ->assertRedirect();
        }

        $this->actingAs($user)
            ->from(route('account.privacy'))
            ->post(route('profile.notifications.devices.test', $device->id))
            ->assertRedirect(route('account.privacy'))
            ->assertSessionHasErrors('test');
    }

    public function test_send_test_targets_only_the_chosen_device(): void
    {
        $user = User::factory()->protectedRole(AuthorizationProfile::ROLE_STAFF)->create();
        $first = VapidTestKeys::subscriptionPayload('device-a');
        $second = VapidTestKeys::subscriptionPayload('device-b');
        $this->actingAs($user)->postJson(route('push.subscriptions.store'), $first)->assertOk();
        $this->actingAs($user)->postJson(route('push.subscriptions.store'), $second)->assertOk();

        $secondDevice = PushSubscription::query()->where('endpoint', $second['endpoint'])->firstOrFail();

        $endpoints = [];
        $mock = $this->mock(WebPush::class);
        $mock->shouldReceive('queueNotification')->once()->andReturnUsing(function ($subscription) use (&$endpoints): void {
            $endpoints[] = $subscription->getEndpoint();
        });
        $mock->shouldReceive('flush')->once()->andReturnUsing(function () use ($secondDevice): \Generator {
            yield new MessageSentReport(
                new Request('POST', $secondDevice->endpoint),
                new Response(201),
                true,
                'OK',
            );
        });

        RateLimiter::clear('profile-push-test:'.$user->id);

        $this->actingAs($user)
            ->post(route('profile.notifications.devices.test', $secondDevice->id))
            ->assertRedirect();

        $this->assertSame([$secondDevice->endpoint], $endpoints);
    }

    private function mockWebPushForSubscription(PushSubscription $subscription): void
    {
        $mock = $this->mock(WebPush::class);
        $mock->shouldReceive('queueNotification')->andReturnNull();
        $mock->shouldReceive('flush')->andReturnUsing(function () use ($subscription): \Generator {
            yield new MessageSentReport(
                new Request('POST', $subscription->endpoint),
                new Response(201),
                true,
                'OK',
            );
        });
    }

    public function test_switches_include_screen_reader_labels_and_touch_targets(): void
    {
        $user = User::factory()->protectedRole(AuthorizationProfile::ROLE_STAFF)->create();

        $this->actingAs($user)
            ->get(route('account.privacy'))
            ->assertOk()
            ->assertSee('push-pref-switch__input', false)
            ->assertSee('visually-hidden', false)
            ->assertSee(__('push.profile.push_for', ['type' => __('push.types.ticket_created')]), false);
    }

    private function staffWithDevice(): User
    {
        $user = User::factory()->protectedRole(AuthorizationProfile::ROLE_STAFF)->create([
            'email_verified_at' => now(),
            'onboarding_completed_at' => now(),
        ]);
        $payload = VapidTestKeys::subscriptionPayload('device-'.$user->id);
        $this->actingAs($user)->postJson(route('push.subscriptions.store'), $payload)->assertOk();
        app(PushPreferenceInitializer::class)->seedDefaultsForUser($user);

        return $user;
    }
}
