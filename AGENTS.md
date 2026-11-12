# Agent instructions for MyAPES Account

Cursor's user rules and skills set how work is done here (branches, issues, pull requests, merge, release, deploy, ship gate, decisions and safety). This file describes this repository and fills the named slots below. If anything here disagrees with them, Cursor's rules win and this file is corrected in the same milestone.

Among the repository's own docs, this file is the agent summary: if an issue template, wiki page or other doc disagrees with it, fix the other doc in the same change.

The owner is Bambie Murphy. Cursor does the coding. Write UK English (organisation, behaviour, colour) in the product, docs, issues and pull requests.

## About the app

MyAPES Account is the APES CIC account and service portal: service users, students, volunteers and staff sign in to manage their profile, tickets, cases, consultations, pet profiles, bookings and recruitment, and administrators run the workspace from Admin. It is a Laravel 12 app on PHP 8.4, live at <https://myaccount.myapes.me.uk> on the APES Cloudron.

- Repository: `APESCIC/MyAPES.Account`.

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
| Profile | [docs/laragon/profile.md](docs/laragon/profile.md): PHP 8.4 NTS through FastCGI (mod_fcgid), MySQL 8.4 (`myapes_account_local`, root, empty password), Node 22, npm 11.10+, Vite port 5175 |
| Setup | `composer setup` (runs `scripts/laragon-setup.ps1`; safe to run twice) |
| Live preview gate | Before any development work: `powershell -NoProfile -ExecutionPolicy Bypass -File scripts\preview.ps1`, then open <http://myapes-account.test> in Cursor's browser (see [`.cursor/rules/laragon-local-dev.mdc`](.cursor/rules/laragon-local-dev.mdc)) |
| Daily loop | Laragon started (**Start All**), then `scripts\preview.ps1`: Vite hot reload at `http://myapes-account.test:5175` with no window, output in `storage/logs/vite-dev.log`, stopped with `-StopDevServer`. `composer run dev` adds the queue and a log tail and reuses that Vite |
| Demo data | `composer demo:seed` to load, `composer demo:reset` to wipe and reload; logins in [docs/laragon/demo-data.md](docs/laragon/demo-data.md) |
| Env file | `.env.example` (Laragon values) |

- The agent shell's `php` and `node` may not be Laragon's. Run `scripts/laragon-setup.ps1`, or put the NTS build `C:\laragon\bin\php\php-8.4.*-nts-*` (the one Apache runs through FastCGI), `C:\laragon\bin\mysql\mysql-8.4.*\bin` and `C:\laragon\bin\nodejs\node-v22` first on `PATH`.
- Laragon's settings (PHP version, SSL, restarts) and the hosts file belong to the owner: ask before changing them, and never touch other projects' folders, databases or vhosts.
- Demo data refuses to run unless `APP_ENV=local`. Never point it at production or Cloudron, and never copy real data into it.
- Local QA accounts come from `database/seeders/LocalQaSeeder.php` (local and testing only) and drive the in-app QA role switcher. Never copy their passwords anywhere.
- Cursor cloud and Linux agents have no Laragon. Their agent-only path is `bash scripts/local/bootstrap.sh --fresh` and `bash scripts/local/dev.sh`, with `http://127.0.0.1:8000/` for UI checks. It is not a developer setup.
- In Windows PowerShell 5.1, pass `gh` bodies with `--body-file`, because inline double quotes get stripped.
- Node/npm for Vite builds: use **npm 11.10 or newer** (Node 22 ships npm 10 by default — upgrade with `npm install -g npm@11` before `npm ci`). Repo `.npmrc` sets `min-release-age=7`.
- Every GitHub Actions `uses:` must be pinned to a full 40-character commit SHA with a version comment (`action@<sha> # vX.Y.Z`). Mutable tags such as `@v2` are not allowed.

## Tests and checks

