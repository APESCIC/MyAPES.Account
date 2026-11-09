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

## Local development (Laragon)

Laragon 8 on Windows is the only supported local setup. Use this project's own Laragon project, profile, demo data and commands, and test against the demo data. Never suggest another local stack, container or virtual machine for local development. Guide: [docs/laragon.md](docs/laragon.md).

| Item | Value |
| --- | --- |
| Project folder | `C:\laragon\www\myapes-account` (on the owner's PC, a junction to `C:\Users\BambieMurphy\Documents\GitHub\MyAPES.Account`) |
| App URL | `http://myapes-account.test`, document root `public/` (Laragon Auto Virtual Hosts) |
| Profile | [docs/laragon/profile.md](docs/laragon/profile.md): PHP 8.4, MySQL 8.4 (`myapes_account_local`, root, empty password), Node 22, npm 11.10+, Vite port 5175 |
| Setup | `composer setup` (runs `scripts/laragon-setup.ps1`; safe to run twice) |
| Daily loop | Laragon started (**Start All**), then `composer run dev` for Vite hot reload |
| Demo data | `composer demo:seed` to load, `composer demo:reset` to wipe and reload; logins in [docs/laragon/demo-data.md](docs/laragon/demo-data.md) |
| Env file | `.env.example` (Laragon values) |

- The agent shell's `php` and `node` may not be Laragon's. Run `scripts/laragon-setup.ps1`, or put `C:\laragon\bin\php\php-8.4.*`, `C:\laragon\bin\mysql\mysql-8.4.*\bin` and `C:\laragon\bin\nodejs\node-v22` first on `PATH`.
- Laragon's settings (PHP version, SSL, restarts) and the hosts file belong to the owner: ask before changing them, and never touch other projects' folders, databases or vhosts.
- Demo data refuses to run unless `APP_ENV=local`. Never point it at production or Cloudron, and never copy real data into it.
- Local QA accounts come from `database/seeders/LocalQaSeeder.php` (local and testing only) and drive the in-app QA role switcher. Never copy their passwords anywhere.
- Cursor cloud and Linux agents have no Laragon. Their agent-only path is `bash scripts/local/bootstrap.sh --fresh` and `bash scripts/local/dev.sh`, with `http://127.0.0.1:8000/` for UI checks. It is not a developer setup.
- Full local check: `composer pre-pr-verify` (PowerShell) or `bash scripts/local/pre-pr-verify.sh` (agent-only).
- In Windows PowerShell 5.1, pass `gh` bodies with `--body-file`, because inline double quotes get stripped.
- Node/npm for Vite builds: use **npm 11.10 or newer** (Node 22 ships npm 10 by default — upgrade with `npm install -g npm@11` before `npm ci`). Repo `.npmrc` sets `min-release-age=7`.
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

## Milestone versioning

Every milestone, branch, PR title, release-history record, tag and release uses the milestone's version (`v0.58.3`), never GitHub's milestone number. The rule is [`.cursor/rules/milestone-versioning.mdc`](.cursor/rules/milestone-versioning.mdc).

- `composer milestone:next` prints the next milestone (the lowest open version above the latest tag) and its branch name. `composer milestone:pr-title -- <issue>` prints the PR title.
- `composer milestone:check-title -- "<title>"`, `milestone:check-branch -- "<branch>"` and `milestone:check-release -- vX.Y.Z "<release title>"` fail with the reason. CI runs the title and branch checks on every PR (`.github/workflows/milestone-version-check.yml`).
- Skills: [`start-next-milestone`](.cursor/skills/start-next-milestone/SKILL.md), [`milestone-release-titles`](.cursor/skills/milestone-release-titles/SKILL.md) and `/next-milestone`. Script: `scripts/milestone-version`.
- Optional local check before each push: `git config core.hooksPath .githooks`.

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
