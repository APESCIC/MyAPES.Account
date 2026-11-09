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
| Profile | [docs/laragon/profile.md](docs/laragon/profile.md): PHP 8.4 NTS through FastCGI (mod_fcgid), MySQL 8.4 (`myapes_account_local`, root, empty password), Node 22, npm 11.10+, Vite port 5175 |
| Setup | `composer setup` (runs `scripts/laragon-setup.ps1`; safe to run twice) |
| Live preview gate | Before any development work: `powershell -NoProfile -ExecutionPolicy Bypass -File scripts\preview.ps1`, then open <http://myapes-account.test> in Cursor's browser (see [`.cursor/rules/laragon-local-dev.mdc`](.cursor/rules/laragon-local-dev.mdc)) |
| Daily loop | Laragon started (**Start All**), then `composer run dev` for Vite hot reload |
| Demo data | `composer demo:seed` to load, `composer demo:reset` to wipe and reload; logins in [docs/laragon/demo-data.md](docs/laragon/demo-data.md) |
| Env file | `.env.example` (Laragon values) |

- The agent shell's `php` and `node` may not be Laragon's. Run `scripts/laragon-setup.ps1`, or put the NTS build `C:\laragon\bin\php\php-8.4.*-nts-*` (the one Apache runs through FastCGI), `C:\laragon\bin\mysql\mysql-8.4.*\bin` and `C:\laragon\bin\nodejs\node-v22` first on `PATH`.
- Laragon's settings (PHP version, SSL, restarts) and the hosts file belong to the owner: ask before changing them, and never touch other projects' folders, databases or vhosts.
- Demo data refuses to run unless `APP_ENV=local`. Never point it at production or Cloudron, and never copy real data into it.
- Local QA accounts come from `database/seeders/LocalQaSeeder.php` (local and testing only) and drive the in-app QA role switcher. Never copy their passwords anywhere.
- Cursor cloud and Linux agents have no Laragon. Their agent-only path is `bash scripts/local/bootstrap.sh --fresh` and `bash scripts/local/dev.sh`, with `http://127.0.0.1:8000/` for UI checks. It is not a developer setup.
- Full local check: `composer pre-pr-verify` (PowerShell) or `bash scripts/local/pre-pr-verify.sh` (agent-only).
- In Windows PowerShell 5.1, pass `gh` bodies with `--body-file`, because inline double quotes get stripped.
- Node/npm for Vite builds: use **npm 11.10 or newer** (Node 22 ships npm 10 by default — upgrade with `npm install -g npm@11` before `npm ci`). Repo `.npmrc` sets `min-release-age=7`.
- Every GitHub Actions `uses:` must be pinned to a full 40-character commit SHA with a version comment (`action@<sha> # vX.Y.Z`). Mutable tags such as `@v2` are not allowed.

## Issues and milestones

- Live milestones: https://github.com/APESCIC/MyAPES.Account/milestones. Read the live titles before assigning work.
- Title prefix `[v0.N.x] NN` with a two-digit index. `00` is the epic; its body keeps a checklist of sub-issues (`- [ ] #123 Short title`), and each child is linked as a sub-issue.
- Labels: only `area:admin`, `area:public`, `area:auth`, `area:tests`, `area:cloudron`.
- Assignee: `apescic-director`.
- Comment on each issue as work goes: the branch, the commits and progress. An issue is done when its acceptance criteria are committed on the milestone branch; it closes when the milestone pull request merges (`Closes #<n>`), and the epic closes after the deploy.

## Shipping and versioning

Work ships once per milestone, by the milestone's version, never by GitHub's milestone number: every issue committed on one `milestone/vX-Y-Z-<slug>` branch behind one draft pull request, **one merge commit** into `main`, one `vX.Y.Z` tag and GitHub Release, and one Cloudron deploy of that tag. The single rule is [`.cursor/rules/version-shipping.mdc`](.cursor/rules/version-shipping.mdc): the next version, the names, the version fields and release record, the flow and hotfixes. Agents run it with the [`complete-next-version`](.cursor/skills/complete-next-version/SKILL.md) and [`release-version`](.cursor/skills/release-version/SKILL.md) skills, or `/complete-next-version`. Before any work, run the live preview gate in [`.cursor/rules/laragon-local-dev.mdc`](.cursor/rules/laragon-local-dev.mdc).

Facts the rule relies on:

- `composer milestone:next`, `milestone:bump`, `milestone:check-title`, `milestone:check-branch` and `milestone:check-release` (`scripts/milestone-version`). CI runs the title and branch checks on every PR (`.github/workflows/milestone-version-check.yml`). Optional local check before each push: `git config core.hooksPath .githooks`.
- Release links in `releases.json` must start with `https://github.com/APESCIC/MyAPES-Account/` (hyphen); `myapes:changelog-validate` rejects other forms. No credentials, personal data or exploitable detail in release notes.
- `release.yml` publishes the GitHub Release when the tag is pushed. `deploy-cloudron.yml` runs only on a published release tag (`gh workflow run deploy-cloudron.yml --ref vX.Y.Z`); it backs up, uploads, activates, verifies `/healthz` and the OIDC redirect, and rolls back on failure. Its secrets (`CLOUDRON_FQDN`, `CLOUDRON_TOKEN`) live in the `cloudron-deploy` environment. If a run needs a secret, a permission or an approval, stop and tell the owner.
- Dependabot opens grouped weekly pull requests into the long-lived `dependencies` branch, never `main`. Merge them there once green; merge `origin/dependencies` into the milestone branch when work starts, and `origin/main` into `dependencies` after each milestone ships.
- Security checks use only free GitHub features: Dependabot, dependency review and CodeQL (Actions and JavaScript/TypeScript; CodeQL has no PHP support, so PHP is covered by PHPUnit and the lint checks).
- If the milestone's version is already taken on `main`, stop and report it to the owner. Don't pick another number or rename the milestone yourself.
## Where to find things

- Wiki: https://github.com/APESCIC/MyAPES.Account/wiki. The source is [`docs/wiki`](docs/wiki), published by `.github/workflows/wiki-sync.yml` on merge to `main`. Edit through pull requests only, because direct wiki edits are overwritten.
- Discussions: https://github.com/APESCIC/MyAPES.Account/discussions, with forms in `.github/DISCUSSION_TEMPLATE`.
- Change log: `/change-log` in the app, and `resources/data/releases.json`.
- Security: [SECURITY.md](SECURITY.md). Conduct: [CODE_OF_CONDUCT.md](CODE_OF_CONDUCT.md).
- Developer guide: [docs/developer-guide.md](docs/developer-guide.md).
