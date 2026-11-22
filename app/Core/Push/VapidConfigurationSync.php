<?php

namespace App\Core\Push;

use Illuminate\Support\Facades\Config;

/**
 * Pushes resolved VAPID material into runtime config after an admin change (#803).
 */
final class VapidConfigurationSync
{
    public function __construct(private readonly VapidCredentials $credentials) {}

    public function sync(): void
    {
        if (! $this->credentials->isConfigured()) {
            Config::set('webpush.vapid.public_key', null);
            Config::set('webpush.vapid.private_key', null);
            Config::set('webpush.vapid.subject', null);

            return;
        }

        Config::set('webpush.vapid.public_key', $this->credentials->publicKey());
        Config::set('webpush.vapid.private_key', $this->credentials->privateKey());
        Config::set('webpush.vapid.subject', $this->credentials->subject());
    }
}