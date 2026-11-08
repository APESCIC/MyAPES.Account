<?php

namespace App\Core\Attachments;

use App\Core\Extensions\Models\OrganisationModule;

/**
 * Per-module switch for ticket and case uploads (#548). Uploads are on
 * unless an admin turns them off on Admin → Modules.
 */
class UploadSettings
{
    /** @var array<string, bool> */
    private array $cache = [];

    public function enabledFor(string $moduleSlug): bool
    {
        return $this->cache[$moduleSlug] ??= (bool) (OrganisationModule::query()
            ->where('slug', $moduleSlug)
            ->value('uploads_enabled') ?? true);
    }
}
