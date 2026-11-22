<?php

namespace Tests\Feature\Push;

/**
 * Deterministic base64url VAPID material for push feature tests (#792).
 */
final class VapidTestKeys
{
    public const PUBLIC = 'BEl62iUYgUivxIkv9yuoVe-VueBkzLKTI5UQLI0kPzHroj5eWS-FB71xXQB47w-Odk4w0vWc3lXkaF_WlDd_Ea4';

    public const PRIVATE = 'Vk6602VsY3UfFQZPTq0T-mMH-4JnN0A-avdT_rNh2XY';

    public const SUBJECT = 'mailto:web-push-test@myapes.test';

    public const SUBSCRIPTION_P256DH = 'BNcRdreALRHDz8IuH0gihT94d-i9y4p9QT21R-wWiBhM2spdiu23rWVTHWkjhGPQhvuFla6HwS16M8M9mq-h6R8';

    public const SUBSCRIPTION_AUTH = 'kRhjWqHxQnHJCm8tFuFQ7A';

    public static function fcmEndpoint(string $id): string
    {
        return 'https://fcm.googleapis.com/fcm/send/'.$id;
    }

    /**
     * @return array<string, mixed>
     */
    public static function subscriptionPayload(string $id): array
    {
        return [
            'endpoint' => self::fcmEndpoint($id),
            'keys' => [
                'p256dh' => self::SUBSCRIPTION_P256DH,
                'auth' => self::SUBSCRIPTION_AUTH,
            ],
            'contentEncoding' => 'aesgcm',
        ];
    }
}
