<?php

namespace App\Core\Extensions\Settings;

use App\Core\Accounts\User;
use App\Services\ModuleSettingsService;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Add, rename, reorder, archive and delete single rows of a managed list
 * (#471). Rows live in the module's plugin settings; every change goes
 * through ModuleSettingsService::save so structure checks and the
 * optimistic lock still apply. Rows in use are archived, never deleted.
 */
final class ManagedListService
{
    public const REMOVED = 'deleted';

    public const ARCHIVED = 'archived';

    public function __construct(private readonly ModuleSettingsService $settings) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function items(ManagedListDefinition $list): array
    {
        $items = $this->settings->get($list->subCoreKey, $list->moduleKey)[$list->listKey] ?? [];

        return is_array($items) ? array_values($items) : [];
    }

    /**
     * @return array<string, mixed>
     */
    public function add(ManagedListDefinition $list, string $label, ?User $actor = null): array
    {
        $items = $this->items($list);
        $item = [
            'key' => $this->uniqueKey($label, array_column($items, 'key')),
            'label' => $label,
            ...$list->newItemDefaults,
        ];
        $items[] = $item;
        $this->write($list, $items, $actor);

        return $item;
    }

    public function rename(ManagedListDefinition $list, string $key, string $label, ?User $actor = null): void
    {
        $items = $this->items($list);
        $items[$this->indexOf($items, $key)]['label'] = $label;
        $this->write($list, $items, $actor);
    }

    public function move(ManagedListDefinition $list, string $key, string $direction, ?User $actor = null): void
    {
        $items = $this->items($list);
        $index = $this->indexOf($items, $key);
        $swap = $direction === 'up' ? $index - 1 : $index + 1;

        if (! isset($items[$swap])) {
            return;
        }

        [$items[$index], $items[$swap]] = [$items[$swap], $items[$index]];
        $this->write($list, $items, $actor);
    }

    /**
     * Deletes an unused row; archives a row that records still use.
     *
     * @return self::REMOVED|self::ARCHIVED
     */
    public function remove(ManagedListDefinition $list, string $key, ?User $actor = null): string
    {
        $items = $this->items($list);
        $index = $this->indexOf($items, $key);

        if (($list->usageCount)($key) > 0) {
            $items[$index]['archived'] = true;
            $this->write($list, $items, $actor);

            return self::ARCHIVED;
        }

        $remaining = array_values(array_filter($items, static fn (array $item): bool => ! ($item['archived'] ?? false)));
        if (count($remaining) <= 1 && ! ($items[$index]['archived'] ?? false)) {
            throw ValidationException::withMessages(['item' => __('admin.managed_lists.keep_one')]);
        }

        array_splice($items, $index, 1);
        $this->write($list, $items, $actor);

        return self::REMOVED;
    }

    public function restore(ManagedListDefinition $list, string $key, ?User $actor = null): void
    {
        $items = $this->items($list);
        unset($items[$this->indexOf($items, $key)]['archived']);
        $this->write($list, $items, $actor);
    }

    /**
     * @param  list<array<string, mixed>>  $items
     */
    private function write(ManagedListDefinition $list, array $items, ?User $actor): void
    {
        $record = $this->settings->record($list->subCoreKey, $list->moduleKey);
        abort_if($record === null, 404);

        $settings = is_array($record->settings) ? $record->settings : [];
        $settings[$list->listKey] = array_values($items);

        $this->settings->save($list->subCoreKey, $list->moduleKey, $settings, (int) $record->lock_version, $actor);
    }

    /**
     * @param  list<array<string, mixed>>  $items
     */
    private function indexOf(array $items, string $key): int
    {
        foreach ($items as $index => $item) {
            if (($item['key'] ?? null) === $key) {
                return $index;
            }
        }

        abort(404);
    }

    /**
     * @param  list<mixed>  $taken
     */
    private function uniqueKey(string $label, array $taken): string
    {
        $base = Str::of($label)->ascii()->lower()->replaceMatches('/[^a-z0-9]+/', '_')->trim('_')->limit(56, '')->toString();
        $base = $base === '' ? 'item' : $base;
        $key = $base;

        for ($suffix = 2; in_array($key, $taken, true); $suffix++) {
            $key = $base.'_'.$suffix;
        }

        return $key;
    }
}
