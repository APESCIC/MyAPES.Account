<?php

namespace Tests\Feature\Push;

use App\Core\Push\WebPushFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Minishlink\WebPush\WebPush;
use Tests\TestCase;

/**
 * Lazy VAPID sync keeps package discovery off the database (#805).
 */
class PushVapidLazySyncTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'app-settings.env' => array_fill_keys(array_keys((array) config('app-settings.env')), null),
            'app-settings.env.VAPID_PUBLIC_KEY' => VapidTestKeys::PUBLIC,
            'app-settings.env.VAPID_PRIVATE_KEY' => VapidTestKeys::PRIVATE,
            'app-settings.env.VAPID_SUBJECT' => VapidTestKeys::SUBJECT,
            'webpush.vapid.public_key' => null,
            'webpush.vapid.private_key' => null,
            'webpush.vapid.subject' => null,
        ]);
    }

    public function test_vapid_runtime_config_stays_empty_until_web_push_is_built(): void
    {
        $this->assertNull(config('webpush.vapid.public_key'));

        app(WebPushFactory::class)->make();

        $this->assertSame(VapidTestKeys::PUBLIC, config('webpush.vapid.public_key'));
        $this->assertSame(VapidTestKeys::PRIVATE, config('webpush.vapid.private_key'));
        $this->assertSame(VapidTestKeys::SUBJECT, config('webpush.vapid.subject'));
    }

    public function test_resolving_the_web_push_client_applies_vapid_config(): void
    {
        $client = app(WebPush::class);

        $this->assertInstanceOf(WebPush::class, $client);
        $this->assertSame(VapidTestKeys::PUBLIC, config('webpush.vapid.public_key'));
    }
}