# Agent instructions for MyAPES Account

This file is the source of truth for agents (Cursor cloud and IDE) working in this repository. If an issue template, wiki page or other doc disagrees with this file, follow this file and fix the other one in the same change.

The owner is Bambie Murphy. Cursor does the coding. Write UK English (organisation, behaviour, colour) in the product, docs, issues and pull requests.

## Ground rules

- Never print, paste or commit passwords, secrets, tokens or keys: not in chat, issues, pull requests, release notes, logs or docs.
- Before creating milestones, epics or issues on GitHub, write a report or Page for the owner to approve.
- End every report with next options and one recommended option.
- Work only in `APESCIC/MyAPES.Account`. Never open issues in another repository.

## Architecture and naming

- Layers are **Core > Modules > Plugins**: `app/Core`, `modules/<slug>`, `plugins/<slug>`. Core never depends on a Module or a Plugin. See [ADR 0001](docs/adr/0001-core-modules-plugins.md) and [docs/architecture.md](docs/architecture.md).
- Add packages with `php artisan make:module` and `php artisan make:plugin`; do not edit Core to register them.
- Recruitment vacancies use `RecruitmentRole`, never Spatie's `Role` (and not an Access job role).
- Follow the conventions of sibling files, reuse existing components, and do not change dependencies without approval.

## Local development

The owner's working copy is Laragon on Windows.

| Item | Value |
| --- | --- |
| Clone | `C:\laragon\www\myapes-account` |
| App URL | `http://myapes-account.test` |
| Daily loop | Start Laragon (Apache), then `composer run dev:laragon` |
| First bootstrap | `powershell -ExecutionPolicy Bypass -File .\scripts\local\bootstrap.ps1 -Laragon -Fresh` |
| Env file | `.env.laragon.example` (copied by `-Laragon`) |

- Cloud and Linux agents have no Laragon: use `bash scripts/local/dev.sh` and `http://127.0.0.1:8000/` for UI checks.
- Local QA accounts come from `database/seeders/LocalQaSeeder.php` (local and testing only). Never copy their passwords anywhere.
- Full local check: `composer pre-pr-verify` (PowerShell) or `bash scripts/local/pre-pr-verify.sh`.
- In Windows PowerShell 5.1, pass `gh` bodies with `--body-file`, because inline double quotes get stripped.
- Every GitHub Actions `uses:` must be pinned to a full 40-character commit SHA with a version comment (`action@<sha> # vX.Y.Z`). Mutable tags such as `@v2` are not allowed.

## Before you start

Pull the latest `main` before any new issue, task or job, and before creating any branch. The Cursor rule [`.cursor/rules/sync-before-work.mdc`](.cursor/rules/sync-before-work.mdc) has the exact steps:

1. `git status`: if there are uncommitted changes, stop and report them. Don't stash, reset or discard them.
2. `git fetch origin --prune`, then `git checkout main` and `git pull --ff-only origin main`.
3. Merge `origin/main` into the milestone branch (or create it from the updated `main` and push it), or into the issue branch you're resuming.
4. Only then create a new issue branch from the milestone branch.

If the pull isn't a fast-forward or a merge conflicts, stop and report it. Never force-push, `reset --hard` or rebase shared branches to get past it.

## Issues and milestones

- Live milestones: https://github.com/APESCIC/MyAPES.Account/milestones. Read the live titles before assigning work.
  ```bash
  gh api 'repos/APESCIC/MyAPES.Account/milestones?state=open&per_page=50' --jq '.[] | {number,title,open_issues}'
  ```
