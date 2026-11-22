<?php

namespace App\Core\Push;

use App\Core\Secrets\SecretRegistry;

/**
 * Resolves Web Push VAPID credentials from Keys and secrets, then .env (#792).
 */
final class VapidCredentials
{
    public const STATUS_CONFIGURED = 'Configured';

    public const STATUS_NOT_CONFIGURED = 'Not configured';

    public function __construct(private readonly SecretRegistry $secrets) {}

    public function publicKey(): ?string
    {
        return $this->secrets->get(SecretRegistry::VAPID_PUBLIC_KEY);
    }

    public function privateKey(): ?string
    {
        return $this->secrets->get(SecretRegistry::VAPID_PRIVATE_KEY);
    }

    public function subject(): ?string
    {
        return $this->secrets->get(SecretRegistry::VAPID_SUBJECT);
    }

    public function isConfigured(): bool
    {
        return filled($this->publicKey())
            && filled($this->privateKey())
            && filled($this->subject());
    }

    /**
     * @return self::STATUS_*
     */
    public function statusLabel(): string
    {
        return $this->isConfigured() ? self::STATUS_CONFIGURED : self::STATUS_NOT_CONFIGURED;
    }

    /**
     * @return SecretRegistry::SOURCE_*
     */
    public function source(): string
    {
        if (! $this->isConfigured()) {
            return SecretRegistry::SOURCE_MISSING;
        }

        foreach ([
            SecretRegistry::VAPID_PUBLIC_KEY,
            SecretRegistry::VAPID_PRIVATE_KEY,
            SecretRegistry::VAPID_SUBJECT,
        ] as $key) {
            if ($this->secrets->source($key) === SecretRegistry::SOURCE_KEYS) {
                return SecretRegistry::SOURCE_KEYS;
            }
        }

        return SecretRegistry::SOURCE_ENV;
    }
}
