---
name: start-next-milestone
description: Start work on the next milestone with the right version everywhere - sync main, find the next milestone with composer milestone:next, create the milestone/vX-Y-Z-<slug> branch from main, set the version fields once, and use the script's PR titles. Use when starting or resuming a milestone, creating a milestone branch, naming a PR, or when the user says "next milestone" or "start the milestone".
---

# Start the next milestone

Follows `.cursor/rules/milestone-versioning.mdc`: every milestone ships under its semantic version, never GitHub's milestone number.

## Steps

1. **Sync main.** `git fetch origin --prune --tags`, then fast-forward `main` (`git switch main` and `git merge --ff-only origin/main`). Never rebase or force-push.
2. **Find the next milestone.** Run `composer milestone:next`. It prints the open milestone with the lowest version above the latest `vX.Y.Z` tag, its milestone branch and the PR title format.
   - **Stop** if it fails or the milestone you were asked to work on is not the one it prints. A milestone whose title does not start with `vX.Y.Z` is not ready for work: ask for it to be retitled; never guess a version.
   - Read any `Warning:` lines out to the user (stale milestones at or below the latest tag).
3. **Create the milestone branch from main**, using the printed name or the same version with a shorter slug: `git switch -c milestone/vX-Y-Z-<slug> origin/main`, then `git push -u origin HEAD`. Reuse the branch if it already exists. Check it: `composer milestone:check-branch -- "milestone/vX-Y-Z-<slug>"`.
4. **Set the version fields once**, to the milestone version, in the milestone's release commit. Issue commits never bump the version.
   - In this repo: `VERSION`, `application_version` in `resources/data/module-runtime-contract.json` and one new head record in `resources/data/releases.json` (see "Release metadata" in `AGENTS.md`).
5. **Name issue branches and PRs with the script.**
   - Branch: `composer milestone:branch -- --issue <n>` prints `<type>/<n>-<slug>`.
   - PR title: `composer milestone:pr-title -- <n>` prints `[vX.Y.Z] Summary (#n)` from the issue's own milestone.
   - Check any title you write yourself: `composer milestone:check-title -- "<title>"`.
6. CI (`milestone-version-check`) runs the same checks on every pull request. If it fails, fix the title or branch it names; do not work around the check.

## Never

- Write `Milestone 7`, `[#7]` or `M7` in a title, branch, commit subject, tag or release name.
- Use the version you expect to ship instead of the milestone's version.
- Hard-code or print a token. The script reads `GITHUB_TOKEN`/`GH_TOKEN` or uses the signed-in `gh` CLI.
