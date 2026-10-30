<?php

namespace Plugins\Tickets;

use App\Services\ModuleSettingsService;
use App\Support\WorkflowLabels;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class TicketCategoryResolver
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

        return $this->settings->get($subCoreKey, 'tickets');
    }

    /**
     * @return array<int, array{key: string, label: string, subcategories: array<int, array<string, mixed>>}>
     */
    public function serviceAreas(string $subCoreKey): array
    {
        $settings = $this->settingsFor($subCoreKey);
        $areas = $settings['service_areas'] ?? [];

        return is_array($areas) ? array_values($areas) : [];
    }

    /** @return array<int, string> */
    public function serviceAreaKeys(string $subCoreKey): array
    {
        return array_map(
            static fn (array $area): string => (string) $area['key'],
            $this->serviceAreas($subCoreKey),
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
    public function findSubcategory(string $subCoreKey, string $serviceArea, string $subCategory): ?array
    {
        foreach ($this->serviceAreas($subCoreKey) as $area) {
            if (($area['key'] ?? null) !== $serviceArea) {
                continue;
            }
            foreach ($area['subcategories'] ?? [] as $sub) {
                if (($sub['key'] ?? null) === $subCategory) {
                    return $sub;
                }
            }
        }

        return null;
    }

    public function labelForArea(string $subCoreKey, string $key): string
    {
        foreach ($this->serviceAreas($subCoreKey) as $area) {
            if (($area['key'] ?? null) === $key) {
                return (string) ($area['label'] ?? $key);
            }
        }

        return WorkflowLabels::label('ticket_area', $key);
    }

    public function labelForSubcategory(string $subCoreKey, string $areaKey, string $subKey): string
    {
        $sub = $this->findSubcategory($subCoreKey, $areaKey, $subKey);

        return $sub !== null
            ? (string) ($sub['label'] ?? $subKey)
            : Str::headline($subKey);
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
     * @return array{service_area: string, sub_category: string, affected_website_key: ?string}
     */
    public function validateSelection(
        string $subCoreKey,
        string $serviceArea,
        string $subCategory,
        ?string $websiteKey,
    ): array {
        $sub = $this->findSubcategory($subCoreKey, $serviceArea, $subCategory);
        if ($sub === null) {
            throw ValidationException::withMessages([
                'sub_category' => __('tickets::ui.validation.choose_a_valid_subcategory_for_the_selected_service_are'),
            ]);
        }

        $requiresWebsite = (bool) ($sub['requires_website'] ?? false);
        $websiteKeys = $this->websiteKeys($subCoreKey);

        if ($requiresWebsite) {
            if ($websiteKey === null || $websiteKey === '') {
                throw ValidationException::withMessages([
                    'affected_website_key' => __('tickets::ui.validation.select_which_website_is_affected'),
                ]);
            }
            if (! in_array($websiteKey, $websiteKeys, true)) {
                throw ValidationException::withMessages([
                    'affected_website_key' => __('tickets::ui.validation.select_a_valid_website'),
                ]);
            }
        } elseif ($websiteKey !== null && $websiteKey !== '' && ! in_array($websiteKey, $websiteKeys, true)) {
            throw ValidationException::withMessages([
                'affected_website_key' => __('tickets::ui.validation.select_a_valid_website'),
            ]);
        }

        return [
            'service_area' => $serviceArea,
            'sub_category' => $subCategory,
            'affected_website_key' => $websiteKey === '' ? null : $websiteKey,
        ];
    }

    public function allowsAttachments(string $subCoreKey, string $serviceArea, string $subCategory): bool
    {
        $sub = $this->findSubcategory($subCoreKey, $serviceArea, $subCategory);

        return (bool) ($sub['allows_attachments'] ?? false);
    }
}
