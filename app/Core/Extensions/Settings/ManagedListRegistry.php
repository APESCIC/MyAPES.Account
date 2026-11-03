<?php

namespace App\Core\Extensions\Settings;

/**
 * Managed lists contributed by plugins (#471). Core never names a plugin list.
 */
final class ManagedListRegistry
{
    /** @var array<string, \Closure(): ManagedListDefinition> */
    private array $definitions = [];

    /**
     * @param  \Closure(): ManagedListDefinition  $definition  resolved lazily so labels translate per request
     */
    public function register(string $subCoreKey, string $moduleKey, string $listKey, \Closure $definition): void
    {
        $this->definitions["{$subCoreKey}:{$moduleKey}:{$listKey}"] = $definition;
    }

    public function find(string $subCoreKey, string $moduleKey, string $listKey): ?ManagedListDefinition
    {
        $definition = $this->definitions["{$subCoreKey}:{$moduleKey}:{$listKey}"] ?? null;

        return $definition === null ? null : $definition();
    }

    /**
     * @return list<ManagedListDefinition>
     */
    public function forModule(string $subCoreKey, string $moduleKey): array
    {
        $found = [];

        foreach ($this->definitions as $id => $definition) {
            if (str_starts_with($id, "{$subCoreKey}:{$moduleKey}:")) {
                $found[] = $definition();
            }
        }

        return $found;
    }
}
