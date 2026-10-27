<?php

namespace Tests\Feature;

use App\Core\Accounts\User;
use App\Services\AuthorizationProfile;
use App\Services\ModuleInstallationSynchronizer;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Plugins\Recruitment\Models\RecruitmentRole;
use Tests\TestCase;

class LayoutSpacingSmokeTest extends TestCase
{
    use RefreshDatabase;

    private function stylesheet(): string
    {
        return (string) file_get_contents(resource_path('css/app.css'));
    }

    private function ruleBody(string $selector): string
    {
        $pattern = '/(?:^|\n)'.preg_quote($selector, '/').'\s*\{([^}]*)\}/';
        $this->assertSame(1, preg_match($pattern, $this->stylesheet(), $matches), "Missing CSS rule for {$selector}.");

        return $matches[1];
    }

    /**
     * A .panel-flat tile has no margin of its own, so its direct parent must be a
     * .grid or .stack that gaps it.
     */
    private function assertFlatTilesSitInGappingParents(string $html, string $surface): void
    {
        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML($html);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $tiles = (new DOMXPath($document))->query('//*[contains(concat(" ", normalize-space(@class), " "), " panel-flat ")]');
        $this->assertGreaterThan(0, $tiles->length, "{$surface} should render flat tiles.");

        foreach ($tiles as $tile) {
            $parentClasses = preg_split('/\s+/', trim((string) $tile->parentNode->getAttribute('class')));
            $this->assertNotEmpty(
                array_intersect(['grid', 'stack'], $parentClasses),
                "{$surface} has a .panel-flat tile whose parent (".implode(' ', $parentClasses).') does not gap it.',
            );
        }
    }

    public function test_every_spacing_token_used_in_the_stylesheet_is_defined(): void
    {
        $root = $this->ruleBody(':root');
        preg_match_all('/var\((--space-[a-z-]+)\)/', $this->stylesheet(), $matches);
        $used = array_unique($matches[1]);

        $this->assertNotEmpty($used);
        foreach ($used as $token) {
            $this->assertStringContainsString("{$token}:", $root, "{$token} is used but not defined on :root.");
        }
    }

    public function test_flat_tiles_always_sit_inside_a_gapping_parent(): void
    {
        app(ModuleInstallationSynchronizer::class)->synchronize();
        $author = User::factory()->create();
        RecruitmentRole::factory()->open()->category('volunteer')->count(2)->create(['created_by' => $author->id]);

        $this->assertFlatTilesSitInGappingParents($this->get(route('home'))->assertOk()->getContent(), 'Landing');
        $this->assertFlatTilesSitInGappingParents($this->get(route('recruitment.index'))->assertOk()->getContent(), 'Recruitment board');
        $this->assertFlatTilesSitInGappingParents((string) $this->view('auth.login'), 'Cloudron sign-in');

        $superAdmin = User::factory()->protectedRole(AuthorizationProfile::ROLE_SUPER_ADMIN)->create();
        $this->assertFlatTilesSitInGappingParents(
            $this->actingAs($superAdmin)->get(route('admin.index'))->assertOk()->getContent(),
            'Admin overview',
        );
    }

    public function test_spacing_tokens_are_defined_on_root(): void
    {
        $root = $this->ruleBody(':root');

        foreach (['--space-stack', '--space-stack-tight', '--space-stack-loose', '--space-grid', '--space-section'] as $token) {
            $this->assertMatchesRegularExpression('/'.preg_quote($token, '/').':\s*[0-9.]+rem;/', $root, "Missing token {$token}.");
        }
    }

    public function test_stack_utility_gaps_its_children(): void
    {
        $stack = $this->ruleBody('.stack');

        $this->assertStringContainsString('display: flex;', $stack);
        $this->assertStringContainsString('flex-direction: column;', $stack);
        $this->assertStringContainsString('gap: var(--space-stack);', $stack);
        $this->assertStringContainsString('gap: var(--space-stack-tight);', $this->ruleBody('.stack--tight'));
        $this->assertStringContainsString('gap: var(--space-stack-loose);', $this->ruleBody('.stack--loose'));
    }

    public function test_panel_contract_separates_page_cards_and_nested_tiles(): void
    {
        $this->assertStringContainsString('margin-bottom: var(--space-section);', $this->ruleBody('.panel'));
        $this->assertStringContainsString('margin: 0;', $this->ruleBody('.panel-flat'));
        $this->assertStringContainsString('gap: var(--space-grid);', $this->ruleBody('.grid'));
    }

    public function test_dashboard_and_hub_sections_use_shared_spacing_tokens(): void
    {
        $expectations = [
            '.dashboard-layout' => 'gap: var(--space-section);',
            '.service-summary' => 'gap: var(--space-stack);',
            '.service-summary__group' => 'gap: var(--space-stack);',
            '.service-summary__items' => 'gap: var(--space-grid);',
            '.module-hub__grid' => 'gap: var(--space-grid);',
            '.service-dashboard' => 'gap: var(--space-stack-loose);',
            '.service-dashboard__quick-links-grid' => 'gap: var(--space-grid);',
        ];

        foreach ($expectations as $selector => $declaration) {
            $this->assertStringContainsString($declaration, $this->ruleBody($selector), "{$selector} should use {$declaration}");
        }
    }

