<?php

namespace Tests\Unit\MilestoneVersion;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Scripts\MilestoneVersion\SemVer;
use Scripts\MilestoneVersion\TitleRules;

class TitleRulesTest extends TestCase
{
    /**
     * @return array<string, array{string}>
     */
    public static function validProvider(): array
    {
        return [
            'PR title' => ['[v1.3.0] Fix login (#42)'],
            'numbered issue title' => ['[v1.3.x] 07 Fix login'],
            'typed Epic title' => ['[Epic] v1.3.0 Reporting'],
            'release title' => ['v1.3.0 Beta'],
            'release title with name' => ['v1.3.0 - Public UX & compliance'],
            'milestone PR into main' => ['v1.3.0: Public UX & compliance'],
            'milestone title' => ['v1.3.0 Beta: Reporting'],
            'issue title with colon' => ['v1.3.0: Change Log Hub entry'],
            'number in summary is fine' => ['[v1.3.0] Support 2 factor codes (#42)'],
        ];
    }

    #[DataProvider('validProvider')]
    public function test_accepts_titles_that_carry_the_milestone_version(string $title): void
    {
        $this->assertSame([], TitleRules::check($title, SemVer::parse('v1.3.0')));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function invalidProvider(): array
    {
        return [
            'milestone number' => ['Milestone 7: Fix login', '"Milestone 7" is a GitHub milestone number'],
            'bracketed milestone number' => ['[#7] Fix login', '"[#7]" looks like a GitHub milestone number'],
            'short milestone number' => ['M7: Fix login', '"M7" looks like a GitHub milestone number'],
            'milestone URL path' => ['[v1.3.0] Fix login for /milestone/7', '"/milestone/7" is a GitHub milestone number'],
            'mismatched version' => ['[v1.2.0] Fix login (#42)', '"v1.2.0" does not match the milestone version v1.3.0'],
            'mismatched minor' => ['[v1.2.x] 07 Fix login', '"v1.2.x" does not match the milestone version v1.3.0'],
            'mismatched pre-release' => ['[v1.3.0-beta.1] Fix login', '"v1.3.0-beta.1" does not match the milestone version v1.3.0'],
            'incomplete version' => ['[v1.3] Fix login', '"v1.3" is not a full version'],
            'no version' => ['Fix login (#42)', 'does not start with the milestone version'],
            'version not at the start' => ['Fix login for v1.3.0', 'does not start with the milestone version'],
            'version only' => ['[v1.3.0]', 'does not start with the milestone version'],
        ];
    }

    #[DataProvider('invalidProvider')]
    public function test_rejects_titles_with_a_clear_message(string $title, string $message): void
    {
        $problems = TitleRules::check($title, SemVer::parse('v1.3.0'));

        $this->assertNotSame([], $problems);
        $this->assertStringContainsString($message, implode("\n", $problems));
    }

    public function test_pr_title_strips_issue_prefixes_and_adds_the_issue_number(): void
    {
        $version = SemVer::parse('v0.58.3');

        $this->assertSame(
            '[v0.58.3] Milestone version tooling for agents and CI (#726)',
            TitleRules::prTitle($version, '[v0.58.x] 03 [Chore] Milestone version tooling for agents and CI', 726),
        );
        $this->assertSame('[v0.58.3] Fix login (#42)', TitleRules::prTitle($version, 'v0.58.3: Fix login', 42));
    }

    public function test_suggestion_replaces_a_milestone_number_with_the_version(): void
    {
        $this->assertSame('[v1.3.0] Fix login', TitleRules::suggestion('Milestone 7: Fix login', SemVer::parse('v1.3.0')));
        $this->assertSame('[v1.3.0] Fix login', TitleRules::suggestion('[#7] Fix login', SemVer::parse('v1.3.0')));
    }
}
