<?php

namespace App\Support\Notifications;

use Illuminate\Notifications\DatabaseNotification;

/**
 * Renders database notifications for the in-app bell and list (#801).
 */
final class AccountNotificationPresenter
{
    /**
     * @return array{
     *     id: string,
     *     title: string,
     *     summary: string|null,
     *     url: string|null,
     *     open_url: string,
     *     read: bool,
     *     created_at: \Illuminate\Support\Carbon|null
     * }
     */
    public static function present(DatabaseNotification $notification): array
    {
        /** @var array<string, mixed> $data */
        $data = $notification->data;

        return [
            'id' => (string) $notification->id,
            'title' => self::title($notification->type, $data),
            'summary' => self::summary($data),
            'url' => self::url($data),
            'open_url' => route('notifications.open', $notification),
            'read' => $notification->read_at !== null,
            'created_at' => $notification->created_at,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function url(array $data): ?string
    {
        $url = $data['url'] ?? null;

        return is_string($url) && $url !== '' ? $url : null;
    }

    public static function sameOriginPath(?string $url): ?string
    {
        if ($url === null || $url === '') {
            return null;
        }

        if (str_starts_with($url, '/')) {
            return $url;
        }

        $parsed = parse_url($url);

        if (! is_array($parsed)) {
            return null;
        }

        $path = $parsed['path'] ?? '/';
        $query = isset($parsed['query']) && $parsed['query'] !== '' ? '?'.$parsed['query'] : '';
        $fragment = isset($parsed['fragment']) && $parsed['fragment'] !== '' ? '#'.$parsed['fragment'] : '';

        return $path.$query.$fragment;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function title(string $type, array $data): string
    {
        foreach (['subject', 'title'] as $key) {
            if (isset($data[$key]) && is_string($data[$key]) && $data[$key] !== '') {
                return $data[$key];
            }
        }

        if (isset($data['file_name']) && is_string($data['file_name']) && $data['file_name'] !== '') {
            return __('notifications.title.attachment_quarantined', ['name' => $data['file_name']]);
        }

        $event = $data['event'] ?? null;

        if (is_string($event) && $event !== '') {
            $translated = __("notifications.event.{$event}");

            if ($translated !== "notifications.event.{$event}") {
                return $translated;
            }
        }

        return __('notifications.title.generic');
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function summary(array $data): ?string
    {
        $event = $data['event'] ?? null;
        $updatedBy = $data['updated_by'] ?? null;

        if (is_string($event) && $event !== '' && is_string($updatedBy) && $updatedBy !== '') {
            $eventLabel = __("notifications.event.{$event}");
            if ($eventLabel === "notifications.event.{$event}") {
                $eventLabel = $event;
            }

            return __('notifications.summary.by', ['event' => $eventLabel, 'name' => $updatedBy]);
        }

        if (is_string($updatedBy) && $updatedBy !== '') {
            return __('notifications.summary.from', ['name' => $updatedBy]);
        }

        return null;
    }
}