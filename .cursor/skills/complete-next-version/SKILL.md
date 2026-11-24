---
name: complete-next-version
description: Complete the next version of this repository end to end - run the live preview gate, find the next milestone with composer milestone:next, create or resume its milestone/vX-Y-Z branch, commit every issue on it behind one draft PR, then hand over to the release-version skill for merge, release and deploy. Use when the user says "complete the next version", "next version", "next milestone", "start the milestone" or "resume the milestone".
---

# Complete the next version

Follows `.cursor/rules/version-shipping.mdc` and Cursor's `github-milestone-completion` skill (one plan, one branch, one PR). Run it from one plan; when a new chat resumes a milestone, carry on with that plan.

## Steps

1. **Sync then live preview gate.** Run Cursor's `repo-sync` first (merge `origin/main` and push). Then `.cursor/rules/herd-local-dev.mdc`: `powershell -NoProfile -ExecutionPolicy Bypass -File scripts\preview.ps1`, open the printed URL in Cursor's browser, confirm it loaded and say "Preview ready, starting work". `preview.ps1` may merge `origin/main` locally without pushing — that is not a substitute for `repo-sync`. Exit 3 means a decision: ask with AskQuestion. Exit 1 means a blocker: report it.
2. **Find the version.** `composer milestone:next`. Stop and report, without guessing, if it fails, prints a warning about a milestone you were asked to work on, or names a different milestone than the user asked for.
3. **Branch.** Use the printed milestone branch. Create it from `origin/main` if it is missing (`git switch -c <branch> origin/main`, `git push -u origin HEAD`), otherwise switch to it and run `repo-sync`. Merge `origin/dependencies` with a merge commit. Stop on any conflict. Call `SetActiveBranch`.
4. **Issues.** List the milestone's issues (`gh issue list --milestone "<title>" --state open`). Comment on each when work starts.
5. **Draft PR.** After the first commit, open one draft PR into `main` titled with the printed "Milestone PR" title, with `Closes #<n>` for every issue including the Epic.
6. **Work every issue in order** on the milestone branch. Before each issue run `repo-sync`. Small Conventional Commits with `Refs #<n>`; **commit and push as and when needed** so CI runs on the draft PR. Run `composer test` and Pint, check the change in the preview at https://myapes-account.test. Comment on the issue with the commit SHA when it is done; leave it open until the PR merges. Don't ask between issues; stop only for a failure or blocker the plan does not settle.
7. **Hand over** to the `release-version` skill once every issue is committed and pushed.

## Never

- Create per-issue branches or open a second PR into `main`.
- Bump the version for one issue.
- Use GitHub's milestone number in any name.
