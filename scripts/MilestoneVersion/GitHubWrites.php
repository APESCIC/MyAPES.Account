<?php

namespace Scripts\MilestoneVersion;

/**
 * The lookups and changes that closing a finished milestone needs, on top of the read-only view.
 */
interface GitHubWrites extends GitHub
{
    /**
     * Issues in the milestone, open and closed, without pull requests.
     *
     * @return list<array{number: int, title: string, open: bool, stateReason: ?string, openSubIssues: int}>
     */
    public function milestoneIssues(int $milestone): array;

    /**
     * URL of the latest successful deploy-cloudron.yml run for the tag, or null when there is none.
     */
    public function successfulDeployRun(string $tag): ?string;

    public function commentIssue(int $number, string $body): void;

    /**
     * @param  'completed'|'not_planned'  $reason
     */
    public function closeIssue(int $number, string $reason): void;

    /**
     * Closes the milestone and appends the note to its description.
     */
    public function closeMilestone(int $number, string $note): void;
}
