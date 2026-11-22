<?php

namespace App\Core\Push;

/**
 * Builds lock-screen-safe push text from notification type and record reference only (#799).
 */
final class PushPayloadBuilder
{
    public function message(string $typeKey, PushTarget $target): WebPushPayload
    {
        $path = PushSameOriginPath::fromRoute($target->routeName, $target->routeParameters);
        $navigate = PushSameOriginPath::absoluteFromRoute($target->routeName, $target->routeParameters);

        return WebPushPayload::make(
            title: (string) config('push-payloads.title', 'MyAPES Account'),
            body: $this->body($typeKey, $target),
            lang: (string) config('push-payloads.lang', 'en-GB'),
            tag: $this->tag($typeKey, $target),
            path: $path,
            navigate: $navigate,
            icon: (string) config('push-payloads.icon', '/icons/pwa-192x192.png'),
            badge: (string) config('push-payloads.badge', '/icons/pwa-192x192.png'),
            topic: $this->topic($typeKey, $target),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function payloadArray(string $typeKey, PushTarget $target): array
    {
        return $this->message($typeKey, $target)->toArray();
    }

    public function encodedSize(string $typeKey, PushTarget $target): int
    {
        return strlen((string) json_encode($this->payloadArray($typeKey, $target), JSON_THROW_ON_ERROR));
    }

    private function body(string $typeKey, PushTarget $target): string
    {
        $slug = str_replace('.', '_', $typeKey);
        $reference = $target->referenceForBody();

        if ($reference === '') {
            return (string) __("push.payload.{$slug}");
        }

        $line = (string) __('push.payload.'.$slug, ['reference' => $reference]);

        if ($target->serviceLabel !== null && $target->serviceLabel !== '') {
            $line .= (string) __('push.payload.service_scope', ['service' => $target->serviceLabel]);
        }

        return $line;
    }

    private function tag(string $typeKey, PushTarget $target): string
    {
        $reference = $target->referenceForBody();

        return $reference === ''
            ? $typeKey
            : "{$typeKey}:{$reference}";
    }

    private function topic(string $typeKey, PushTarget $target): ?string
    {
        if (! str_starts_with($typeKey, 'ticket.')) {
            return null;
        }

        $reference = $target->referenceForBody();

        return $reference === '' ? null : "ticket:{$reference}";
    }
}