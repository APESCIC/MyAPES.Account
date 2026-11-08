<?php

namespace Plugins\Cases;

use App\Core\Attachments\UploadSettings;

/**
 * Whether a case form may take files (#548): the module's upload switch,
 * plus the subcategory flag for APES CIC cases.
 */
class CaseAttachmentGate
{
    public function __construct(
        private readonly CaseCategoryResolver $categories,
        private readonly UploadSettings $uploads,
    ) {}

    public function uploadsEnabled(CasesArea $area): bool
    {
        return $area->supportsAttachments && $this->uploads->enabledFor($area->moduleSlug);
    }

    public function allows(CasesArea $area, string $subCoreKey, ?string $category, ?string $subCategory): bool
    {
        if (! $this->uploadsEnabled($area)) {
            return false;
        }

        if (! $area->usesCategories) {
            return true;
        }

        return is_string($category)
            && is_string($subCategory)
            && $this->categories->allowsAttachments($subCoreKey, $category, $subCategory);
    }

    public function refusalMessage(CasesArea $area): string
    {
        return $this->uploadsEnabled($area)
            ? __('cases::ui.validation.attachments_are_not_available_for_this_subcategory')
            : __('attachments.validation.uploads_disabled');
    }
}
