<?php

namespace Plugins\Cases;

use App\Services\ModuleSettingsService;
use Illuminate\Validation\ValidationException;

class CaseCategoryResolver
{
    public function __construct(
        private readonly ModuleSettingsService $settings,
    ) {}

    /** @return array<string, mixed> */
    public function settingsFor(string $subCoreKey): array
    {
        if ($subCoreKey !== 'apes-cic') {
            return [];
        }

        return $this->settings->get($subCoreKey, 'cases');
    }

    /**
     * @return array<int, array{key: string, label: string, subcategories: array<int, array<string, mixed>>}>
     */
    public function categories(string $subCoreKey): array
    {
        return array_values(array_filter(
            $this->allCategories($subCoreKey),
            static fn (array $category): bool => ! ($category['archived'] ?? false),
        ));
    }

    /**
     * Includes archived categories (#471) so existing cases keep their labels.
     *
     * @return array<int, array{key: string, label: string, subcategories: array<int, array<string, mixed>>}>
     */
    public function allCategories(string $subCoreKey): array
    {
        $settings = $this->settingsFor($subCoreKey);
        $categories = $settings['categories'] ?? [];

        return is_array($categories) ? array_values($categories) : [];
    }

    /** @return array<int, string> */
    public function categoryKeys(string $subCoreKey): array
    {
        return array_map(
            static fn (array $category): string => (string) $category['key'],
            $this->categories($subCoreKey),
        );
    }

    /**
     * @return array<int, array{key: string, label: string, url: ?string}>
     */
    public function websites(string $subCoreKey): array
    {
        $settings = $this->settingsFor($subCoreKey);
        $websites = $settings['websites'] ?? [];

        return is_array($websites) ? array_values($websites) : [];
    }

    /** @return array<int, string> */
    public function websiteKeys(string $subCoreKey): array
    {
        return array_map(
            static fn (array $site): string => (string) $site['key'],
            $this->websites($subCoreKey),
        );
    }

    /** @return array<string, mixed>|null */
    public function findSubcategory(string $subCoreKey, string $category, string $subCategory, bool $includeArchived = false): ?array
    {
        $groups = $includeArchived ? $this->allCategories($subCoreKey) : $this->categories($subCoreKey);
        foreach ($groups as $group) {
            if (($group['key'] ?? null) !== $category) {
                continue;
            }
            foreach ($group['subcategories'] ?? [] as $sub) {
                if (($sub['key'] ?? null) === $subCategory) {
                    return $sub;
                }
            }
        }

        return null;
    }

    public function labelForCategory(string $subCoreKey, string $key): string
    {
        foreach ($this->allCategories($subCoreKey) as $category) {
            if (($category['key'] ?? null) === $key) {
                return (string) ($category['label'] ?? $key);
            }
        }

        return str_replace('_', ' ', $key);
    }

    public function labelForSubcategory(string $subCoreKey, string $categoryKey, string $subKey): string
    {
        $sub = $this->findSubcategory($subCoreKey, $categoryKey, $subKey, true);

        return $sub !== null
            ? (string) ($sub['label'] ?? $subKey)
            : str_replace('_', ' ', $subKey);
    }

    public function labelForWebsite(string $subCoreKey, ?string $key): string
    {
        if ($key === null || $key === '') {
            return '—';
        }
        foreach ($this->websites($subCoreKey) as $site) {
            if (($site['key'] ?? null) === $key) {
                return (string) ($site['label'] ?? $key);
            }
        }

        return str_replace('_', ' ', $key);
    }

    /**
     * @return array{category: string, sub_category: string, affected_website_key: ?string}
     */
    public function validateSelection(
        string $subCoreKey,
        string $category,
        string $subCategory,
        ?string $websiteKey,
        bool $includeArchived = false,
    ): array {
        $sub = $this->findSubcategory($subCoreKey, $category, $subCategory, $includeArchived);
        if ($sub === null) {
            throw ValidationException::withMessages([
                'sub_category' => __('cases::ui.validation.choose_a_valid_subcategory_for_the_selected_category'),
            ]);
        }

        $requiresWebsite = (bool) ($sub['requires_website'] ?? false);
        $websiteKeys = $this->websiteKeys($subCoreKey);

        if ($requiresWebsite) {
            if ($websiteKey === null || $websiteKey === '') {
                throw ValidationException::withMessages([
                    'affected_website_key' => __('cases::ui.validation.select_which_website_or_system_is_involved'),
                ]);
            }
            if (! in_array($websiteKey, $websiteKeys, true)) {
                throw ValidationException::withMessages([
                    'affected_website_key' => __('cases::ui.validation.select_a_valid_website'),
                ]);
            }
        } elseif ($websiteKey !== null && $websiteKey !== '' && ! in_array($websiteKey, $websiteKeys, true)) {
            throw ValidationException::withMessages([
                'affected_website_key' => __('cases::ui.validation.select_a_valid_website'),
            ]);
        }

        return [
            'category' => $category,
            'sub_category' => $subCategory,
            'affected_website_key' => $websiteKey === '' ? null : $websiteKey,
        ];
    }

    public function allowsAttachments(string $subCoreKey, string $category, string $subCategory): bool
    {
        $sub = $this->findSubcategory($subCoreKey, $category, $subCategory, true);

        return (bool) ($sub['allows_attachments'] ?? false);
    }
}
