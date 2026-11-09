<?php

namespace Tests\Unit\MilestoneVersion;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Scripts\MilestoneVersion\BranchRules;
use Scripts\MilestoneVersion\SemVer;

class BranchRulesTest extends TestCase
{
    /**
     * @return list<string>
     */
    private function check(string $branch): array
    {
        return BranchRules::check(
            $branch,
            fn (): array => [SemVer::parse('v1.3.0'), SemVer::parse('v1.4.0')],
            fn (int $issue): ?SemVer => match ($issue) {
                42 => SemVer::parse('v1.3.0'),
                43 => null,
                default => SemVer::parse('v1.4.0'),
            },
        );
    }

    /**
     * @return array<string, array{string}>
     */
    public static function validProvider(): array
    {
        return [
            'milestone branch' => ['milestone/v1-3-0-reporting'],
            'milestone branch without slug' => ['milestone/v1-4-0'],
            'issue branch' => ['chore/42-fix-login'],
            'cursor issue branch' => ['cursor/fix/42-login-loop'],
            'epic branch with matching version' => ['cursor/feature/42-v1-3-0-reporting'],
            'main' => ['main'],
            'dependabot' => ['dependabot/composer/laravel/framework-13.30.0'],
            'refs prefix' => ['refs/heads/milestone/v1-3-0-reporting'],
        ];
    }

    #[DataProvider('validProvider')]
    public function test_accepts_branches_that_follow_the_rule(string $branch): void
    {
        $this->assertSame([], $this->check($branch));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function invalidProvider(): array
    {
        return [
            'milestone number' => ['milestone/7-reporting', 'looks like a GitHub milestone number'],
            'milestone number in issue branch' => ['chore/42-milestone-7-login', 'looks like a GitHub milestone number'],
            'short milestone number' => ['feature/m7-login', 'looks like a GitHub milestone number'],
            'milestone branch without version' => ['milestone/operations', 'has no milestone version'],
            'milestone branch with dotted version' => ['milestone/v1.3.0-reporting', 'has no milestone version'],
            'closed or unknown milestone version' => ['milestone/v1-2-0-reporting', 'No open milestone has version v1.2.0'],
            'issue branch with another milestone version' => ['cursor/feature/42-v1-4-0-reporting', 'issue #42 is in milestone v1.3.0'],
            'issue branch version when the issue has no milestone' => ['chore/43-v1-3-0-login', 'issue #43 has no versioned milestone'],
        ];
    }

    #[DataProvider('invalidProvider')]
    public function test_rejects_branches_with_a_clear_message(string $branch, string $message): void
    {
        $this->assertStringContainsString($message, implode("\n", $this->check($branch)));
    }

    public function test_builds_issue_branch_names(): void
    {
        $this->assertSame('chore', BranchRules::typeFor('[v0.58.x] 03 [Chore] Milestone version tooling'));
        $this->assertSame('fix', BranchRules::typeFor('[Bug] Login loop'));
        $this->assertSame('chore/726-milestone-version-tooling-agents-ci', BranchRules::issueBranch('chore', 726, '[v0.58.x] 03 [Chore] Milestone version tooling for agents and CI'));
    }
}
