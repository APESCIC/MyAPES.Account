<?php

namespace App\Core\Push;

use NotificationChannels\WebPush\WebPushMessageInterface;

/**
 * Declarative (8030) and service-worker JSON for one private push (#799).
 */
final class WebPushPayload implements WebPushMessageInterface
{
    private function __construct(
        private readonly string $title,
        private readonly string $body,
        private readonly string $lang,
        private readonly string $tag,
        private readonly string $path,
        private readonly string $navigate,
        private readonly string $icon,
        private readonly string $badge,
        private readonly ?string $topic,
    ) {}

    public static function make(
        string $title,
        string $body,
        string $lang,
        string $tag,
        string $path,
        string $navigate,
        string $icon,
        string $badge,
        ?string $topic = null,
    ): self {
        return new self($title, $body, $lang, $tag, $path, $navigate, $icon, $badge, $topic);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $worker = [
            'title' => $this->title,
            'body' => $this->body,
            'lang' => $this->lang,
            'tag' => $this->tag,
            'icon' => $this->icon,
            'badge' => $this->badge,
            'url' => $this->path,
            'data' => [
                'url' => $this->path,
            ],
        ];

        return [
            'web_push' => (int) config('push-payloads.declarative_version', 8030),
            'notification' => [
                'title' => $this->title,
                'body' => $this->body,
                'lang' => $this->lang,
                'dir' => 'ltr',
                'tag' => $this->tag,
                'icon' => $this->icon,
                'badge' => $this->badge,
                'navigate' => $this->navigate,
            ],
            ...$worker,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function getOptions(): array
    {
        $options = [
            'TTL' => (int) config('push-payloads.ttl_seconds', 86_400),
            'urgency' => (string) config('push-payloads.urgency', 'normal'),
        ];

        if ($this->topic !== null && $this->topic !== '') {
            $options['topic'] = $this->topic;
        }

        return $options;
    }
}