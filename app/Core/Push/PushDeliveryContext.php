<?php

namespace App\Core\Push;

/**
 * Carries per-send metadata into the web push report handler (#800).
 */
final class PushDeliveryContext
{
    private static ?self $current = null;

    public function __construct(
        public readonly string $notificationType,
        public readonly int $attempt,
        public readonly ?int $userId,
    ) {}

    public static function run(self $context, callable $callback): mixed
    {
        $previous = self::$current;
        self::$current = $context;

        try {
            return $callback();
        } finally {
            self::$current = $previous;
        }
    }

    public static function current(): ?self
    {
        return self::$current;
    }
}