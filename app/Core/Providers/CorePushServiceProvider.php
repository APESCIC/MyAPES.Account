<?php

namespace App\Core\Providers;

use App\Core\Accounts\User;
use App\Core\Push\VapidCredentials;
use App\Core\Secrets\SecretDefinition;
use App\Core\Secrets\SecretRegistry;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\ServiceProvider;

class CorePushServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(VapidCredentials::class);

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