| Check | Command |
| --- | --- |
| Tests | `composer test` (PHPUnit), or `php artisan test --filter <Name>` for one suite |
| Code style | `vendor/bin/pint --test` (fix with `vendor/bin/pint`) |
| Release record | `php artisan myapes:changelog-validate --base-ref=origin/main` |
| Full pre-PR check | `composer pre-pr-verify` (PowerShell) or `bash scripts/local/pre-pr-verify.sh` (agent-only) |
| CI workflows | `.github/workflows/test-cloudron.yml`, `milestone-version-check.yml`, `codeql.yml`, `dependency-review.yml` |

## Issues and milestones

- Live milestones: https://github.com/APESCIC/MyAPES.Account/milestones. Read the live titles before assigning work.
- Title prefix `[v0.N.x] NN` with a two-digit index. `00` is the epic; its body keeps a checklist of sub-issues (`- [ ] #123 Short title`), and each child is linked as a sub-issue.
- Labels: only `area:admin`, `area:public`, `area:auth`, `area:tests`, `area:cloudron`.
- Assignee: `apescic-director`.
- Comment on each issue as work goes: the branch, the commits and progress. An issue is done when its acceptance criteria are committed on the milestone branch; it closes when the milestone pull request merges (`Closes #<n>`), and the epic closes after the deploy.
- Milestones close automatically, without asking: `.github/workflows/milestone-close.yml` runs `composer milestone:close-ready` after each successful Cloudron deploy and whenever an issue is closed or taken out of a milestone. A milestone closes once none of its issues is open (a finished epic is closed first) and either its `vX.Y.Z` tag was deployed or nothing in it shipped. Agents who find a milestone still open with all its issues closed run `composer milestone:close-ready` themselves, with no question; empty and unversioned milestones stay open.
- Before an issue is closed, Cursor ticks every acceptance-criteria box in its body and the issue's line in the epic checklist. An unticked box means the issue is not done: finish it, or record why it was dropped, before closing.

## Shipping and versioning

Work ships once per milestone, by the milestone's version, never by GitHub's milestone number: every issue committed on one `milestone/vX-Y-Z-<slug>` branch behind one draft pull request, **one merge commit** into `main`, one `vX.Y.Z` tag and GitHub Release, and one Cloudron deploy of that tag. The single rule is [`.cursor/rules/version-shipping.mdc`](.cursor/rules/version-shipping.mdc): the next version, the names, the version fields and release record, the flow and hotfixes. Agents run it with the [`complete-next-version`](.cursor/skills/complete-next-version/SKILL.md) and [`release-version`](.cursor/skills/release-version/SKILL.md) skills, or `/complete-next-version`. Before any work, run the live preview gate in [`.cursor/rules/laragon-local-dev.mdc`](.cursor/rules/laragon-local-dev.mdc).

Facts the rule relies on:

- `composer milestone:next`, `milestone:bump`, `milestone:check-title`, `milestone:check-branch`, `milestone:check-release` and `milestone:close-ready` (`scripts/milestone-version`). CI runs the title and branch checks on every PR (`.github/workflows/milestone-version-check.yml`). Optional local check before each push: `git config core.hooksPath .githooks`.
- Release links in `releases.json` must start with `https://github.com/APESCIC/MyAPES-Account/` (hyphen); `myapes:changelog-validate` rejects other forms. No credentials, personal data or exploitable detail in release notes.
- Dependabot opens grouped weekly pull requests into the long-lived `dependencies` branch, never `main`. Merge them there once green; merge `origin/dependencies` into the milestone branch when work starts, and `origin/main` into `dependencies` after each milestone ships.
- Security checks use only free GitHub features: Dependabot, dependency review and CodeQL (Actions and JavaScript/TypeScript; CodeQL has no PHP support, so PHP is covered by PHPUnit and the lint checks).
- If the milestone's version is already taken on `main`, stop and report it to the owner. Don't pick another number or rename the milestone yourself.

## Deploy and health

