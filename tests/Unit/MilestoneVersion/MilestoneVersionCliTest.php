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
    private ?string $root = null;

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
        $exitCode = (new Cli(fn (): GitHub => $github, $out, $err, $this->root))->run($argv);
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
        $this->assertSame(Cli::OK, $this->cli('check-release', 'v1.9.0', 'v1.9.0 Reporting and exports', '--changelog', '## [1.9.0] - 2026-11-09')[0]);
        $this->assertSame(Cli::OK, $this->cli('check-release', 'v1.9.0', 'v1.9.0 Reporting and exports', '--version', 'v1.9.0')[0]);

        [$exitCode, , $err] = $this->cli('check-release', 'v1.9.0', 'v1.9.0 Beta');
        $this->assertSame(Cli::FAILED, $exitCode);
        $this->assertStringContainsString('The release title should be "v1.9.0 Reporting and exports"', $err);

        [$exitCode, , $err] = $this->cli('check-release', 'v1.9.1', 'v1.9.0 Reporting and exports', '--changelog', '## [1.8.0]');
        $this->assertSame(Cli::FAILED, $exitCode);
        $this->assertStringContainsString('Tag v1.9.1 does not match the milestone version v1.9.0', $err);
        $this->assertStringContainsString('does not contain 1.9.0', $err);
    }

    public function test_next_prints_the_milestone_pr_and_release_titles(): void
    {
        [, $out] = $this->cli('next');

        $this->assertStringContainsString('Milestone PR:     v1.9.0: Reporting and exports', $out);
        $this->assertStringContainsString('Release title:    v1.9.0 Reporting and exports', $out);
    }

    public function test_check_title_rejects_milestone_numbers_and_wrong_versions(): void
    {
        $this->assertSame(Cli::OK, $this->cli('check-title', '[v1.3.0] Fix login (#42)', '--version', 'v1.3.0')[0]);

        foreach (['Milestone 7: Fix login', '[#7] Fix login', '[v1.4.0] Fix login', 'Fix login'] as $title) {
            $this->assertSame(Cli::FAILED, $this->cli('check-title', $title, '--version', 'v1.3.0')[0], $title);
        }
    }

    public function test_bump_sets_the_fields_for_the_next_milestone_only(): void
    {
        $this->root = sys_get_temp_dir().'/milestone-bump-'.bin2hex(random_bytes(4));
        mkdir($this->root.'/scripts/MilestoneVersion', recursive: true);
        file_put_contents($this->root.'/VERSION', "1.8.10\n");
        file_put_contents($this->root.'/scripts/MilestoneVersion/fields.json', '{"fields": [{"file": "VERSION", "type": "plain"}], "notes": ["Update the changelog."]}');

        try {
            [$exitCode, , $err] = $this->cli('bump', 'v1.10.0');
            $this->assertSame(Cli::FAILED, $exitCode);
            $this->assertStringContainsString('v1.10.0 is not the next milestone\'s version', $err);
            $this->assertSame(Cli::FAILED, $this->cli('bump', 'v1.9.0', '--check')[0]);

            [$exitCode, $out] = $this->cli('bump', 'v1.9.0');
            $this->assertSame(Cli::OK, $exitCode);
            $this->assertStringContainsString('Set VERSION: 1.8.10 -> 1.9.0', $out);
            $this->assertStringContainsString('Next: Update the changelog.', $out);
            $this->assertSame(Cli::OK, $this->cli('bump', 'v1.9.0', '--check')[0]);
            $this->assertSame(Cli::OK, $this->cli('bump', 'v2.0.0', '--offline', '--dry-run')[0]);
            $this->assertSame("1.9.0\n", file_get_contents($this->root.'/VERSION'));
        } finally {
            unlink($this->root.'/scripts/MilestoneVersion/fields.json');
            unlink($this->root.'/VERSION');
            rmdir($this->root.'/scripts/MilestoneVersion');
            rmdir($this->root.'/scripts');
            rmdir($this->root);
        }
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
