<?php

namespace Tests\Unit\MilestoneVersion;

use PHPUnit\Framework\TestCase;
use Scripts\MilestoneVersion\MilestoneCloser;
use Scripts\MilestoneVersion\SemVer;

class MilestoneCloserTest extends TestCase
{
    private const MILESTONE = ['number' => 7, 'title' => 'v1.9.0 Beta: Reporting and exports'];

    private const RUN = 'https://github.com/APESCIC/MyAPES.Account/actions/runs/1';

    /**
     * @return array{number: int, title: string, open: bool, stateReason: ?string, openSubIssues: int}
     */
    private static function issue(int $number, bool $open, ?string $stateReason = 'completed', string $title = 'Fix login', int $openSubIssues = 0): array
    {
        return ['number' => $number, 'title' => $title, 'open' => $open, 'stateReason' => $open ? null : $stateReason, 'openSubIssues' => $openSubIssues];
    }

    /**
     * @param  list<array{number: int, title: string, open: bool, stateReason: ?string, openSubIssues: int}>  $issues
     * @return array{close: bool, reason: string, epics: list<int>, epicReason: string, evidence: ?string}
     */
    private static function decide(array $issues, ?string $run = self::RUN, array $milestone = self::MILESTONE): array
    {
        return MilestoneCloser::decide($milestone, $issues, function (SemVer $version) use ($run): ?string {
            self::assertSame('v1.9.0', $version->tag());

            return $run;
        });
    }

    public function test_an_open_issue_keeps_the_milestone_open(): void
    {
        $decision = self::decide([self::issue(1, false), self::issue(2, true)]);

        $this->assertFalse($decision['close']);
        $this->assertSame('issues still open: #2', $decision['reason']);
    }

    public function test_shipped_work_waits_for_a_successful_deploy(): void
    {
        $decision = self::decide([self::issue(1, false)], run: null);

        $this->assertFalse($decision['close']);
        $this->assertSame('no successful deploy-cloudron run for v1.9.0 yet', $decision['reason']);
    }

    public function test_shipped_and_deployed_closes_with_the_run_as_evidence(): void
    {
        $decision = self::decide([self::issue(1, false), self::issue(2, false, 'not_planned')]);

        $this->assertTrue($decision['close']);
        $this->assertSame(self::RUN, $decision['evidence']);
        $this->assertSame([], $decision['epics']);
    }

    public function test_nothing_shipped_closes_without_a_deploy(): void
    {
        $decision = self::decide([self::issue(1, false, 'not_planned'), self::issue(2, false, 'duplicate')], run: null);

        $this->assertTrue($decision['close']);
        $this->assertNull($decision['evidence']);
        $this->assertStringContainsString('nothing in it shipped', $decision['reason']);
    }

    public function test_an_empty_milestone_stays_open(): void
    {
        $decision = self::decide([]);

        $this->assertFalse($decision['close']);
        $this->assertSame('it has no issues', $decision['reason']);
    }

    public function test_an_open_epic_with_closed_sub_issues_is_closed_first(): void
    {
        $decision = self::decide([self::issue(10, true, title: '[v1.9.x] 00 [Epic] Reporting'), self::issue(1, false)]);

        $this->assertTrue($decision['close']);
        $this->assertSame([10], $decision['epics']);
        $this->assertSame('completed', $decision['epicReason']);
    }

    public function test_an_epic_with_nothing_shipped_closes_as_not_planned(): void
    {
        $decision = self::decide([self::issue(10, true, title: '[Epic] v1.9.0 Reporting'), self::issue(1, false, 'not_planned')], run: null);

        $this->assertTrue($decision['close']);
        $this->assertSame('not_planned', $decision['epicReason']);
    }

    public function test_an_open_epic_with_open_sub_issues_keeps_the_milestone_open(): void
    {
        $decision = self::decide([self::issue(10, true, title: '[v1.9.x] 00 Reporting', openSubIssues: 2), self::issue(1, false)]);

        $this->assertFalse($decision['close']);
        $this->assertSame('Epic #10 still has open sub-issues', $decision['reason']);
    }

    public function test_an_unversioned_milestone_is_skipped(): void
    {
        $decision = self::decide([self::issue(1, false)], milestone: ['number' => 3, 'title' => 'Ship once per milestone']);

        $this->assertFalse($decision['close']);
        $this->assertStringContainsString('does not start with a version', $decision['reason']);
    }

    public function test_epic_titles(): void
    {
        $this->assertTrue(MilestoneCloser::isEpic('[v0.61.x] 00 [Epic] Admin reply mailbox (IMAP) settings'));
        $this->assertTrue(MilestoneCloser::isEpic('[Epic] v0.6.0 Reporting'));
        $this->assertFalse(MilestoneCloser::isEpic('[v0.61.x] 01 Shared encrypted settings store'));
        $this->assertFalse(MilestoneCloser::isEpic('[v0.61.x] 100 Something'));
    }
}