| Item | Value |
| --- | --- |
| Target | Cloudron LAMP app on the APES Cloudron |
| Domain | <https://myaccount.myapes.me.uk> |
| Deploy command | `gh workflow run deploy-cloudron.yml --ref vX.Y.Z`, then `gh run watch <id> --exit-status` |
| Deploys | The published release tag only (the workflow refuses a tag without a published Release). It backs up, uploads, migrates, activates, verifies `/healthz` and the OIDC redirect, and rolls back on failure |
| Auto-deploy couplings | None: pushing the tag only runs `release.yml`, which publishes the GitHub Release. Merging to `main` deploys nothing |
| Health URL | <https://myaccount.myapes.me.uk/healthz> |
| Version field | `version` (= `VERSION`) and `release` (the deployed commit) |
| Secrets live in | GitHub environment `cloudron-deploy` (`CLOUDRON_FQDN`, `CLOUDRON_TOKEN`). If a run needs a secret, a permission or an approval, stop and tell the owner |
| Server-side settings | `/app/data/shared/.env` on the app (key names only in docs, values in APES Vault 'API Keys & Tokens') |

## Decisions and gotchas

- 2026-11-12: email import uses the Cloudron `communications@myapes.me.uk` mailbox over IMAP on `mail.cloudron.apes.org.uk:993` SSL/TLS (Cloudron has no IMAP on 143), polled every minute with backoff, and imported mail moves to `Processed` or `Failed` folders emptied after 30 days. Decided by Bambie (epic #770).
- The milestone branch is always the one `composer milestone:next` prints, even when an issue body names a slightly different slug.
- `scripts\preview.ps1` can fail `npm ci` with `EPERM ... lightningcss.win32-x64-msvc.node` when this repo's Vite is still running from an older checkout. Run `scripts\preview.ps1 -StopDevServer`, then the gate again.
- The "SQLite test and package" check on a milestone PR stays red until the `chore: release vX.Y.Z` commit prepends the release record: `myapes:changelog-validate --base-ref` runs before the tests. Run `composer test` locally while the PR is a draft.
- `composer pre-pr-verify` runs the same release-record gate first, so it only passes once the release commit is in.
- The full suite needs more than the CLI's default 128 MB: `php artisan test` starts PHPUnit in a child process that ignores `-d`, so run `php -d memory_limit=-1 vendor/bin/phpunit` for a local full run (CI's `setup-php` has no limit).
- CI runs `php artisan lang:check --fail-on-missing`, which only skips keys containing `$` or `{`. Build dynamic keys with interpolation (`__("admin.x.{$type}.message")`), not concatenation, or the check reports them missing.
- `gh` with `--jq` in Windows PowerShell 5.1 loses the inner double quotes; pipe `--json` output to `ConvertFrom-Json` instead.

## Where to find things

- Wiki: https://github.com/APESCIC/MyAPES.Account/wiki. The source is [`docs/wiki`](docs/wiki), published by `.github/workflows/wiki-sync.yml` on merge to `main`. Edit through pull requests only, because direct wiki edits are overwritten.
- Discussions: https://github.com/APESCIC/MyAPES.Account/discussions, with forms in `.github/DISCUSSION_TEMPLATE`.
- Change log: `/change-log` in the app, and `resources/data/releases.json`.
- Security: [SECURITY.md](SECURITY.md). Conduct: [CODE_OF_CONDUCT.md](CODE_OF_CONDUCT.md).
- Developer guide: [docs/developer-guide.md](docs/developer-guide.md).
- API, webhooks and integrations (v0.62.0): [docs/api/openapi-v1.yaml](docs/api/openapi-v1.yaml), and the wiki pages [Integrations](docs/wiki/Integrations.md), [API clients](docs/wiki/API-clients.md) and [Webhooks](docs/wiki/Webhooks.md). Change `routes/api.php` and the spec together (`OpenApiSpecTest` checks them). Real keys, tokens and signing secrets live only in APES Vault ('API Keys & Tokens') and in Admin → Settings.
