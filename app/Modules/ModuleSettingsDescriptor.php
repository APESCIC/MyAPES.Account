<?php

namespace App\Modules;

/**
 * Plugin settings registry contract entry.
 *
 * Each shipped plugin instance declares whether it supports editable settings,
 * which schema drives the Admin settings page, the named route for deep-links,
 * and the permissions that gate view/manage.
 */
final readonly class ModuleSettingsDescriptor
{
    public const SCHEMA_WEBSITES_CATEGORIES = 'websites_categories';

    public const SCHEMA_RECRUITMENT_BOARD = 'recruitment_board';

    public const SCHEMA_SERVICE_AREAS = 'service_areas';

    public const SCHEMA_NONE = 'none';

    public function __construct(
        public string $subCoreKey,
        public string $moduleKey,
        public bool $supportsSettings,
        public string $schema = self::SCHEMA_NONE,
        public string $settingsRouteName = 'admin.modules.settings.edit',
        public string $viewPermission = 'admin.modules.view',
        public string $managePermission = 'admin.modules.manage',
        public string $navLabel = 'Settings',
        public ?string $groupKey = null,
        public ?string $groupLabel = null,
    ) {}

    public function settingsUrl(): ?string
    {
        if (! $this->supportsSettings) {
            return null;
        }

        return route($this->settingsRouteName, [$this->subCoreKey, $this->moduleKey]);
    }
}
