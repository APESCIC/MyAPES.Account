<?php

namespace App\Core\Providers;

use App\Core\Accounts\User;
use App\Core\Push\CanReceivePush;
use App\Core\Push\PushDeliveryRecorder;
use App\Core\Push\PushPayloadBuilder;
use App\Core\Push\PushNotificationTypeRegistry;
use App\Core\Push\PushPreferenceInitializer;
use App\Core\Push\PushPreferenceReader;
use App\Core\Push\PushRecipientFilter;
use App\Core\Push\PushReportHandler;
use App\Core\Push\PushRoleResolver;
use App\Core\Push\QueuedWebPushChannel;
use App\Core\Push\VapidCredentials;
use App\Core\Push\WebPushDeliveryService;
use App\Core\Push\WebPushFactory;
use App\Core\Secrets\SecretDefinition;
use App\Core\Secrets\SecretRegistry;
use Illuminate\Notifications\ChannelManager;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\ServiceProvider;
use Minishlink\WebPush\WebPush;
use NotificationChannels\WebPush\ReportHandlerInterface;
use NotificationChannels\WebPush\WebPushChannel;

class CorePushServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(VapidCredentials::class);
        $this->app->singleton(PushNotificationTypeRegistry::class);
        $this->app->singleton(PushRoleResolver::class);
        $this->app->singleton(PushPreferenceReader::class);
        $this->app->singleton(PushPreferenceInitializer::class);
        $this->app->singleton(PushPayloadBuilder::class);
        $this->app->singleton(PushRecipientFilter::class);
        $this->app->singleton(CanReceivePush::class);
        $this->app->singleton(WebPushFactory::class);
        $this->app->singleton(PushDeliveryRecorder::class);
        $this->app->singleton(WebPushDeliveryService::class);

        $this->app->bind(ReportHandlerInterface::class, PushReportHandler::class);

        $this->app->singleton(WebPush::class, fn ($app): WebPush => $app->make(WebPushFactory::class)->make());

        $this->app->bind(WebPushChannel::class, QueuedWebPushChannel::class);

        $this->app->afterResolving(SecretRegistry::class, static function (SecretRegistry $registry): void {
            $registry->register(
                new SecretDefinition(
                    SecretRegistry::VAPID_PUBLIC_KEY,
                    'Web Push VAPID public key',
                    'VAPID_PUBLIC_KEY',
                    'Public VAPID key for browser push subscriptions.',
                ),
                new SecretDefinition(
                    SecretRegistry::VAPID_PRIVATE_KEY,
                    'Web Push VAPID private key',
                    'VAPID_PRIVATE_KEY',
                    'Private VAPID key for signing push messages.',
                ),
                new SecretDefinition(
                    SecretRegistry::VAPID_SUBJECT,
                    'Web Push VAPID subject',
                    'VAPID_SUBJECT',
                    'mailto: or https: contact for the VAPID JWT subject.',
                ),
            );
        });
    }

    public function boot(): void
    {
        $this->applyVapidConfig();

        $this->app->afterResolving(ChannelManager::class, static function (ChannelManager $manager, $app): void {
            $manager->extend('WebPush', fn () => $app->make(QueuedWebPushChannel::class));
        });

        User::deleting(static function (User $user): void {
            $user->pushSubscriptions()->delete();
        });

        User::updated(static function (User $user): void {
            if (! $user->wasChanged('suspended_at') || $user->suspended_at === null) {
                return;
            }

            $user->pushSubscriptions()->delete();
        });
    }

    private function applyVapidConfig(): void
    {
        $credentials = $this->app->make(VapidCredentials::class);

        if (! $credentials->isConfigured()) {
            return;
        }

        Config::set('webpush.vapid.public_key', $credentials->publicKey());
        Config::set('webpush.vapid.private_key', $credentials->privateKey());
        Config::set('webpush.vapid.subject', $credentials->subject());
    }
}