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

## Local development and preview (Herd)

Laravel Herd (Pro) on Windows is the only supported local setup. Use this project's own Herd site, profile, demo data and commands, and test against the demo data. Never suggest another local stack, container or virtual machine for local development. Guide: [docs/herd.md](docs/herd.md).

| Item | Value |
| --- | --- |
| Herd site | `myapes-account`, linked from the clone (`C:\Users\BambieMurphy\Documents\GitHub\MyAPES.Account` on the owner's PC); no junction or copy elsewhere |
| App URL | `http://myapes-account.test`, document root `public/` |
| Profile | [docs/herd/profile.md](docs/herd/profile.md) ([`profile.json`](docs/herd/profile.json) for the scripts): PHP 8.4 pinned with `herd isolate` (FastCGI, `php_sapi` `cgi-fcgi`), Herd MySQL 8.4 (`myapes_account_local`, root, empty password), Node 22 from Herd's nvm, npm 11.10+, Vite port 5175 |
| Mail | Herd's mail catcher, SMTP `127.0.0.1:2525` (Herd > Mail, or the Herd MCP `mail` tool). An optional standalone Mailpit (SMTP 1025, API 8025) is only for the reply loop (`INBOUND_MAIL_DRIVER=mailpit`) |
| Setup | `composer setup` (runs `scripts/herd-setup.ps1`; safe to run twice) |
| Live preview gate | Before any development work: `powershell -NoProfile -ExecutionPolicy Bypass -File scripts\preview.ps1`, then open <http://myapes-account.test> in Cursor's browser (see [`.cursor/rules/herd-local-dev.mdc`](.cursor/rules/herd-local-dev.mdc)) |
| Daily loop | Herd open, then `scripts\preview.ps1`: Vite hot reload at `http://myapes-account.test:5175` with no window, output in `storage/logs/vite-dev.log`, stopped with `-StopDevServer`. `composer run dev` adds the queue and a log tail and reuses that Vite |
| Demo data | `composer demo:seed` to load, `composer demo:reset` to wipe and reload; logins in [docs/herd/demo-data.md](docs/herd/demo-data.md) |
| Env file | `.env.example` (Herd values) |

- The agent shell's `php` and `node` may not be the project's: Herd's global defaults are PHP 8.5 and Node 26 on the owner's PC. Dot-source `scripts\herd-env.ps1` (Herd's PHP 8.4, Composer, MySQL client and Node 22 first on `PATH`), or use `herd php` and `herd composer`.
- Herd's settings (PHP versions, services, SSL, restarts) and the hosts file belong to the owner: ask before changing them, and never touch other sites, their databases or links.
- Demo data refuses to run unless `APP_ENV=local`. Never point it at production or Cloudron, and never copy real data into it.
- Local QA accounts come from `database/seeders/LocalQaSeeder.php` (local and testing only) and drive the in-app QA role switcher. Never copy their passwords anywhere.
- Cursor cloud and Linux agents have no Herd. Their agent-only path is `bash scripts/local/bootstrap.sh --fresh` and `bash scripts/local/dev.sh`, with `http://127.0.0.1:8000/` for UI checks. It is not a developer setup.
- In Windows PowerShell 5.1, pass `gh` bodies with `--body-file`, because inline double quotes get stripped.
- Node/npm for Vite builds: use **npm 11.10 or newer** (Node 22 ships npm 10 by default — upgrade Herd's Node 22 with `npm install -g npm@11` after `. .\scripts\herd-env.ps1`, before `npm ci`). Repo `.npmrc` sets `min-release-age=7`.
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

Work ships once per milestone, by the milestone's version, never by GitHub's milestone number: every issue committed on one `milestone/vX-Y-Z-<slug>` branch behind one draft pull request, **one merge commit** into `main`, one `vX.Y.Z` tag and GitHub Release, and one Cloudron deploy of that tag. The single rule is [`.cursor/rules/version-shipping.mdc`](.cursor/rules/version-shipping.mdc): the next version, the names, the version fields and release record, the flow and hotfixes. Agents run it with the [`complete-next-version`](.cursor/skills/complete-next-version/SKILL.md) and [`release-version`](.cursor/skills/release-version/SKILL.md) skills, or `/complete-next-version`. Before any work, run the live preview gate in [`.cursor/rules/herd-local-dev.mdc`](.cursor/rules/herd-local-dev.mdc).

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
- 2026-11-14: Laravel Herd Pro becomes the only local development and testing stack (v0.63.1). Local mail goes to Herd's mail catcher; the Mailpit inbound driver stays for an optional standalone Mailpit when testing the reply loop. Decided by Bambie (epic #789).
- The milestone branch is always the one `composer milestone:next` prints, even when an issue body names a slightly different slug.
- With Herd's PHP, `composer install` regenerating the optimised autoloader can take several minutes (66 s for `dump-autoload -o`, 30 s for `package:discover`, far longer on a cold first run); it is slow, not stuck. Right after it, the first `/healthz` can exceed `scripts\preview.ps1`'s 60 s timeout; run the gate again.
- Herd's own Vite process (`herd processes:start vite --site=myapes-account`) stopped with no output on 14 November 2026, so `scripts\preview.ps1` starts Vite itself with Herd's Node 22.
- With `core.autocrlf=true` on Windows, `vendor/bin/pint --test` reports `line_ending` (and knock-on brace and operator fixers) for every file in the CRLF working tree. Check changed files from their committed LF content (`git cat-file -p HEAD:<path>` into a temp folder, then Pint there) or rely on CI; never commit Pint's CRLF "fixes".
- Something outside the repo (likely Herd's dev-process badge) can delete `public/hot` while Vite keeps running, which makes pages load the built assets; `scripts\preview.ps1` rewrites it when it finds this repo's Vite running.
- `herd services:list` can show the MySQL service as `stopped` while it answers on 3306; the scripts check the port instead.
- 14 November 2026: the Herd site `myapes-account` was found secured (HTTPS) twice, the second time minutes after being unsecured (its nginx file in `~\.config\herd\config\valet\Nginx` gained a `listen 443 ssl` line). On HTTPS the browser blocks the http Vite assets from port 5175 as mixed content, so every page is unstyled and form posts can fail. Check with `Invoke-WebRequest -MaximumRedirection 0 http://myapes-account.test/` (a 301 to https means secured) or the Herd MCP `site` `info` (`secured`); with Bambie's approval, `site` `unsecure` puts it back on http. Afterwards Cursor's browser keeps replaying the cached 301s to https: load URLs with a fresh query string, or send CDP `Network.setCacheDisabled` (`Network.clearBrowserCache` is not allowed). The app sends no HSTS header.
- In Windows PowerShell 5.1, `gh api ... | ConvertFrom-Json | Format-Table` can print an empty table for a JSON array; loop over the items (`% { ... }`) to list them.
- `scripts\preview.ps1` can fail `npm ci` with `EPERM ... lightningcss.win32-x64-msvc.node` when this repo's Vite is still running from an older checkout. Run `scripts\preview.ps1 -StopDevServer`, then the gate again.
- The "SQLite test and package" check on a milestone PR stays red until the `chore: release vX.Y.Z` commit prepends the release record: `myapes:changelog-validate --base-ref` runs before the tests. Run `composer test` locally while the PR is a draft.
- Release order: run `php artisan myapes:changelog-prepare --type=<patch|minor|major>` first (it bumps from the current `VERSION` and sets `VERSION` and the runtime contract itself), then `composer milestone:bump -- vX.Y.Z --check`. Running `milestone:bump` first makes `changelog-prepare` prepare the version after next. Then raise the `array_slice($releases, N)` offset in `ReleaseHistoryCommandTest` by one, and update the current release's `type`, `references` and required phrases in the same test; the tool updates none of these.
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
