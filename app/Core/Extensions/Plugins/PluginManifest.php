<?php

namespace App\Core\Extensions\Plugins;

use Illuminate\Support\Facades\Lang;

/**
 * Package manifest for a reusable plugin (#287).
 *
 * {@see $translationNamespace} and {@see $searchKeywordsKey} feed the Language
 * line (#272 / #274) without further contract changes.
 * {@see $nameKey} / {@see $descriptionKey} resolve Admin Plugins labels via {@see label()}.
 * {@see keywords()} resolves Admin search synonyms from {@see $searchKeywordsKey}.
 */
final readonly class PluginManifest
{
    /**
     * @param  list<string>  $compatibleModules  Module slugs, or ['*']
     * @param  list<string>  $shippedModules  Modules where this plugin ships today
     * @param  list<PluginAbility>  $permissions
     * @param  list<PluginDependency>  $dependencies
     * @param  list<PluginNavigationItem>  $navigation
     * @param  list<PluginMenuAction>  $menuActions  mega-menu actions (#885); when empty, derived from $navigation
     * @param  array<string, PluginSettingsSchema>  $settingsByModule  keyed by module slug
     * @param  class-string|null  $activeRecordDetector
     * @param  class-string|null  $summaryProvider
     * @param  class-string|null  $recentActivityProvider
     * @param  class-string|null  $analyticsProvider
     * @param  class-string|null  $attentionProvider
     * @param  list<string>  $publicRouteFiles
     * @param  list<string>  $staffRouteFiles
     */
    public function __construct(
        public string $slug,
        public string $name,
        public string $description,
        public string $version,
        public string $requiresCore,
        public array $compatibleModules,
        public array $shippedModules,
        public array $permissions,
        public array $dependencies = [],
        public array $navigation = [],
        public array $settingsByModule = [],
        public string $translationNamespace = '',
        public string $searchKeywordsKey = '',
        public ?string $activeRecordDetector = null,
        public ?string $summaryProvider = null,
        public ?string $recentActivityProvider = null,
        public ?string $analyticsProvider = null,
        public ?string $attentionProvider = null,
        public ?string $migrationsPath = null,
        public array $publicRouteFiles = [],
        public array $staffRouteFiles = [],
        public ?string $nameKey = null,
        public ?string $descriptionKey = null,
        public array $menuActions = [],
    ) {}

    public static function make(
        string $slug,
        string $name,
        string $description,
        string $version,
        string $requiresCore,
        array $compatibleModules,
        array $shippedModules,
        array $permissions,
        array $dependencies = [],
        array $navigation = [],
        array $settingsByModule = [],
        ?string $translationNamespace = null,
        ?string $searchKeywordsKey = null,
        ?string $activeRecordDetector = null,
        ?string $summaryProvider = null,
        ?string $recentActivityProvider = null,
        ?string $analyticsProvider = null,
        ?string $attentionProvider = null,
        ?string $migrationsPath = null,
        array $publicRouteFiles = [],
        array $staffRouteFiles = [],
        ?string $nameKey = null,
        ?string $descriptionKey = null,
        array $menuActions = [],
    ): self {
        $namespace = $translationNamespace ?? str_replace('-', '_', $slug);

        return new self(
            slug: $slug,
            name: $name,
            description: $description,
            version: $version,
            requiresCore: $requiresCore,
            compatibleModules: $compatibleModules,
            shippedModules: $shippedModules,
            permissions: $permissions,
            dependencies: $dependencies,
            navigation: $navigation,
            settingsByModule: $settingsByModule,
            translationNamespace: $namespace,
            searchKeywordsKey: $searchKeywordsKey ?? $namespace.'::plugin.keywords',
            activeRecordDetector: $activeRecordDetector,
            summaryProvider: $summaryProvider,
            recentActivityProvider: $recentActivityProvider,
            analyticsProvider: $analyticsProvider,
            attentionProvider: $attentionProvider,
            migrationsPath: $migrationsPath,
            publicRouteFiles: $publicRouteFiles,
            staffRouteFiles: $staffRouteFiles,
            nameKey: $nameKey ?? $namespace.'::plugin.name',
            descriptionKey: $descriptionKey ?? $namespace.'::plugin.description',
            menuActions: $menuActions,
        );
    }

    /**
     * Mega-menu actions for a module (#885). Explicit {@see $menuActions} win;
     * otherwise each {@see $navigation} entry for the module becomes a staff
     * "view" action (and a create action when the route ends in `.index`).
     *
     * @return list<PluginMenuAction>
     */
    public function menuActionsFor(string $moduleSlug, string $audience): array
    {
        $actions = array_values(array_filter(
            $this->menuActions,
            static fn (PluginMenuAction $action): bool => $action->moduleSlug === $moduleSlug
                && $action->audience === $audience,
        ));

        if ($actions !== []) {
            usort($actions, static fn (PluginMenuAction $a, PluginMenuAction $b): int => $a->order <=> $b->order);

            return $actions;
        }

        if ($audience !== PluginMenuAction::AUDIENCE_STAFF) {
            return [];
        }

        $derived = [];
        foreach ($this->navigation as $item) {
            if ($item->moduleSlug !== $moduleSlug) {
                continue;
            }

            $derived[] = new PluginMenuAction(
                label: $item->label,
                routeName: $item->routeName,
                icon: $item->icon,
                order: $item->order * 10,
                audience: PluginMenuAction::AUDIENCE_STAFF,
                moduleSlug: $moduleSlug,
                requiredAbility: $item->requiredAbility,
                description: null,
                labelKey: $item->labelKey,
            );

            if (str_ends_with($item->routeName, '.index')) {
                $derived[] = new PluginMenuAction(
                    label: $item->label,
                    routeName: $item->routeName,
                    icon: 'circle-plus',
                    order: $item->order * 10 + 1,
                    audience: PluginMenuAction::AUDIENCE_STAFF,
                    moduleSlug: $moduleSlug,
                    requiredAbility: 'create',
                    anchor: '#create',
                    description: null,
                    labelKey: $item->labelKey,
                );
            }
        }

        usort($derived, static fn (PluginMenuAction $a, PluginMenuAction $b): int => $a->order <=> $b->order);

        return $derived;
    }

    /**
     * Translated display name for Admin Plugins and registry adapters (#272).
     * Falls back to the English manifest name, then the slug, when the key is missing.
     */
    public function label(): string
    {
        return $this->resolveTranslation($this->nameKey, $this->name);
    }

    /**
     * Translated description for Admin Plugins (#272).
     */
    public function descriptionLabel(): string
    {
        return $this->resolveTranslation($this->descriptionKey, $this->description);
    }

    /**
     * Search synonyms for Admin keyword filter (#274).
     *
     * @return list<string>
     */
    public function keywords(): array
    {
        $key = $this->searchKeywordsKey;
        if ($key === '') {
            return [];
        }

        $value = __($key);
        if (! is_array($value)) {
            return [];
        }

        $keywords = [];
        foreach ($value as $item) {
            if (is_string($item) && trim($item) !== '') {
                $keywords[] = trim($item);
            }
        }

        return array_values(array_unique($keywords));
    }

    public function settingsFor(string $moduleSlug): PluginSettingsSchema
    {
        return $this->settingsByModule[$moduleSlug]
            ?? new PluginSettingsSchema(supportsSettings: false);
    }

    public function isCompatibleWith(string $moduleSlug): bool
    {
        return in_array('*', $this->compatibleModules, true)
            || in_array($moduleSlug, $this->compatibleModules, true);
    }

    public function isShippedFor(string $moduleSlug): bool
    {
        return in_array($moduleSlug, $this->shippedModules, true);
    }

    private function resolveTranslation(?string $key, string $fallback): string
    {
        $englishFallback = $fallback !== '' ? $fallback : $this->slug;

        if ($key === null || $key === '') {
            return $englishFallback;
        }

        $locale = (string) app()->getLocale();
        $fallbackLocale = (string) config('app.fallback_locale', 'en');

        if (! Lang::has($key, $locale, false) && ! Lang::has($key, $fallbackLocale, false)) {
            MissingPluginTranslationKeyLogger::once($this->slug, $key);

            return $englishFallback;
        }

        $translated = __($key);

        if ($translated === $key) {
            MissingPluginTranslationKeyLogger::once($this->slug, $key);

            return $englishFallback;
        }

        return $translated;
    }
}