    public function test_panel_stack_spaces_page_level_cards(): void
    {
        $panelStack = $this->ruleBody('.panel-stack');

        $this->assertStringContainsString('flex-direction: column;', $panelStack);
        $this->assertStringContainsString('gap: var(--space-section);', $panelStack);
        $this->assertStringContainsString('margin: 0;', $this->ruleBody('.panel-stack > .panel'));
    }

    public function test_staff_list_and_create_pages_wrap_their_panels_in_a_panel_stack(): void
    {
        app(ModuleInstallationSynchronizer::class)->synchronize();
        $staff = User::factory()->protectedRole(AuthorizationProfile::ROLE_STAFF)->create();

        foreach ([
            'apes-cic.tickets.index',
            'apes-cic.cases.index',
            'shelter.cases.index',
            'petcare.consultations.index',
            'apes-cic.recruitment.index',
            'apes-cic.recruitment.applications.index',
        ] as $routeName) {
            $this->actingAs($staff)
                ->get(route($routeName))
                ->assertOk()
                ->assertSee('<div class="panel-stack">', false);
        }
    }

    public function test_admin_kpis_and_plugin_registry_use_shared_spacing_tokens(): void
    {
        $this->assertStringContainsString('gap: var(--space-stack-tight);', $this->ruleBody('.analytics-kpis'));
        $this->assertStringContainsString('padding: 0.8rem 1rem;', $this->ruleBody('.analytics-kpis > .panel-flat'));
        $this->assertStringContainsString('gap: var(--space-section);', $this->ruleBody('.module-registry'));
        $this->assertStringContainsString('gap: var(--space-grid);', $this->ruleBody('.module-registry__rows'));
        $this->assertStringContainsString('gap: var(--space-stack-tight);', $this->ruleBody('.module-registry__card-body'));
        $this->assertStringContainsString('margin-bottom: var(--space-stack);', $this->ruleBody('.module-registry__subcore-header'));
    }

    public function test_admin_overview_kpis_render_as_flat_tiles_in_the_kpi_grid(): void
    {
        app(ModuleInstallationSynchronizer::class)->synchronize();
        $admin = User::factory()->protectedRole(AuthorizationProfile::ROLE_ADMINISTRATOR)->create();

        $content = $this->actingAs($admin)
            ->get(route('admin.index'))
            ->assertOk()
            ->assertSee('<div class="grid analytics-kpis" role="list">', false)
            ->getContent();

        $this->assertSame(6, substr_count($content, '<div class="panel panel-flat" role="listitem">'));
        $this->assertStringContainsString('data-kpi="total-accounts"', $content);
    }

    public function test_admin_access_user_detail_and_maintenance_pages_use_a_panel_stack(): void
    {
        app(ModuleInstallationSynchronizer::class)->synchronize();
        $superAdmin = User::factory()->protectedRole(AuthorizationProfile::ROLE_SUPER_ADMIN)->create();
        $managed = User::factory()->protectedRole(AuthorizationProfile::ROLE_STAFF)->create();

        foreach ([
            route('admin.access.index'),
            route('admin.access.index', ['tab' => 'permissions']),
            route('admin.users.show', $managed),
            route('admin.maintenance.index'),
        ] as $url) {
            $this->actingAs($superAdmin)
                ->get($url)
                ->assertOk()
                ->assertSee('<div class="panel-stack">', false);
        }
    }

    public function test_auth_service_tiles_use_lighter_nested_spacing(): void
    {
        $this->assertStringContainsString('gap: var(--space-grid);', $this->ruleBody('.auth-service-tiles'));
        $this->assertStringContainsString('padding: 0.9rem 1.1rem;', $this->ruleBody('.auth-service-tiles > .panel-flat'));
        $this->assertStringContainsString('margin: 0;', $this->ruleBody('.auth-entry-grid > .panel'));
    }

    public function test_landing_and_staff_login_render_service_tiles_in_the_auth_grid(): void
    {
        app(ModuleInstallationSynchronizer::class)->synchronize();

        $this->get(route('home'))
            ->assertOk()
            ->assertSee('<div class="grid auth-service-tiles">', false)
            ->assertSee('<div class="grid auth-entry-grid">', false);

        $this->view('auth.login')
            ->assertSee('<div class="grid auth-service-tiles">', false);
    }

    public function test_profile_edit_stacks_its_sections(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('profile.edit'))
            ->assertOk()
            ->assertSee('<div class="panel-stack">', false);
    }

    public function test_public_recruitment_role_list_uses_the_stack_contract(): void
    {
        app(ModuleInstallationSynchronizer::class)->synchronize();
        $author = User::factory()->create();
        RecruitmentRole::factory()->open()->category('staff')->count(2)->create(['created_by' => $author->id]);

        $this->get(route('recruitment.index'))
            ->assertOk()
            ->assertSee('<ul class="stack stack-spaced" data-recruitment-role-list>', false)
            ->assertSee('<li class="panel panel-flat">', false);
    }
}
