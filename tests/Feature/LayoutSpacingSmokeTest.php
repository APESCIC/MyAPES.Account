<?php

namespace Tests\Feature;

use App\Core\Accounts\User;
use App\Services\AuthorizationProfile;
use App\Services\ModuleInstallationSynchronizer;
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
