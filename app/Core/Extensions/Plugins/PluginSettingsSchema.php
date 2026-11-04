<?php

namespace App\Core\Extensions\Plugins;

/**
 * Per-enablement settings contract (#287). Preserves v0.35 field meanings.
 */
final readonly class PluginSettingsSchema
{
    public const SCHEMA_WEBSITES_CATEGORIES = 'websites_categories';

    public const SCHEMA_RECRUITMENT_BOARD = 'recruitment_board';

    public const SCHEMA_SERVICE_AREAS = 'service_areas';

    public const SCHEMA_NONE = 'none';

    public function __construct(
        public bool $supportsSettings,
        public string $schema = self::SCHEMA_NONE,
        public string $settingsRouteName = 'admin.modules.settings.edit',
        public string $viewPermission = 'admin.modules.view',
        public string $managePermission = 'admin.modules.manage',
        public string $navLabel = 'Settings',
        public ?string $groupKey = null,
        public ?string $groupLabel = null,
    ) {}
}
