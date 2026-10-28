<?php

namespace App\Services;

use App\Core\Extensions\Plugins\PluginRegistry;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Permission-gated Admin shell search index powered by lang keywords (#274).
 */
final class AdminSearchCatalogue
{
    public function __construct(
        private readonly PluginRegistry $plugins,
        private readonly Gate $gate,
    ) {}

    /**
     * @return list<array{
     *     id: string,
     *     label: string,
     *     description: string,
     *     url: string,
     *     keywords: list<string>,
     *     group: string
     * }>
     */
    public function entriesFor(?Authenticatable $user): array
    {
        if ($user === null) {
            return [];
        }

        $entries = [];

        foreach ($this->coreSections() as $section) {
            if (! $this->allows($user, $section['abilities'])) {
                continue;
            }

            $entries[] = [
                'id' => $section['id'],
                'label' => $section['label'],
                'description' => $section['description'],
                'url' => $section['url'],
                'keywords' => $this->keywordsFromLang($section['keywords_key']),
                'group' => 'admin',
            ];
        }

        foreach ($this->plugins->plugins() as $plugin) {
            if (! $this->allows($user, ['admin.modules.view'])) {
                break;
            }

            $entries[] = [
                'id' => 'plugin.'.$plugin->slug,
                'label' => $plugin->label(),
                'description' => $plugin->descriptionLabel(),
                'url' => route('admin.modules.index').'#plugin-'.$plugin->slug,
                'keywords' => $plugin->keywords(),
                'group' => 'plugin',
            ];

            foreach ($plugin->shippedModules as $moduleSlug) {
                $schema = $plugin->settingsFor($moduleSlug);
                if (! $schema->supportsSettings) {
                    continue;
                }

                if (! $this->allows($user, [$schema->viewPermission])) {
                    continue;
                }

                $settingsKeywordsKey = $plugin->translationNamespace.'::settings.keywords';
                $entries[] = [
                    'id' => 'settings.'.$moduleSlug.'.'.$plugin->slug,
                    'label' => $plugin->label().' — '.__('terms.plugin_settings'),
                    'description' => __('admin.search.settings_for', [
                        'plugin' => $plugin->label(),
                        'module' => $moduleSlug,
                    ]),
                    'url' => route($schema->settingsRouteName, [$moduleSlug, $plugin->slug]),
                    'keywords' => array_values(array_unique(array_merge(
                        $plugin->keywords(),
                        $this->keywordsFromLang($settingsKeywordsKey),
                        ['settings', 'configure', 'modules'],
                    ))),
                    'group' => 'settings',
                ];
            }
        }

        return $entries;
    }

    /**
     * @param  list<string>  $abilities
     */
    private function allows(Authenticatable $user, array $abilities): bool
    {
        foreach ($abilities as $ability) {
            if ($this->gate->forUser($user)->allows($ability)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<array{
     *     id: string,
     *     label: string,
     *     description: string,
     *     url: string,
     *     keywords_key: string,
     *     abilities: list<string>
     * }>
     */
    private function coreSections(): array
    {
        return [
            [
                'id' => 'admin.overview',
                'label' => __('admin.nav.overview'),
                'description' => __('admin.search.sections.overview'),
                'url' => route('admin.index'),
                'keywords_key' => 'admin.search.keywords.overview',
                'abilities' => ['admin.analytics.view'],
            ],
            [
                'id' => 'admin.users',
                'label' => __('terms.public_users'),
                'description' => __('admin.search.sections.users'),
                'url' => route('admin.users.index', ['account_type' => 'public']),
                'keywords_key' => 'admin.search.keywords.users',
                'abilities' => ['admin.users.view'],
            ],
            [
                'id' => 'admin.staff',
                'label' => __('terms.staff'),
                'description' => __('admin.search.sections.staff'),
                'url' => route('admin.users.index', ['account_type' => 'staff']),
                'keywords_key' => 'admin.search.keywords.staff',
                'abilities' => ['admin.users.view'],
            ],
            [
                'id' => 'admin.access',
                'label' => __('admin.nav.access'),
                'description' => __('admin.search.sections.access'),
                'url' => route('admin.access.index'),
                'keywords_key' => 'admin.search.keywords.access',
                'abilities' => ['admin.groups.view', 'admin.roles.view', 'admin.permissions.view'],
            ],
            [
                'id' => 'admin.modules',
                'label' => __('admin.nav.modules'),
                'description' => __('admin.search.sections.modules'),
                'url' => route('admin.organisation-modules.index'),
                'keywords_key' => 'admin.search.keywords.modules',
                'abilities' => ['admin.modules.view'],
            ],
            [
                'id' => 'admin.plugins',
                'label' => __('admin.nav.plugins'),
                'description' => __('admin.search.sections.plugins'),
                'url' => route('admin.modules.index'),
                'keywords_key' => 'admin.search.keywords.plugins',
                'abilities' => ['admin.modules.view'],
            ],
            [
                'id' => 'admin.emails',
                'label' => __('admin.nav.emails'),
                'description' => __('admin.search.sections.emails'),
                'url' => route('admin.emails.index'),
                'keywords_key' => 'admin.search.keywords.emails',
                'abilities' => ['admin.emails.view'],
            ],
            [
                'id' => 'admin.maintenance',
                'label' => __('admin.nav.maintenance'),
                'description' => __('admin.search.sections.maintenance'),
                'url' => route('admin.maintenance.index'),
                'keywords_key' => 'admin.search.keywords.maintenance',
                'abilities' => ['admin.maintenance.manage'],
            ],
        ];
    }

    /**
     * @return list<string>
     */
    private function keywordsFromLang(string $key): array
    {
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
}
