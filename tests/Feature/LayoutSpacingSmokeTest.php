<?php

namespace Tests\Feature;

use App\Core\Accounts\User;
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
