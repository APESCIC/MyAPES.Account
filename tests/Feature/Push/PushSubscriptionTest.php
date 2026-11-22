<?php

namespace Tests\Feature\Push;

use App\Core\Accounts\User;
use App\Core\Secrets\SecretRegistry;
use App\Core\Settings\AppSetting;
use App\Services\AuthorizationProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use NotificationChannels\WebPush\PushSubscription;
use Tests\TestCase;

class PushSubscriptionTest extends TestCase
{
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

    public function test_guest_is_refused_subscription_changes(): void
    {
        $payload = VapidTestKeys::subscriptionPayload('guest');

        $this->postJson(route('push.subscriptions.store'), $payload)->assertUnauthorized();
        $this->deleteJson(route('push.subscriptions.destroy'), ['endpoint' => $payload['endpoint']])->assertUnauthorized();
    }

    public function test_signed_in_user_can_save_and_remove_several_device_subscriptions(): void
    {
        $first = VapidTestKeys::subscriptionPayload('one');
        $second = VapidTestKeys::subscriptionPayload('two');

        $this->actingAs($this->user)
            ->postJson(route('push.subscriptions.store'), $first)
            ->assertOk()
            ->assertJson(['saved' => true]);

        $this->actingAs($this->user)
            ->postJson(route('push.subscriptions.store'), $second)
            ->assertOk();

        $this->assertSame(2, PushSubscription::query()->count());

        $this->actingAs($this->user)
            ->deleteJson(route('push.subscriptions.destroy'), ['endpoint' => $first['endpoint']])
            ->assertOk()
            ->assertJson(['removed' => true]);

        $this->assertSame(1, PushSubscription::query()->count());
        $this->assertDatabaseMissing('push_subscriptions', ['endpoint' => $first['endpoint']]);
    }

    public function test_saving_the_same_endpoint_twice_updates_one_row(): void
    {
        $payload = VapidTestKeys::subscriptionPayload('duplicate');

        $this->actingAs($this->user)->postJson(route('push.subscriptions.store'), $payload)->assertOk();
        $this->actingAs($this->user)->postJson(route('push.subscriptions.store'), $payload)->assertOk();

        $this->assertSame(1, PushSubscription::query()->count());
    }

    public function test_invalid_endpoints_are_refused_with_422(): void
    {
        $http = VapidTestKeys::subscriptionPayload('http');
        $http['endpoint'] = 'http://fcm.googleapis.com/fcm/send/http';

        $this->actingAs($this->user)
            ->postJson(route('push.subscriptions.store'), $http)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['endpoint']);

        $evil = VapidTestKeys::subscriptionPayload('evil');
        $evil['endpoint'] = 'https://evil.example.test/push';

        $this->actingAs($this->user)
            ->postJson(route('push.subscriptions.store'), $evil)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['endpoint']);
    }

    public function test_suspended_user_gets_no_push_targets_and_subscriptions_are_removed(): void
    {
        $payload = VapidTestKeys::subscriptionPayload('suspended');
        $this->actingAs($this->user)->postJson(route('push.subscriptions.store'), $payload)->assertOk();
        $this->assertSame(1, PushSubscription::query()->count());

        $this->user->forceFill(['suspended_at' => now()])->save();

        $this->assertSame(0, PushSubscription::query()->count());
        $this->assertCount(0, $this->user->fresh()->routeNotificationForWebPush());
    }

    public function test_deleted_user_subscriptions_are_removed(): void
    {
        $payload = VapidTestKeys::subscriptionPayload('deleted');
        $this->actingAs($this->user)->postJson(route('push.subscriptions.store'), $payload)->assertOk();

        $this->user->delete();

        $this->assertSame(0, PushSubscription::query()->count());
    }

    public function test_only_public_vapid_key_reaches_the_browser(): void
    {
        $response = $this->getJson(route('push.vapid-public-key'))
            ->assertOk()
            ->assertJson(['publicKey' => VapidTestKeys::PUBLIC]);

        $encoded = json_encode($response->json(), JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString(VapidTestKeys::PRIVATE, $encoded);
        $this->assertArrayNotHasKey('privateKey', $response->json());
    }

    public function test_saved_private_vapid_key_is_encrypted_and_never_leaks(): void
    {
        app(SecretRegistry::class)->set(SecretRegistry::VAPID_PRIVATE_KEY, VapidTestKeys::PRIVATE, $this->user->id);

        $row = AppSetting::query()
            ->where('group', SecretRegistry::GROUP)
            ->where('key', SecretRegistry::VAPID_PRIVATE_KEY)
            ->sole();

        $this->assertTrue($row->is_secret);
        $this->assertNotSame(VapidTestKeys::PRIVATE, $row->value);
        $this->assertSame(VapidTestKeys::PRIVATE, Crypt::decryptString($row->value));

        $json = $this->getJson(route('push.vapid-public-key'))->assertOk()->getContent();
        $this->assertStringNotContainsString(VapidTestKeys::PRIVATE, $json);

        $payload = VapidTestKeys::subscriptionPayload('redact');
        $subscriptionResponse = $this->actingAs($this->user)
            ->postJson(route('push.subscriptions.store'), $payload)
            ->assertOk()
            ->getContent();
        $this->assertStringNotContainsString(VapidTestKeys::PRIVATE, $subscriptionResponse);
    }
}
