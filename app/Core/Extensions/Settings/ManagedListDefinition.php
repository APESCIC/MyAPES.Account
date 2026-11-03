<?php

namespace App\Core\Extensions\Settings;

/**
 * A row-managed list inside a module's plugin settings (#471), such as the
 * APES CIC ticket service areas. Plugins register one per list.
 */
final readonly class ManagedListDefinition
{
    /**
     * @param  \Closure(string): int  $usageCount  how many records use an item key
     * @param  array<string, mixed>  $newItemDefaults  extra fields for a new row
     */
    public function __construct(
        public string $subCoreKey,
        public string $moduleKey,
        public string $listKey,
        public string $label,
        public \Closure $usageCount,
        public array $newItemDefaults = [],
    ) {}

    public function id(): string
    {
        return "{$this->subCoreKey}:{$this->moduleKey}:{$this->listKey}";
    }
}
