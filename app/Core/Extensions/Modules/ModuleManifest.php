<?php

namespace App\Core\Extensions\Modules;

/**
 * Package manifest for an organisation-area module (#283 / #284–#286).
 */
final readonly class ModuleManifest
{
    /**
     * @param  list<string>  $plugins  Plugin slugs this module composes by default
     * @param  array<string, array<string, mixed>>  $pluginDefaults  Default settings keyed by plugin slug
     */
    public function __construct(
        public string $slug,
        public string $name,
        public string $description,
        public string $routePrefix,
        public string $routeNamePrefix,
        public string $hubRouteName,
        public string $icon,
        public int $sortOrder,
        public bool $enabledByDefault = true,
        public ?string $nameKey = null,
        public ?string $descriptionKey = null,
        public ?ModuleStaffArea $staffArea = null,
        public array $plugins = [],
        public array $pluginDefaults = [],
        public string $hubView = 'sub-cores.show',
        public ?string $packagePath = null,
        public string $version = '1.0.0',
    ) {}

    /**
     * Compatibility shape for the legacy SubCoreDefinition adapter.
     */
    public function basePath(): string
    {
        return $this->routePrefix;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function defaultsFor(string $pluginSlug): ?array
    {
        $defaults = $this->pluginDefaults[$pluginSlug] ?? null;

        return is_array($defaults) ? $defaults : null;
    }
}
