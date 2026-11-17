<?php

namespace App\Core\Extensions\Modules;

use App\Core\Extensions\Models\OrganisationModule;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;

/**
 * Collects module package manifests registered by ModuleServiceProvider subclasses (#283).
 */
final class ModulePackageRegistry
{
    /** @var array<string, ModuleManifest> */
    private array $manifests = [];

    /** @var array<string, bool>|null */
    private ?array $enabledCache = null;

    public function register(ModuleManifest $manifest): void
    {
        $this->manifests[$manifest->slug] = $manifest;
        ksort($this->manifests);
    }

    /** @return array<string, ModuleManifest> */
    public function modules(): array
    {
        return $this->manifests;
    }

    public function module(string $slug): ModuleManifest
    {
        return $this->manifests[$slug]
            ?? throw new InvalidArgumentException("Unknown module slug [{$slug}].");
    }

    public function has(string $slug): bool
    {
        return isset($this->manifests[$slug]);
    }

    /** @return array<string, ModuleManifest> */
    public function enabled(): array
    {
        return array_filter(
            $this->manifests,
            fn (ModuleManifest $manifest): bool => $this->isEnabled($manifest->slug),
        );
    }

    public function isEnabled(string $slug): bool
    {
        $manifest = $this->manifests[$slug] ?? null;

        if ($manifest === null) {
            return false;
        }

        if (! $this->organisationModulesTableReady()) {
            return $manifest->enabledByDefault;
        }

        if ($this->enabledCache === null) {
            $this->enabledCache = OrganisationModule::query()
                ->pluck('enabled', 'slug')
                ->all();
        }

        return $this->enabledCache[$slug] ?? $manifest->enabledByDefault;
    }

    private function organisationModulesTableReady(): bool
    {
        try {
            return Schema::hasTable('organisation_modules');
        } catch (\Throwable) {
            return false;
        }
    }
}
