# Agent instructions for MyAPES Account

Cursor's user rules and skills set how work is done here (branches, issues, pull requests, merge, release, deploy, ship gate, decisions and safety). This file describes this repository and fills the named slots below. If anything here disagrees with them, Cursor's rules win and this file is corrected in the same milestone.

Among the repository's own docs, this file is the agent summary: if an issue template, wiki page or other doc disagrees with it, fix the other doc in the same change.

Cursor user rules and personal skills (Agent Store) own process; this file fills named slots only.

The owner is Bambie Murphy. Write UK English (organisation, behaviour, colour, licence) in the product, docs, issues and pull requests. Never print, paste or commit secrets. Secrets live only in APES Vault (Bitwarden) and in the documented server/Admin locations — never in git.

## About the app

MyAPES Account is the APES CIC account and service portal: service users, students, volunteers and staff sign in to manage their profile, tickets, cases, consultations, pet profiles, bookings and recruitment, and administrators run the workspace from Admin. It is a Laravel 13 app on PHP 8.4, live at <https://myaccount.myapes.me.uk> on the APES Cloudron.

- Repository: `APESCIC/MyAPES.Account`. Work only in this repository. Never open issues in another repository.

## Stack and architecture

- Layers are **Core > Modules > Plugins**: `app/Core`, `modules/<slug>`, `plugins/<slug>`. Core never depends on a Module or a Plugin. See [ADR 0001](docs/adr/0001-core-modules-plugins.md) and [docs/architecture.md](docs/architecture.md).
- Add packages with `php artisan make:module` and `php artisan make:plugin`; do not edit Core to register them.
- Recruitment vacancies use `RecruitmentRole`, never Spatie's `Role` (and not an Access job role).
- Follow the conventions of sibling files, reuse existing components, and do not change dependencies without approval.
- Frontend: Vite on port 5175; Node 22 with npm 11.10+ (`npm run build`, `npm run test:frontend`).

## Local development and preview

Laravel Herd (Pro) on Windows is the only supported local setup. Use this project's own Herd site, profile, demo data and commands, and test against the fake demo data. Never suggest Laragon, containers, virtual machines or any other local stack for local development. Guide: [docs/herd.md](docs/herd.md).

