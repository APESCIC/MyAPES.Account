---
name: release-version
description: Ship a completed version - set the version fields once with composer milestone:bump and myapes:changelog-prepare, mark the milestone PR ready, get CI green, ask the ship-gate question, then merge once (merge commit), push the vX.Y.Z tag (release.yml publishes the Release), deploy that tag with deploy-cloudron.yml and close out. Use when every issue in the milestone is committed, or when the user asks to merge, release, tag or deploy the milestone.
---

# Release a version

Follows `.cursor/rules/version-shipping.mdc` and the `ship-gate` skill.

## Steps

1. **Sync.** Run `scripts\preview.ps1` (it merges `origin/main` into the milestone branch).
2. **Set the version once.** `composer milestone:bump -- vX.Y.Z` (it refuses a version that is not the next milestone's). Then `php artisan myapes:changelog-prepare --type=<patch|minor|major> --title="..." --issue=<n> --pr=<n>`, after checking the type with `--dry-run`. Update the version-pinned tests it names, then `php artisan myapes:changelog-validate --base-ref=origin/main` and `composer milestone:bump -- vX.Y.Z --check`.
3. **Last commit.** `chore: release vX.Y.Z`. Push.
4. **Ready.** `gh pr ready <pr>`, then `gh pr checks <pr> --watch`. Fix failures on the same branch.
5. **Check the names.** `composer milestone:check-release -- vX.Y.Z "<release title>"`, using the "Release title" from `composer milestone:next`.
6. **Ship gate.** Summarise the milestone, PR, checks, merge method, tag and deploy target, then ask with exactly: a. Merge, b. Release, c. Deploy, d. Merge, release and deploy, e. Stop here. Run only what was chosen; after each single step ask again with what remains.
7. **Merge.** `gh pr merge <pr> --merge --delete-branch`.
8. **Release.** Tag the merge commit and push the tag; `release.yml` publishes the Release:

   ```powershell
   git fetch origin; git tag -a vX.Y.Z -m "vX.Y.Z" <merge-sha>; git push origin vX.Y.Z
   gh run watch (gh run list --workflow release.yml --limit 1 --json databaseId --jq '.[0].databaseId') --exit-status
   ```

9. **Deploy.** Check no deploy is queued or running (`gh run list -w deploy-cloudron.yml -L 3`), then `gh workflow run deploy-cloudron.yml --ref vX.Y.Z` and `gh run watch <id> --exit-status`. Evidence: the run URL and `/healthz` showing `version` = `VERSION` and `release` = the tag's commit.
10. **Close out** the issues, the Epic and the milestone, then merge `origin/main` into `dependencies`. `milestone-close.yml` closes the milestone after the deploy; run `composer milestone:close-ready -- --tag vX.Y.Z` (no question needed) and confirm with `gh api repos/APESCIC/MyAPES.Account/milestones/<n> --jq .state` that it is `closed`. Post the completion message with the PR, merge commit, issues, release and deploy evidence.

## Never

- Squash or rebase the milestone PR, tag anything but the merge commit, or deploy `main` or a branch.
- Rewrite a past tag, Release or published `releases.json` record.
- Report a step without its command output.
