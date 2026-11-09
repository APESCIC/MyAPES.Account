<?php

namespace Tests\Unit\MilestoneVersion;

use PHPUnit\Framework\TestCase;
use Scripts\MilestoneVersion\Cli;
use Scripts\MilestoneVersion\GitHub;
use Scripts\MilestoneVersion\Milestones;

class MilestoneVersionCliTest extends TestCase
{
    /**
     * @return array{int, string, string}
     */
    private function cli(string ...$argv): array
    {
        $github = new class implements GitHub
        {
            public function openMilestones(): array
            {
                return [
                    ['number' => 7, 'title' => 'v1.10.0 Beta: Search'],
                    ['number' => 5, 'title' => 'v1.9.0 Beta: Reporting and exports'],
                    ['number' => 3, 'title' => 'v1.2.0 Beta: Old'],
                    ['number' => 9, 'title' => 'Later: ActivityPub'],
                ];
            }

            public function tags(): array
            {
                return ['v1.8.2', 'v1.8.10', 'v1.9.0-beta.1', 'v1.2.0'];
            }

            public function issue(int $number): ?array
            {
                return match ($number) {
                    42 => ['number' => 42, 'title' => '[Chore] Fix login', 'milestone' => 'v1.9.0 Beta: Reporting and exports'],
                    44 => ['number' => 44, 'title' => 'Tidy docs', 'milestone' => 'Ship once per milestone'],
                    default => null,
                };
            }

            public function pullRequest(int $number): ?array
            {
                return $number === 100
                    ? ['number' => 100, 'title' => 'Milestone 7: Fix login', 'body' => "Closes #42\n", 'milestone' => null]
                    : null;
            }
        };

        $out = fopen('php://memory', 'w+');
        $err = fopen('php://memory', 'w+');
        $exitCode = (new Cli(fn (): GitHub => $github, $out, $err))->run($argv);
        rewind($out);
        rewind($err);

        return [$exitCode, stream_get_contents($out), stream_get_contents($err)];
    }

    public function test_next_picks_the_lowest_open_version_above_the_latest_tag(): void
    {
        [$exitCode, $out, $err] = $this->cli('next');

        $this->assertSame(Cli::OK, $exitCode);
        $this->assertStringContainsString('Version:          v1.9.0', $out);
        $this->assertStringContainsString('Latest tag:       v1.9.0-beta.1', $out);
        $this->assertStringContainsString('Milestone branch: milestone/v1-9-0-reporting-exports', $out);
        $this->assertStringContainsString('"v1.2.0 Beta: Old" is at or below the latest tag', $err);
        $this->assertStringContainsString('Ignored open milestone "Later: ActivityPub"', $err);
    }

    public function test_next_version_only_prints_just_the_version(): void
    {
        [$exitCode, $out] = $this->cli('next', '--version-only');

        $this->assertSame(Cli::OK, $exitCode);
        $this->assertSame('v1.9.0', trim($out));
    }

    public function test_pr_title_uses_the_issue_milestone(): void
    {
        [$exitCode, $out] = $this->cli('pr-title', '42');

        $this->assertSame(Cli::OK, $exitCode);
        $this->assertSame('[v1.9.0] Fix login (#42)', trim($out));
    }

    public function test_pr_title_stops_when_the_milestone_has_no_version(): void
    {
        [$exitCode, , $err] = $this->cli('pr-title', '44');

        $this->assertSame(Cli::FAILED, $exitCode);
        $this->assertStringContainsString('"Ship once per milestone", does not start with a version', $err);
    }

    public function test_check_title_with_explicit_version(): void
    {
        $this->assertSame(Cli::OK, $this->cli('check-title', '--', '[v1.3.0] Fix login (#42)', '--version', 'v1.3.0')[0]);

        [$exitCode, , $err] = $this->cli('check-title', 'Milestone 7: Fix login', '--version=v1.3.0');
        $this->assertSame(Cli::FAILED, $exitCode);
        $this->assertStringContainsString('Expected (--version): [v1.3.0] Fix login', $err);
    }

    public function test_check_title_for_a_pr_uses_its_linked_issue_milestone(): void
    {
        [$exitCode, , $err] = $this->cli('check-title', 'Milestone 7: Fix login', '--pr', '100');

        $this->assertSame(Cli::FAILED, $exitCode);
        $this->assertStringContainsString('Expected (milestone of issue #42): [v1.9.0] Fix login', $err);
        $this->assertSame(Cli::OK, $this->cli('check-title', '[v1.9.0] Fix login (#42)', '--pr', '100')[0]);
    }

    public function test_check_title_reads_the_issue_number_from_the_title(): void
    {
        $this->assertSame(Cli::OK, $this->cli('check-title', '[v1.9.0] Fix login (#42)')[0]);
        $this->assertSame(Cli::FAILED, $this->cli('check-title', '[v1.10.0] Fix login (#42)')[0]);
    }

    public function test_check_branch(): void
    {
        $this->assertSame(Cli::OK, $this->cli('check-branch', 'milestone/v1-9-0-reporting')[0]);
        $this->assertSame(Cli::OK, $this->cli('check-branch', 'chore/42-fix-login')[0]);

        [$exitCode, , $err] = $this->cli('check-branch', 'milestone/7-reporting');
        $this->assertSame(Cli::FAILED, $exitCode);
        $this->assertStringContainsString('looks like a GitHub milestone number', $err);
    }

    public function test_check_release(): void
    {
        $this->assertSame(Cli::OK, $this->cli('check-release', 'v1.9.0', 'v1.9.0 Beta', '--changelog', '## [1.9.0] - 2026-11-09')[0]);
        $this->assertSame(Cli::OK, $this->cli('check-release', 'v1.9.0', 'v1.9.0')[0]);

        [$exitCode, , $err] = $this->cli('check-release', 'v1.9.1', 'v1.9.0 Beta', '--changelog', '## [1.8.0]');
        $this->assertSame(Cli::FAILED, $exitCode);
        $this->assertStringContainsString('Tag v1.9.1 does not match the milestone version v1.9.0', $err);
        $this->assertStringContainsString('does not contain 1.9.0', $err);
    }

    public function test_unknown_command_is_a_usage_error(): void
    {
        $this->assertSame(Cli::USAGE, $this->cli('publish')[0]);
        $this->assertSame(Cli::USAGE, $this->cli('check-title')[0]);
    }

    public function test_milestone_names_drop_the_version_and_label(): void
    {
        $this->assertSame('SEO and social previews', Milestones::name('v1.31.0 Beta: SEO and social previews'));
        $this->assertSame('Operations', Milestones::name('v0.4.0 Operations'));
    }
}
