---
name: release-and-deploy
description: Checklist for finishing a MyAPES Account change. Use when work is ready to commit, when opening or merging a pull request, when bumping VERSION, or when deploying to Cloudron.
---

# Release and deploy

`AGENTS.md` is the source of truth. This skill is the command checklist. Do not invent a deploy CLI. Do not create the GitHub Release by hand unless the publish job failed.

## Checklist

1. Focused tests for the change, or a written reason there is nothing to test.
2. Epic checklist ticked. Progress comment on the epic and the leaf issue (branch, then pull request).
3. Pull request open with `Closes #<n>` for each issue this pull request finishes.
4. Release metadata in that same pull request (needs the pull request number):

   ```bash
   php artisan myapes:changelog-prepare --type=patch --title="Short public title" --issue=<n> --pr=<n>
   ```

   Replace every `TODO:` in the new `resources/data/releases.json` head. The command rewrites `$releases[N]['date']` indexes and the version strings. It does not bump `array_slice($releases, N)` in `tests/Feature/ReleaseHistoryCommandTest.php`, and it does not rewrite assertions that name the current release title, issue, or pull request. Update those so they describe the new head, then:

   ```bash
   git fetch origin
   php artisan myapes:changelog-validate --base-ref=origin/main
   ```

5. On Windows Laragon, `composer pre-pr-verify`. On Linux without PowerShell, run the steps in `scripts/local/pre-merge.ps1` (fetch, changelog validate, the PHPUnit files named there, `npm run test:frontend`, `npm run build`).
6. **Test MyAPES Core** green, then:

   ```bash
   gh pr checks --watch --fail-fast
   gh pr merge --merge --delete-branch
   git fetch origin main
   git rev-parse origin/main
   ```

7. Deploy (GitHub Actions secrets `CLOUDRON_FQDN` and `CLOUDRON_TOKEN` on environment `cloudron-deploy`; do not copy them into the repo):

   ```bash
   version=$(git show origin/main:VERSION | tr -d '\r\n')
   gh workflow run "Deploy MyAPES Core to Cloudron" --ref main -f app_version="$version"
   gh run list --workflow "Deploy MyAPES Core to Cloudron" --limit 1
   gh run watch <run-id>
   ```

8. The same workflow's **Publish GitHub Release** job writes notes with `scripts/deploy/github-release-notes.sh` and creates tag `v{VERSION}` and release name `{VERSION} Beta` on the deployed SHA.
9. Verify:

   ```bash
   curl --fail --silent --show-error https://myaccount.myapes.me.uk/healthz
   gh release view "v$version" --json tagName,name,targetCommitish,url
   ```

   `version` matches `VERSION`. `release` matches the deployed SHA. `/change-log` shows the new head record.
10. Close the epic when every child is done. Close the milestone, titled with the last shipped version, when it has no open issues. If the API returns 403, say so. A person does that in GitHub.

## Stop and report

Stop, leave the shipping issue open, and give the exact next command when:

- CI is red (fix first; do not merge).
- `gh workflow run` or the deploy job fails for a missing secret, a missing permission, or a required reviewer.
- Issue sidebar fields or the milestone cannot be updated (403).

## Files the prepare command keeps in sync

- `VERSION`
- `resources/data/releases.json`
- `resources/data/module-runtime-contract.json` (`application_version`)
- Version-pinned tests in `app/Support/ReleaseHistoryPreparer.php`

`release-commit-map.json` is a backfill map. Do not edit it for a normal release.
