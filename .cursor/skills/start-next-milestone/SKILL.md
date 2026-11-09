---
name: start-next-milestone
description: Start work on the next milestone with the right version everywhere - sync main, find the next milestone with composer milestone:next, create the milestone/vX-Y-Z-<slug> branch from main, set the version fields once, and use the script's PR titles. Use when starting or resuming a milestone, creating a milestone branch, naming a PR, or when the user says "next milestone" or "start the milestone".
---

# Start the next milestone

Follows `.cursor/rules/milestone-versioning.mdc`: a milestone's version is the `vMAJOR.MINOR.PATCH` at the start of its GitHub milestone title, never GitHub's milestone number. The section names below are that rule's.

## Steps

1. **Sync main.** `git fetch origin --prune --tags`, then fast-forward `main` (`git switch main` and `git merge --ff-only origin/main`). Never rebase or force-push.
2. **Find the next milestone** ("The next milestone"). Run `composer milestone:next`. It prints the open milestone with the lowest version above the latest released tag, compared as semver, plus its milestone branch and the issue PR title format.
   - **Stop and report to the owner, without guessing,** if it fails, if a milestone title doesn't start with a valid `vMAJOR.MINOR.PATCH`, or if it is not the milestone you were asked to work on. Don't rename, create or delete milestones yourself.
   - Pass on any `Warning:` lines (open milestones at or below the latest tag, or without a version).
3. **Create the milestone branch from main** ("Names built from the version"), using the printed name or the same version with a shorter suffix slug: `git switch -c milestone/vX-Y-Z-<slug> origin/main`, then `git push -u origin HEAD`. Reuse the branch if it already exists. Check it: `composer milestone:check-branch -- "milestone/vX-Y-Z-<slug>"`.
4. **Set the version fields once** ("Version fields"): to the milestone's version, in the milestone PR into `main`. Issue PRs don't bump the version.
5. **Name issue branches and PRs with the script.**
   - Issue branch: `composer milestone:branch -- --issue <n>` prints `<type>/<n>-<slug>`.
   - Issue PR into the milestone branch: `composer milestone:pr-title -- <n>` prints `[vX.Y.Z] <issue title> (#n)` from the issue's own milestone.
   - Milestone PR into `main`: `vX.Y.Z: <suffix after the colon, or a summary>`.
   - Check any title you write yourself: `composer milestone:check-title -- "<title>"`.
6. CI (`milestone-version-check`) runs the same checks on every pull request. If it fails, fix the title or branch it names; don't work around the check.

## Never

- Write `Milestone 7`, `[#7]`, `M7` or `/milestone/7` (GitHub's milestone number) in a title, branch, commit subject, tag or release name.
- Hard-code or print a token. The script reads `GITHUB_TOKEN`/`GH_TOKEN` or uses the signed-in `gh` CLI.