- Title prefix `[v0.N.x] NN` with a two-digit index. `00` is the epic; its body keeps a checklist of sub-issues (`- [ ] #123 Short title`), and each child is linked as a sub-issue.
- Labels: only `area:admin`, `area:public`, `area:auth`, `area:tests`, `area:cloudron`.
- Assignee: `apescic-director`.
- Commit issue work on the milestone branch (see [Milestone shipping](#milestone-shipping)), not on branches from `main`. Never commit on `main`.
- Update the issue as work goes: comment with the branch, commits and progress. Close each issue with a comment giving the commit SHA once its acceptance criteria are committed on the milestone branch. The milestone pull request uses `Closes #<n>` for the ship issue. Close the epic when every child is done.

## Milestone shipping

Work ships once per milestone, not once per change or issue. The Cursor rule [`.cursor/rules/milestone-shipping.mdc`](.cursor/rules/milestone-shipping.mdc) says the same.

1. Complete **all** the milestone's issues before the next stage.
2. All the milestone's work goes to `main` in **one merge**: a merge commit, never a squash.
3. Release.
4. Deploy.

- Each milestone has one branch, `milestone/<slug>` (or a name the owner gives), created from an up-to-date `main` when work starts (see [Before you start](#before-you-start)). Commit and push on it whenever needed.
- Nothing reaches `main`, and nothing is tagged, released or deployed, before stage 2.
- Stages 3 and 4 run together: `deploy-cloudron.yml` deploys, then publishes the GitHub Release.
- Hotfixes skip these stages only with Bambie's written approval recorded on the hotfix issue.

**Definition of done:** an issue is done when its acceptance criteria are met and committed on its branch. The milestone is done only after the single merge, release and deploy.

## Release metadata (once per milestone)

The milestone pull request into `main` bumps to the **next free patch version** (minor for a new capability) and prepends **exactly one** record to `resources/data/releases.json`. Issue commits on the milestone branch do not bump the version. In the same pull request:

- `VERSION`
- `resources/data/module-runtime-contract.json` (`application_version`)
- one new head record in `resources/data/releases.json`; never edit or reorder published records
- the version-pinned tests: `ChangeLogPageTest`, `HealthAndThemeTest`, `ModuleRollbackCompatibilityTest`, `ReleaseHistoryCommandTest` (version list, the `$releases[N]['date']` indexes, the tail `array_slice`, the current record's references and text)

`php artisan myapes:changelog-prepare --type=patch --title="…" --issue=<n> --pr=<n>` writes most of this. Then check:

```bash
git fetch origin
php artisan myapes:changelog-validate --base-ref=origin/main
```

- Release links must start with `https://github.com/APESCIC/MyAPES-Account/` (hyphen). The validator rejects other forms, even though the repository is `MyAPES.Account`.
- No credentials, personal data or exploitable detail in release notes.
- **If main moves** and your version is taken: rebase on the latest `main`, take the next free patch number, redo the bump and record, wait for CI again, then merge.

## Ship flow

Run this once per milestone, after every other issue in the milestone is closed.

1. Open one pull request from the milestone branch into `main`, with the release metadata above.
2. CI green: `gh pr checks <n> --watch`. Fix failures; re-run a job only for a proven flaky test, and log an issue for it.
3. Wait for Bambie's review or approval on the ship issue, then merge with a merge commit: `gh pr merge <n> --merge`.
4. Deploy by hand. A green `main` does not deploy itself. First check that no deploy is queued or running, and that `/healthz` does not already report the new version:
   ```bash
   gh run list -R APESCIC/MyAPES.Account -w deploy-cloudron.yml -L 3
   gh workflow run deploy-cloudron.yml -R APESCIC/MyAPES.Account --ref main -f app_version="$(git show origin/main:VERSION | tr -d '\r\n')"
   gh run watch <run-id> -R APESCIC/MyAPES.Account --exit-status
   ```
   After a successful deploy, the workflow publishes the GitHub Release (`v{VERSION}`). Do not create that release by hand.
5. Verify live: `curl -s https://myaccount.myapes.me.uk/healthz` must show `status: ok`, `version` equal to `VERSION`, and `release` equal to the merge SHA.

Deploy secrets (`CLOUDRON_FQDN`, `CLOUDRON_TOKEN`) live in the `cloudron-deploy` environment. If a run needs a secret, a permission or an approval, stop and tell the owner.

## Where to find things

- Wiki: https://github.com/APESCIC/MyAPES.Account/wiki. The source is [`docs/wiki`](docs/wiki), published by `.github/workflows/wiki-sync.yml` on merge to `main`. Edit through pull requests only, because direct wiki edits are overwritten.
- Discussions: https://github.com/APESCIC/MyAPES.Account/discussions, with forms in `.github/DISCUSSION_TEMPLATE`.
- Change log: `/change-log` in the app, and `resources/data/releases.json`.
- Security: [SECURITY.md](SECURITY.md). Conduct: [CODE_OF_CONDUCT.md](CODE_OF_CONDUCT.md).
- Developer guide: [docs/developer-guide.md](docs/developer-guide.md).
