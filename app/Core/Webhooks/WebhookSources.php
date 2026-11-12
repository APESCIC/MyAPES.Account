<?php

namespace App\Core\Webhooks;

use Closure;
use Illuminate\Database\Eloquent\Model;

/**
 * Lets a plugin send created/updated webhooks for its own records (#641)
 * without Core knowing the plugin: the plugin names the events, how to find
 * the record's organisation and the id key used in the payload.
 */
final class WebhookSources
{
    private const IGNORED_FIELDS = ['created_at', 'updated_at'];

    /**
     * @param  class-string<Model>  $model
     * @param  Closure(Model): ?string  $organisation
     */
    public static function changes(string $model, string $createdEvent, string $updatedEvent, string $idKey, Closure $organisation): void
    {
        $model::created(static function (Model $record) use ($createdEvent, $idKey, $organisation): void {
            app(WebhookDispatcher::class)->dispatch($createdEvent, array_filter([$organisation($record)]), [$idKey => $record->getKey()]);
        });

        $model::updated(static function (Model $record) use ($updatedEvent, $idKey, $organisation): void {
            $changes = self::changedFields($record);
            if ($changes !== []) {
                app(WebhookDispatcher::class)->dispatch($updatedEvent, array_filter([$organisation($record)]), [$idKey => $record->getKey(), 'changes' => $changes]);
            }
        });
    }

    /**
     * @return list<string>
     */
    public static function changedFields(Model $record): array
    {
        $changed = $record->wasRecentlyCreated ? array_keys($record->getAttributes()) : array_keys($record->getChanges());

        return array_values(array_diff($changed, self::IGNORED_FIELDS, [$record->getKeyName()]));
    }
}
