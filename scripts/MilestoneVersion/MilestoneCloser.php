<?php

namespace Scripts\MilestoneVersion;

use Closure;

/**
 * Decides whether a milestone is ready to close: no issue open (an open Epic whose sub-issues are all closed is
 * closed first) and either its vX.Y.Z tag was deployed, or nothing in it shipped. Empty and unversioned
 * milestones are never closed.
 */
final class MilestoneCloser
{
    private const NOT_SHIPPED = ['not_planned', 'duplicate'];

    /**
     * @param  array{number: int, title: string}  $milestone
     * @param  list<array{number: int, title: string, open: bool, stateReason: ?string, openSubIssues: int}>  $issues
     * @param  Closure(SemVer): ?string  $deployRun  URL of a successful deploy run for the tag, or null.
     * @return array{close: bool, reason: string, epics: list<int>, epicReason: string, evidence: ?string}
     */
    public static function decide(array $milestone, array $issues, Closure $deployRun): array
    {
        $skip = fn (string $reason): array => ['close' => false, 'reason' => $reason, 'epics' => [], 'epicReason' => 'completed', 'evidence' => null];

        $version = SemVer::fromMilestoneTitle($milestone['title']);
        if ($version === null) {
            return $skip('its title does not start with a version (vX.Y.Z)');
        }
        if ($issues === []) {
            return $skip('it has no issues');
        }

        $openWork = array_filter($issues, fn (array $i): bool => $i['open'] && ! self::isEpic($i['title']));
        if ($openWork !== []) {
            return $skip('issues still open: '.self::numbers($openWork));
        }

        $openEpics = array_values(array_filter($issues, fn (array $i): bool => $i['open']));
        $blockedEpics = array_filter($openEpics, fn (array $i): bool => $i['openSubIssues'] > 0);
        if ($blockedEpics !== []) {
            return $skip('Epic '.self::numbers($blockedEpics).' still has open sub-issues');
        }

        $work = array_filter($issues, fn (array $i): bool => ! self::isEpic($i['title']));
        $shipped = array_filter($work === [] ? $issues : $work, fn (array $i): bool => ! $i['open'] && ! in_array($i['stateReason'], self::NOT_SHIPPED, true));
        $epics = array_map(fn (array $i): int => $i['number'], $openEpics);

        if ($shipped === []) {
            return ['close' => true, 'reason' => 'nothing in it shipped (every issue was closed as not planned)', 'epics' => $epics, 'epicReason' => 'not_planned', 'evidence' => null];
        }

        $run = $deployRun($version);
        if ($run === null) {
            return $skip(sprintf('no successful deploy-cloudron run for %s yet', $version));
        }

        return ['close' => true, 'reason' => sprintf('%s deployed: %s', $version, $run), 'epics' => $epics, 'epicReason' => 'completed', 'evidence' => $run];
    }

    /**
     * "[v0.61.x] 00 [Epic] Admin reply mailbox" and "[Epic] v0.6.0 Reporting" are Epics.
     */
    public static function isEpic(string $title): bool
    {
        return preg_match('/\[epic\]|^\[v\d+\.\d+\.x\]\s+00\b/i', trim($title)) === 1;
    }

    /**
     * @param  array<array{number: int}>  $issues
     */
    private static function numbers(array $issues): string
    {
        return implode(', ', array_map(fn (array $i): string => '#'.$i['number'], $issues));
    }
}
