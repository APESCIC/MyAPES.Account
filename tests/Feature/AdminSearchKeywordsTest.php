<?php

namespace Tests\Feature;

use App\Core\Accounts\User;
use App\Core\Extensions\Plugins\PluginRegistry;
use App\Services\AdminSearchCatalogue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminSearchKeywordsTest extends TestCase
{
    use RefreshDatabase;

    public function test_synonyms_resolve_recruitment_tickets_and_access(): void
    {
        $superAdmin = User::factory()->accessLevel(User::ROLE_SUPERADMIN)->create();
        $this->actingAs($superAdmin);
        $catalogue = app(AdminSearchCatalogue::class)->entriesFor($superAdmin);

        $this->assertNotEmpty($catalogue);
        $this->assertTrue($this->catalogueContains($catalogue, 'jobs', 'Recruitment'));
        $this->assertTrue($this->catalogueContains($catalogue, 'helpdesk', 'Tickets'));
        $this->assertTrue($this->catalogueContains($catalogue, 'permissions', 'Access'));
    }

    public function test_workflow_label_and_admin_synonyms_find_the_right_entries(): void
    {
        $superAdmin = User::factory()->accessLevel(User::ROLE_SUPERADMIN)->create();
        $this->actingAs($superAdmin);
        $catalogue = app(AdminSearchCatalogue::class)->entriesFor($superAdmin);

        foreach ([
            'waiting on user' => 'Cases',
            'urgent' => 'Tickets',
            'under review' => 'Recruitment',
            'neutering' => 'Pet Profiles',
            'check-up' => 'Consultations',
            'reactivate' => __('terms.public_users'),
            'employees' => __('terms.staff'),
            'group mappings' => __('admin.nav.access'),
            'code not shipped' => __('admin.nav.plugins'),
            'mail log' => __('admin.nav.emails'),
            'maintenance mode' => __('admin.nav.maintenance'),
            'statistics' => __('admin.nav.overview'),
        ] as $query => $label) {
            $this->assertTrue($this->catalogueContains($catalogue, $query, $label), "'{$query}' should find {$label}.");
        }
    }

    public function test_search_keywords_stay_lang_only_synonym_lists(): void
    {
        foreach (array_keys(__('admin.search.sections')) as $section) {
            $keywords = __("admin.search.keywords.{$section}");

            $this->assertIsArray($keywords, $section);
            $this->assertTrue(array_is_list($keywords), $section);
            $this->assertSame($keywords, array_values(array_unique($keywords)), "{$section} has duplicate keywords.");

            foreach ($keywords as $keyword) {
                $this->assertSame(mb_strtolower($keyword), $keyword, "{$section} keyword '{$keyword}' should be lower case.");
            }
        }

        foreach (app(PluginRegistry::class)->plugins() as $plugin) {
            $keywords = $plugin->keywords();

            $this->assertNotEmpty($keywords, $plugin->slug);
            $this->assertSame($keywords, array_values(array_unique($keywords)), "{$plugin->slug} has duplicate keywords.");
        }
    }

    public function test_administrator_does_not_see_access_or_plugin_entries(): void
    {
        $admin = User::factory()->accessLevel(User::ROLE_ADMIN)->create();
        $this->actingAs($admin);
        $catalogue = app(AdminSearchCatalogue::class)->entriesFor($admin);

        $labels = array_column($catalogue, 'label');
        $this->assertContains(__('admin.nav.overview'), $labels);
        $this->assertContains(__('terms.public_users'), $labels);
        $this->assertNotContains(__('admin.nav.access'), $labels);
        $this->assertNotContains(__('admin.nav.plugins'), $labels);
        $this->assertFalse($this->catalogueContains($catalogue, 'jobs', 'Recruitment'));
        $this->assertFalse($this->catalogueContains($catalogue, 'permissions', 'Access'));
    }

    public function test_admin_shell_renders_permission_gated_search_box(): void
    {
        $superAdmin = User::factory()->accessLevel(User::ROLE_SUPERADMIN)->create();
        $admin = User::factory()->accessLevel(User::ROLE_ADMIN)->create();

        $this->actingAs($superAdmin)
            ->get(route('admin.index'))
            ->assertOk()
            ->assertSee('data-admin-search', false)
            ->assertSee(__('admin.search.placeholder'), false)
            ->assertSee('data-admin-search-index', false)
            ->assertSee('Recruitment', false)
            ->assertSee('jobs', false);

        $this->actingAs($admin)
            ->get(route('admin.index'))
            ->assertOk()
            ->assertSee('data-admin-search', false)
            ->assertDontSee('"id":"admin.access"', false)
            ->assertDontSee('"id":"plugin.recruitment"', false);
    }

    public function test_plugin_manifests_expose_search_keywords_from_lang(): void
    {
        $recruitment = app(PluginRegistry::class)->plugin('recruitment');
        $tickets = app(PluginRegistry::class)->plugin('tickets');

        $this->assertContains('jobs', $recruitment->keywords());
        $this->assertContains('helpdesk', $tickets->keywords());
        $this->assertSame('recruitment::plugin.keywords', $recruitment->searchKeywordsKey);
    }

    /**
     * @param  list<array{label: string, keywords: list<string>}>  $catalogue
     */
    private function catalogueContains(array $catalogue, string $query, string $expectedLabel): bool
    {
        $needle = mb_strtolower($query);

        foreach ($catalogue as $entry) {
            $haystack = mb_strtolower(implode(' ', [
                $entry['label'],
                $entry['description'],
                ...$entry['keywords'],
            ]));

            if (! str_contains($haystack, $needle)) {
                continue;
            }

            if (str_contains($entry['label'], $expectedLabel)) {
                return true;
            }
        }

        return false;
    }
}