| Item | Value |
| --- | --- |
| Clone | `C:\Users\BambieMurphy\Documents\GitHub\MyAPES.Account` (linked as the Herd site; no junction or copy elsewhere) |
| Herd site | `myapes-account` (linked; PHP 8.4 isolated via `herd isolate`, FastCGI `cgi-fcgi`) |
| Preview URL | `https://myapes-account.test` (Herd secure; `http://` redirects), document root `public/` |
| Preview command | `powershell -NoProfile -ExecutionPolicy Bypass -File scripts\preview.ps1` |
| HMR port | Vite at `https://myapes-account.test:5175` (Herd's certificate); stop with `scripts\preview.ps1 -StopDevServer` |
| Env file | `.env.example` (key names only, never values) |
| Database | Herd MySQL 8.4 on `127.0.0.1:3306`, database `myapes_account_local`, `root`, empty password |
| Mail | Herd mail catcher, SMTP `127.0.0.1:2525`. Optional standalone Mailpit (SMTP 1025, API 8025) only for the reply loop (`INBOUND_MAIL_DRIVER=mailpit`) |
| Setup | `composer setup` (runs `scripts/herd-setup.ps1`; safe to run twice) |
| Demo data | `composer demo:seed` / `composer demo:reset` (refuse unless `APP_ENV=local`); logins in [docs/herd/demo-data.md](docs/herd/demo-data.md) |
| Profile | [docs/herd/profile.md](docs/herd/profile.md) / [`profile.json`](docs/herd/profile.json) |

Live preview gate slots and Herd day-to-day detail: [`.cursor/rules/herd-local-dev.mdc`](.cursor/rules/herd-local-dev.mdc).

### Local development with Laravel Herd

- Dot-source `scripts\herd-env.ps1` (Herd's PHP 8.4, Composer, MySQL client and Node 22 first on `PATH`), or use `herd php` and `herd composer`. Herd's global defaults may be PHP 8.5 and another Node.
- Daily loop: Herd open, then `scripts\preview.ps1`. `composer run dev` adds the queue and a log tail and reuses that Vite.
- Demo data is fake only. Never point it at production or Cloudron, and never copy real personal, HR, case or production data into it.
- Developer sign-in (local Herd only): on by default when `APP_ENV=local` and `QA_LOGIN_ENABLED` is unset or true. Amber Developer sign-in on `/login`, `/staff/login` and `/qa`; QA pill switches profile. QA accounts use unknown random passwords — never copy passwords into docs. Off in testing, staging and production; leave unset on Cloudron.
- Herd's settings (PHP versions, services, SSL, restarts) and the hosts file belong to the owner: ask before changing them, and never touch other sites, their databases or links.
- Cursor cloud and Linux agents have no Herd. Their agent-only path is `bash scripts/local/bootstrap.sh --fresh` and `bash scripts/local/dev.sh`, with `http://127.0.0.1:8000/` for UI checks. It is not a developer setup.
- Upgrade Herd's Node 22 to npm 11.10+ (`npm install -g npm@11` after `. .\scripts\herd-env.ps1`) before `npm ci`. Repo `.npmrc` sets `min-release-age=7`.
- Every GitHub Actions `uses:` must be pinned to a full 40-character commit SHA with a version comment (`action@<sha> # vX.Y.Z`). Mutable tags such as `@v2` are not allowed.
- In Windows PowerShell 5.1, pass `gh` bodies with `--body-file`, because inline double quotes get stripped.

## Tests and checks

| Check | Command |
| --- | --- |
| Tests | `composer test` (PHPUnit), or `php artisan test --filter <Name>` for one suite |
| Code style | `vendor/bin/pint --test` (fix with `vendor/bin/pint`) |
| Release record | `php artisan myapes:changelog-validate --base-ref=origin/main` |
| Full pre-PR check | `composer pre-pr-verify` (PowerShell) or `bash scripts/local/pre-pr-verify.sh` (agent-only) |
| Frontend | `npm run test:frontend`, `npm run build` |
| Theme contrast | `php artisan test --filter ThemeContrastTest` (WCAG AA token pairs; fails CI on regression) |
| Theme snapshot manifest | `php artisan test --filter ThemeSnapshotManifestTest` (40 committed PNGs under `tests/Browser/snapshots/theme/`) |
| Theme visual (Playwright, CI / optional local) | `npm run test:e2e` when `APP_URL` or `BASE_URL` points at a running app (`scripts/test/theme-e2e-prepare.sh` + `php artisan serve` for GitHub Actions). **Agents must not run Playwright for Herd or Cursor preview gates** — use PHPUnit contrast/manifest checks and the built-in browser preview only. Refresh snapshots with `npm run test:e2e:update` on a developer machine when theme pixels change. |
| CI workflows | `.github/workflows/test-cloudron.yml`, `milestone-version-check.yml`, `codeql.yml`, `dependency-review.yml` |

## Workflow slots

Shipping follows Cursor's `github-milestone-completion` and `ship-gate` skills and this repo's [`.cursor/rules/version-shipping.mdc`](.cursor/rules/version-shipping.mdc). Agents run [`complete-next-version`](.cursor/skills/complete-next-version/SKILL.md) and [`release-version`](.cursor/skills/release-version/SKILL.md), or `/complete-next-version`.

| Slot | Value |
| --- | --- |
| Milestone branch pattern | `milestone/vX-Y-Z-<slug>` (`composer milestone:next` / `milestone:branch`) |
| Milestone PR title | `v<version>: <milestone name>` |
| Commit / push cadence | Commit and push as and when needed on the milestone branch (small Conventional Commits with `Refs #<n>`); never batch pushes until the end |
| Mid-milestone sync | Cursor's `repo-sync` before each issue and before the ship gate (merge `origin/main`, push). `scripts\preview.ps1` may merge `main` locally without pushing — not a substitute |
| Version files | `VERSION`, `resources/data/module-runtime-contract.json` (`application_version`); canonical release history in `changelog/releases/vX.Y.Z.json` (author with `php artisan myapes:changelog-prepare`, which also regenerates the compatibility aggregate `resources/data/releases.json` — never hand-edit the aggregate); then `composer milestone:bump -- vX.Y.Z --check` |
| Release tooling | Push tag `vX.Y.Z`; `release.yml` publishes the GitHub Release |
| Area labels | only `area:admin`, `area:public`, `area:auth`, `area:tests`, `area:cloudron` |
| Issue title prefix | `[v0.N.x] NN` with a two-digit index (`00` = epic) |

Development is tracked only in GitHub issues, sub-issues and `vMAJOR.MINOR.PATCH` milestones — never Linear.

### Issues and milestones

- Live milestones: https://github.com/APESCIC/MyAPES.Account/milestones. Read the live titles before assigning work.
- Epic body keeps a checklist of sub-issues (`- [ ] #123 Short title`); each child is linked as a sub-issue.
- Assignee: `apescic-director`.
- An issue is done when its acceptance criteria are committed and pushed on the milestone branch; it closes when the milestone pull request merges (`Closes #<n>`), and the epic closes after the deploy.
- Milestones close automatically via `.github/workflows/milestone-close.yml` / `composer milestone:close-ready` after a successful Cloudron deploy (or when nothing shipped). Agents who find a milestone still open with all its issues closed run `composer milestone:close-ready` themselves, with no question; empty and unversioned milestones stay open.
- Before an issue is closed, tick every acceptance-criteria box in its body and the issue's line in the epic checklist.

### Shipping facts this rule relies on

- `composer milestone:next`, `milestone:bump`, `milestone:check-title`, `milestone:check-branch`, `milestone:check-release` and `milestone:close-ready` (`scripts/milestone-version`). CI runs the title and branch checks on every PR.
- Release links in per-release changelog JSON must start with `https://github.com/APESCIC/MyAPES-Account/` (hyphen). No credentials, personal data or exploitable detail in release notes.
- Dependabot opens grouped weekly pull requests into the long-lived `dependencies` branch, never `main`. Merge them there once green; merge `origin/dependencies` into the milestone branch when work starts, and `origin/main` into `dependencies` after each milestone ships.
- Security checks use only free GitHub features: Dependabot, dependency review and CodeQL (Actions and JavaScript/TypeScript; PHP is covered by PHPUnit and lint). Do not use Aikido.
- If the milestone's version is already taken on `main`, stop and report it to the owner. Don't pick another number or rename the milestone yourself.

## Deploy and health

| Item | Value |
| --- | --- |
| Target | Cloudron LAMP app on the APES Cloudron |
| Domain | <https://myaccount.myapes.me.uk> |
| Deploy command | `gh workflow run deploy-cloudron.yml --ref vX.Y.Z`, then `gh run watch <id> --exit-status` |
| Deploys | The published release tag only (the workflow refuses a tag without a published Release) |
| Auto-deploy couplings | None: pushing the tag only runs `release.yml`. Merging to `main` deploys nothing |
| Health URL | <https://myaccount.myapes.me.uk/healthz> |
| Version field | `version` (= `VERSION`) and `release` (the deployed commit) |
| Secrets live in | APES Vault; GitHub environment `cloudron-deploy` (`CLOUDRON_FQDN`, `CLOUDRON_TOKEN`); server-only keys in `/app/data/shared/.env` (key names only in docs). On live, the communications@ mailbox password is set primarily in **Admin → Settings → Keys and secrets**; `INBOUND_MAIL_IMAP_PASSWORD` in that `.env` is emergency/fallback only. Inventory: [docs/secrets-inventory.md](docs/secrets-inventory.md) |

Every Cloudron app is production. Read-only status/logs are fine; mutating or deploy actions follow the Cloudron skills and ship gate.

## Decisions and gotchas

- Theme decorative assets (critter cast SVGs plus Jungle `public/decor/jungle/*` only when Jungle is active) stay at or below 150 KB per representative workspace page; CI enforces this in `ThemeAssetBudgetTest`. Only the app-bar brand mark is preloaded.
- 2026-11-12: email import uses the Cloudron `communications@myapes.me.uk` mailbox over IMAP on `mail.cloudron.apes.org.uk:993` SSL/TLS (Cloudron has no IMAP on 143), polled every minute with backoff, and imported mail moves to `Processed` or `Failed` folders emptied after 30 days. Decided by Bambie (epic #770).
- 2026-11-14: inbound connection is built in (no Import form); on live the mailbox password is set primarily in Admin → Settings → Keys and secrets; `INBOUND_MAIL_IMAP_PASSWORD` in `/app/data/shared/.env` is emergency/fallback only. Local reply testing uses `INBOUND_MAIL_DRIVER=herd` with Mailpit as fallback; `tickets:inbound-loop-check` proves the loop. Decided by Bambie (epic #807; clarified Keys-primary on live in epic #826).
- 2026-11-14: Laravel Herd Pro is the only local development and testing stack (v0.63.1). Local mail goes to Herd's mail catcher; the Mailpit inbound driver stays for an optional standalone Mailpit when testing the reply loop. Decided by Bambie (epic #789).
- The milestone branch is always the one `composer milestone:next` prints, even when an issue body names a slightly different slug.
- 2026-11-24: mid-milestone sync and push cadence — run Cursor's `repo-sync` (merge `origin/main` and push) before each issue; commit and push as and when needed. `preview.ps1` merges `main` locally without pushing and is not a substitute. Decided by Bambie (epic #865 / plan for v0.77.0).
- With Herd's PHP, `composer install` regenerating the optimised autoloader can take several minutes; it is slow, not stuck. Right after it, the first `/healthz` can exceed `scripts\preview.ps1`'s 60 s timeout; run the gate again.
- Herd's own Vite process (`herd processes:start vite --site=myapes-account`) stopped with no output on 14 November 2026, so `scripts\preview.ps1` starts Vite itself with Herd's Node 22.
- With `core.autocrlf=true` on Windows, `vendor/bin/pint --test` reports `line_ending` for every file in the CRLF working tree. Check changed files from their committed LF content or rely on CI; never commit Pint's CRLF "fixes".
- Something outside the repo (likely Herd's dev-process badge) can delete `public/hot` while Vite keeps running; `scripts\preview.ps1` rewrites it when it finds this repo's Vite running.
- `herd services:list` can show the MySQL service as `stopped` while it answers on 3306; the scripts check the port instead.
- On this Windows ARM host, `Get-NetTCPConnection -State Listen` can miss Herd MySQL and Vite even while they answer on 3306/5175. `scripts\preview.ps1` falls back to a TCP connect. Use `. .\scripts\herd-env.ps1` before `npm ci`, and stop Vite first if `npm ci` hits `EPERM` on `*.node` files.
- 2026-11-17: repeatedly dot-sourcing `scripts\herd-env.ps1` (or running `preview.ps1`) used to prepend Herd paths without stripping old ones, growing `PATH` past ~8191 characters so Windows dropped `System32` / `herd` / `php` from the session even while nginx still served https://myapes-account.test. `herd-env.ps1` is now idempotent (strips managed Herd prefixes, then prepends once); `preview.ps1` sources it instead of prepending itself. If `herd` says "No usable PHP version found" or `php` vanishes mid-session, open a fresh shell and run `. .\scripts\herd-env.ps1` once.
- 2026-11-14: the local Herd site is served over HTTPS (Herd secure, Vite on https 5175 with Herd's certificate). Decided by Bambie.
- 2026-11-14: web push notifications milestone (v0.72.0) approved for GitHub-only tracking: optional browser alerts, per-device subscribe on Account → Privacy → Notifications, quiet hours, iPhone Home Screen guide, admin VAPID/pause/stats on Admin → Settings → Push notifications, `push` queue with metadata-only `push_deliveries` (no payloads, endpoints or keys in logs). Decided by Bambie (epic #791).
- 2026-11-22: browser push services (Google, Mozilla, Microsoft, Apple) relay encrypted messages and see the device address needed for delivery, not ticket or case content; lock-screen text uses notification type and record reference only. Wiki and `/privacy` wording for this is drafted in #806 and needs Bambie's approval before release.
- v0.72.0 release-notes facts (push): Account → Privacy → Notifications (types, quiet hours, devices, iOS Home Screen steps); soft prompt on dashboard; Admin → Settings → Push notifications (VAPID via Keys and secrets, pause all, count-only stats, operator test); production VAPID in Vault item **MyAPES Account – Web Push VAPID keys (production)** only; changing VAPID clears all subscriptions; `queue:work --queue=default,push` on Cloudron; optional local `npm run test:push-smoke` on Herd HTTPS only.
- v0.77.0: signed-in account management is four tabs — `/account`, `/account/profile`, `/account/security`, `/account/privacy`; old `/profile` URLs redirect. Wiki [[Account settings]] and in-app Help → Account settings; `docs/design-qa/ui-review-profile-*.png` captures are the pre-split UI.
- An https page with an http `public/hot` is unstyled (mixed content). Restart Vite after securing (`scripts\preview.ps1 -StopDevServer`, then `scripts\preview.ps1`).
- Switching the site between http and https leaves Cursor's browser replaying cached 301s; load URLs with a fresh query string, or send CDP `Network.setCacheDisabled`.
- Release order: run `php artisan myapes:changelog-prepare --type=<patch|minor|major>` first (writes `changelog/releases/vX.Y.Z.json` and regenerates `resources/data/releases.json`), fill every `TODO:` in the new per-release file, then `composer milestone:bump -- vX.Y.Z --check`. Update version-pinned tests (including `array_slice` in `ReleaseHistoryCommandTest`) by hand. The "SQLite test and package" check stays red until that release commit lands.
- Change log hub: there is no Admin release editor. Author in git only (`changelog/releases/`). Production serves `/change-log` from the `releases` table (`MYAPES_CHANGELOG_USE_DATABASE=true`); `scripts/deploy/activate-release.sh` runs `php artisan myapes:releases-sync` before traffic returns. Local Herd may keep the env flag false and read files until you sync. APES Newsroom is the interaction reference for hub layout and behaviour only, not a second editor.
- Full suite needs more than the CLI's default 128 MB: `php -d memory_limit=-1 vendor/bin/phpunit` for a local full run.
- CI runs `php artisan lang:check --fail-on-missing`; build dynamic keys with interpolation, not concatenation.
- `gh` with `--jq` in Windows PowerShell 5.1 loses inner double quotes; pipe `--json` to `ConvertFrom-Json` instead.

## Where to find things

- Wiki: https://github.com/APESCIC/MyAPES.Account/wiki. Source [`docs/wiki`](docs/wiki), published by `.github/workflows/wiki-sync.yml` on merge to `main`. Edit through pull requests only.
- Discussions: https://github.com/APESCIC/MyAPES.Account/discussions, with forms in `.github/DISCUSSION_TEMPLATE`.
- Change log: `/change-log` in the app; sources in `changelog/releases/vX.Y.Z.json` (see `changelog/releases/README.md`). Aggregate `resources/data/releases.json` is generated for compatibility and GitHub release notes tooling.
- Security: [SECURITY.md](SECURITY.md). Conduct: [CODE_OF_CONDUCT.md](CODE_OF_CONDUCT.md).
- Developer guide: [docs/developer-guide.md](docs/developer-guide.md).
- API, webhooks and integrations (v0.62.0): [docs/api/openapi-v1.yaml](docs/api/openapi-v1.yaml), and the wiki pages [Integrations](docs/wiki/Integrations.md), [API clients](docs/wiki/API-clients.md) and [Webhooks](docs/wiki/Webhooks.md). Change `routes/api.php` and the spec together (`OpenApiSpecTest` checks them). Real keys, tokens and signing secrets live only in APES Vault ('API Keys & Tokens') and in Admin → Settings.
