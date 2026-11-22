<?php

namespace App\Core\Push;

use InvalidArgumentException;
use LogicException;

/**
 * Catalogue of push notification types. Core owns the registry; plugins register
 * additional types without Core importing them (#796).
 */
final class PushNotificationTypeRegistry
{
    /** @var array<string, PushNotificationType> */
    private array $types = [];

    private bool $booted = false;

    public function register(PushNotificationType ...$types): void
    {
        foreach ($types as $type) {
            if (isset($this->types[$type->key])) {
                throw new LogicException("Push notification type [{$type->key}] is registered twice.");
            }

            $this->types[$type->key] = $type;
        }
    }

    public function bootFromConfig(): void
    {
        if ($this->booted) {
            return;
        }

        $this->booted = true;

        foreach ((array) config('push-notification-types.types', []) as $key => $definition) {
            if (! is_array($definition)) {
                throw new InvalidArgumentException("Push type [{$key}] must be an array.");
            }

            $roleDefaults = [];
            foreach ((array) ($definition['roles'] ?? []) as $role => $default) {
                $roleDefaults[$role] = PushRoleDefault::from((string) $default);
            }

            $this->register(new PushNotificationType(
                (string) $key,
                (string) ($definition['label'] ?? "push.types.{$key}"),
                $roleDefaults,
                (bool) ($definition['security_locked'] ?? false),
                (bool) ($definition['division_scoped'] ?? false),
            ));
        }
    }

    /**
     * @return array<string, PushNotificationType>
     */
    public function all(): array
    {
        $this->bootFromConfig();

        return $this->types;
    }

    public function has(string $key): bool
    {
        $this->bootFromConfig();

        return isset($this->types[$key]);
    }

    public function get(string $key): PushNotificationType
    {
        $this->bootFromConfig();

        return $this->types[$key] ?? throw new InvalidArgumentException("Unknown push notification type [{$key}].");
    }
}