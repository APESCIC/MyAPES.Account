# Agent instructions for MyAPES Account

This file is the source of truth for Cursor agents, both cloud and the local IDE. Files in `.cursor/rules/` stay short and point here. Command detail lives in `.cursor/skills/release-and-deploy/SKILL.md` and `.cursor/skills/release-metadata/SKILL.md`. If a rule, skill, or issue template disagrees with this file, follow this file and correct the other file in the same change.

The owner is Bambie Murphy. App wording and docs use UK English.

## Language and model names

- Write UK English (organisation, behaviour, colour) in the product and in docs.
- Recruitment vacancies use `RecruitmentRole`. That model is not Spatie's `Role` and not an Access job role.
- Layers are **Core > Modules > Plugins**. See [ADR 0001](docs/adr/0001-core-modules-plugins.md) and [docs/architecture.md](docs/architecture.md). Core never depends on a Module or a Plugin. Add packages with `php artisan make:module` and `php artisan make:plugin`. Do not edit Core to register them.

## Local development

The owner's working copy is Laragon on Windows.

| Item | Value |
| --- | --- |
| Clone | `C:\laragon\www\myapes-account` |
| App URL | `http://myapes-account.test` |
| Document root | `{repo}\public` |
| Daily loop | Start Laragon (Apache), then `composer run dev:laragon` |
| First bootstrap | `powershell -ExecutionPolicy Bypass -File .\scripts\local\bootstrap.ps1 -Laragon -Fresh` |
| Env file | `.env.laragon.example` (the `-Laragon` switch copies it) |
| Extra seed | `bootstrap.ps1 -Laragon -Seed` when the database should keep existing rows |

Pass `-AppUrl` when the virtual host is not `myapes-account.test`. Vite HMR listens on `http://127.0.0.1:5173/`. Passkeys need a secure context, so prefer `https://myapes-account.test` with a trusted local certificate. Live Cloudron is `https://myaccount.myapes.me.uk`.

Local QA accounts are the fixtures in `database/seeders/LocalQaSeeder.php` (`emails()`, `DEFAULT_PASSWORD`). The seeder runs only when `APP_ENV` is `local` or `testing`. The README lists the same email addresses. Do not copy passwords, tokens, or the production walkthrough secret into issues, pull requests, release notes, or new docs. The production walkthrough password is in the operator secret store, not this repository.

Cloud and Linux agents have no Laragon. For a UI change, reuse a running server or start `bash scripts/local/dev.sh` and open `http://127.0.0.1:8000/` (`APP_PORT` overrides the port). Skip the preview when the change is not UI.

`composer pre-pr-verify` and `composer pre-merge` invoke PowerShell (`scripts/local/pre-pr-verify.ps1`, `scripts/local/pre-merge.ps1`). On Linux without PowerShell, run the steps from `pre-merge.ps1` yourself: `git fetch origin`, `php artisan myapes:changelog-validate --base-ref=origin/main`, the PHPUnit files named in that script, `npm run test:frontend`, and `npm run build`. This repository has no second composer script for that path.

## Reports

End every report or question with next options and one recommended option:

```markdown
## Next actions
**Recommended:** <one best option>

1. ...
2. ...
```

That block tells the owner how to steer the next piece of work. It does not pause the definition of done, unless a step needs a secret or a person.

## Starting work

Search this repository for an open issue that already covers the task. Never open an issue in another repository.

1. **Milestone.** One milestone per minor line, titled `v{major}.{minor}.{patch} Beta`. A closed milestone shows the last version shipped on that line. A new minor line, while open, shows the projected final patch: `0.N.(non-epic issues − 1)`, because the first story ships `0.N.0`. A patch on a line that has already shipped stays on that same milestone: reopen it if it is closed and retitle it to the version this patch will ship. Read live titles before trusting the catalogue below:

   ```bash
   gh api 'repos/APESCIC/MyAPES-Account/milestones?state=open&per_page=20' --jq '.[] | {number,title,open_issues}'
   ```

2. **Epic and sub-issues.** Title prefix `[v0.N.x] NN` with a two-digit index. `00` is the epic. The epic body keeps a checklist of the children (`- [ ] #123 Short title`). Link each child as a sub-issue (`parentIssueId` on `createIssue`, or `addSubIssue`). Tick the checklist as children finish, and comment when the branch, pull request, CI, or a blocker changes.

3. **Assignee.** `apescic-director`. That is the account on current issues. `bmurphy-apescic` is the repository administration account named in the README and was the old template default. Do not assign new work to `bmurphy-apescic` unless that person is named as the owner of the task.

4. **Labels.** `area:` only: `area:auth`, `area:admin`, `area:public`, `area:cloudron`, `area:tests`. Human templates may still apply `bug` or `enhancement`. Add the `area:` label for the surface you are changing. Do not add `type:` or `priority:` labels.

5. **Issue Type, Priority, Effort, dates.** Types are `Task`, `Bug`, and `Feature`. Priority is `Urgent`, `High`, `Medium`, or `Low`. Effort is `High`, `Medium`, or `Low`. Start date and Target date are set when they are known. Set them with the GitHub API on create (`issueTypeId`, `issueFields`) or update (`issueFieldUpdates`) when the token allows. If the call returns 403, or the issue comes back with those fields empty, write the intended values in the issue body and in the report. A person sets them in the GitHub sidebar. Do not keep creating issues after an error: list the latest issues first, so a failed assignee call does not leave a duplicate.

6. **Branch and pull request.** Branch from `main` as `cursor/{feature|fix|chore}/<issue-number>-<short-slug>`. Never commit on `main`. If the agent session requires another prefix, keep the issue number in the branch name and say so on the issue. The pull request body uses `Closes #<n>` for each issue that pull request finishes.

7. **In progress.** Comment on the epic and the leaf issue with the branch name. This repository has no in-progress label. The open pull request plus that comment is the in-progress mark.

## Definition of done

A change is done when every item below is true. Do them in order. Do not stop between them for permission. Stop when CI is red (fix it), when a secret or a person is required (name the step and the exact command), or when the owner says stop.

1. The slice is on the working branch. Focused tests for the change have been run, or the report says there is no behaviour change to test.
2. The epic checklist, sub-issue links, and a progress comment match the code.
3. Release metadata is in this same pull request. `php artisan myapes:changelog-validate --base-ref=origin/main` passes. The new head record in `resources/data/releases.json` has no `TODO:`.
4. The pull request is open, **Test MyAPES Core** is green, and the pull request is merged to `main`.
5. Cloudron deploy has been dispatched for that `main` commit and has succeeded, or dispatch could not run and the exact command is in the report and on the shipping issue.
6. Live `https://myaccount.myapes.me.uk/healthz` returns `"status": "ok"`, `version` equal to `VERSION`, and `release` equal to the deployed commit. `/change-log` shows the new head record.
7. Leaf issues that the pull request finished are closed. The epic is closed when every child is done. The milestone is closed, titled with the last shipped `v0.N.P Beta`, when it has no open issues.
8. The report ends with Next actions and one recommended option.

## Completing work

The owner has ordered agents to finish the slice: commit, open the pull request, get CI green, merge to `main`, ship the version, deploy, and verify.

### Release metadata, before merge, same pull request

`php artisan myapes:changelog-prepare` refuses to run without `--pr`. Open the pull request, then:

```bash
php artisan myapes:changelog-prepare --type=patch --title="Short public title" --issue=<n> --pr=<n>
```

Use `--type=minor` for a backward-compatible capability and `--type=patch` for a compatible fix. While the app is pre-1.0, document a breaking change and bump **minor**.

Replace every `TODO:` in the new head of `resources/data/releases.json`. Public notes stay free of credentials, personal data, private operational identifiers, exploitable security detail, and unnecessary infrastructure detail.

The command writes:

- `VERSION`
- `resources/data/releases.json` (one new head record; do not edit or reorder published records)
- `resources/data/module-runtime-contract.json` key `application_version`
- The version-pinned tests listed in `app/Support/ReleaseHistoryPreparer.php`

The prepare command rewrites version strings and `$releases[N]['date']` indexes. It does not bump a tail `array_slice($releases, N)` in `tests/Feature/ReleaseHistoryCommandTest.php`, and it does not rewrite assertions that name the current release's title, issue, or pull request. Update those to the new head before validating.

Validate:

```bash
git fetch origin
php artisan myapes:changelog-validate --base-ref=origin/main
```

On the owner's PC, `composer pre-pr-verify` is the full local check and `composer pre-merge` is the contract gate. On Linux, use the `pre-merge.ps1` steps under Local development.

`release-commit-map.json` is generated by `node scripts/local/map-release-commits.mjs` for backfill. A normal release does not edit it.

`App\Console\Commands\ValidateChangeLog` is the `myapes:changelog-validate` command. CI runs it from `.github/workflows/test-cloudron.yml`.

### Merge

```bash
gh pr checks --watch --fail-fast
gh pr merge --merge --delete-branch
```

Use a merge commit. Squash only when the owner asks. Confirm the commit is on `origin/main` before deploy. **Test MyAPES Core** (`.github/workflows/test-cloudron.yml`) also runs on push to `main`.

### GitHub Release and Cloudron deploy

Deploy is a manual `workflow_dispatch` of **Deploy MyAPES Core to Cloudron** (`.github/workflows/deploy-cloudron.yml`). A green `main` does not deploy by itself.

```bash
version=$(git show origin/main:VERSION | tr -d '\r\n')
gh workflow run "Deploy MyAPES Core to Cloudron" --ref main -f app_version="$version"
gh run list --workflow "Deploy MyAPES Core to Cloudron" --limit 1
gh run watch <run-id>
```

The workflow checks out that commit, builds, runs `scripts/deploy/package-release.sh`, and activates the archive on `https://myaccount.myapes.me.uk`. Activation uses `scripts/deploy/cloudron-run.sh`, `scripts/deploy/cloudron-app.conf`, `scripts/deploy/activate-release.sh`, and `scripts/deploy/rollback-release.sh`. Agents do not run those scripts locally and do not install a Cloudron CLI. This repository has no local deploy command.

After a successful deploy, the **Publish GitHub Release** job runs `scripts/deploy/github-release-notes.sh` against `resources/data/releases.json` and creates:

- tag `v{VERSION}`
- release name `{VERSION} Beta` (for example `0.39.11 Beta`)
- target commit equal to the deployed SHA

Do not create that GitHub Release by hand before deploy. The publish job fails if the tag already points at a different commit. Create it by hand only to recover a failed publish job, with the same notes script and the deployed SHA.

`scripts/github/rename-release-titles.sh` and `scripts/github/migrate-beta-milestones.sh` are maintenance helpers for titles that have already shipped. They are not part of a normal deploy.

### Secrets and human steps

- `CLOUDRON_FQDN` and `CLOUDRON_TOKEN` are GitHub Actions secrets on the `cloudron-deploy` environment. They are not in this repository. The workflow reads them. If dispatch or the job fails because a secret or permission is missing, stop. Leave the shipping issue open and put the `gh workflow run` command in the report.
- When this file was written, `cloudron-deploy` had no required reviewers. If GitHub asks for approval, a person approves the run. Say so and stop.
- Assignee, labels, milestone, Issue Type, Priority, Effort, and dates need a token that can update issues. A 403 means a person sets them in the GitHub UI. Put the intended values in the issue body.
- Closing or renaming a milestone (`gh api -X PATCH repos/APESCIC/MyAPES-Account/milestones/<number>`) needs that same permission. A 403 is a human step, not a reason to invent a second milestone.

### Verify live

```bash
curl --fail --silent --show-error https://myaccount.myapes.me.uk/healthz
gh release view "v$version" --json tagName,name,targetCommitish,url
```

`healthz` JSON has `status`, `version`, `release` (the deployed SHA, or `development` when no `REVISION` file is present), `maintenance`, and `checks`. Confirm `/change-log` on that host matches the head record. Sibling website changelogs are out of scope.

### Close the book

`Closes #<n>` on the merged pull request closes the issues that pull request finished. Close the epic when every child is done, with `state_reason` `completed`. Close the milestone when it has no open issues, titled with the last shipped version.

## No Aikido

This project does not use Aikido. Do not call Aikido tools, follow Aikido skills, or ask anyone to sign in to Aikido. When someone asks for a security review, use PHPUnit and the `review-security` skill.

## Product history

Shipped lines and the open backlog are listed here. Confirm open milestone titles on GitHub before assigning new work. The operating procedure above wins if this catalogue is stale.

## Implement order

`main` holds the merged stack through **v0.39.x** (Account security). How to start, ship, and deploy is the procedure at the top of this file, not this history. **`v0.34.4 Beta`** through **`v0.38.9 Beta`** are **closed**. See [docs/architecture.md](docs/architecture.md) and [docs/developer-guide.md](docs/developer-guide.md).

Do not reopen Access/RBAC or password-pack feature PRs for work already on `main` (#97–#99, #142, #121, #147, #148, #122, #133). Do not reopen Account security feature PRs for work already on `main` (#225–#234).

## GitHub milestones

Use one milestone per **minor line**, titled with a **full version** and a **Beta** suffix until the product exits beta:

- Format: `v{major}.{minor}.{patch} Beta` (for example `v0.32.14 Beta`)
- **Closed** milestones carry the **last version shipped** on that line (from `resources/data/releases.json`)
- **Open** milestones carry the **projected final version**: `0.N.(non-epic issues − 1)` — the first story ships `0.N.0` and each further story ships the next patch. Rename the open milestone whenever its scope changes, and rename it to the real last shipped version when it closes
- **Patch** releases (`0.32.1`, `0.32.2`, …) stay on the same minor-line milestone; a patch outside the plan (for example ops hygiene) bumps the projected title
- **Minor** bump (`0.32.x` → `0.33.0`): close the completed milestone (renamed to its last shipped version) when its issues are done; assign new work to the next minor line
- Closed historical lines: `v0.1.0 Beta` through `v0.30.0 Beta` (archaeology milestones, one per minor line)
- **Completed product lines:** `v0.31.12`, `v0.32.14`, `v0.33.7`, `v0.34.4`, `v0.35.1`, `v0.36.2`, `v0.37.11`, `v0.38.9`, `v0.39.10 Beta` (Account security plus nav cleanup #331 and milestone titles #377). A later patch on this line reopens that milestone and retitles it to the version just shipped.
- **Active backlog (open on GitHub; re-check before use):** `v0.40.7 Beta` Card spacing and page polish → `v0.41.7 Beta` Unified Recruitment menu → `v0.42.11 Beta` Email templates and outbound mail → `v0.43.10 Beta` Language and keywords expansion → `v0.44.0 Beta` Staff app redesign (horizontal mega menu and logo palette, epic #382). `v0.44.0 Beta` is titled with the line's opening version; re-project it to the final patch when that line's scope is firm.
- **Ship order (complete):** `v0.35` Admin shell → `v0.36` Recruitment IA → `v0.37` Structure → `v0.38` Language → `v0.39` Account security (milestones **7 → 9 → 8 → 5**)

Planning lists below still describe feature order; map issues to the minor-line milestone above.

### v1.0.0 Beta (`v0.31.12 Beta`, closed) — complete on live v0.31.2

1. #91 RBAC epic — closed; code on `main` via #120 / #156 / #157
2. #97 Access admin UX — merged (#120)
3. #98 PHPUnit auth matrix — merged (#156)
4. #99 Public vs Cloudron separation — merged (#157)
5. #158 Local public password pack — closed (children #142, #121, #147, #148, #159)
6. #142 Admin reset of local public password — merged (#160)
7. #121 Public local forgot-password — merged (#161)
8. #147 Signed-in local change-password — merged (#162)
9. #148 Read-only profile email — merged (#163)
10. #159 Live-verify on production — closed completed on live v0.31.2
11. #122 Changelog Internal-only leak — merged (#164)
12. #133 Stale `/superadmin/*` 404s — merged (#165)

### v1.1.0 Beta: Public UX & compliance (`v0.32.14 Beta`, closed)

1. #128 Branded 404 and 403
2. #130 Signed-in home still shows login doors
3. #131 Spike dock covers fields
4. #132 UK dd/mm/yyyy dates
5. #149 CIC subcategory default
6. #152 Hide Urgent from public tickets
7. #150 Public ticket Activity
8. #151 Public pet owner/date/ID
9. #153 Public save flash
10. #123 Privacy/cookies/help/terms
11. #124 Register consent timestamp
12. #125 Core vs Account naming
13. #126 SECURITY.md
14. #127 Compress Spike/logo PNGs

### v1.2.0 Beta: Staff UX (`v0.33.7 Beta`, closed)

1. #136 Hub Create/View URLs (incl. `/shelter/pet-profiles`)
2. #143 Empty pet dropdown on create forms
3. #134 Staff empty states
4. #135 List above create form
5. #141 Duplicate Admin/Super Admin KPI cards
6. #137 Raw permission dump
7. #138 Staff Login / Cloudron chase (never the public reset on #121)
8. #139 Blocked vs INCOMPATIBLE copy
9. #144 Suspend user confirm
10. #140 Group member counts clickable
11. #145 Groups last-sync as-of

### v1.3.0 Beta: Recruitment (`v0.34.4 Beta`)

Ship only on `apes-cic`. Model name is `RecruitmentRole` (never Spatie `Role`).

1. #211 Epic: Recruitment plugin — public roles board
2. #212 Module scaffold + registration
3. #213 Role model + staff admin CRUD
4. #214 Public roles listing (staff / volunteer / student)
5. #215 Public role detail page
6. #217 Public nav / discovery links
7. #216 PHPUnit coverage + sample seed data
8. #218 Epic: Recruitment on APES CIC — public & staff manage
9. #219 Register recruitment on APES CIC sub-core
10. #220 Staff APES CIC hub — manage roles
11. #221 Application model + status workflow
12. #222 Public apply + manage own applications
13. #223 Staff review applications
14. #224 PHPUnit — APES CIC recruitment manage paths

### v1.4.0 Beta: Unified Admin shell + plugin settings (`v0.35.1 Beta`, closed)

Ship order vs neighbors: **v0.35 → v0.36 → v0.37 Structure → v0.38 Language → v0.39 Account security**. One primary Admin nav with a permission-gated page submenu; never weaken `admin.*` / `superadmin.access` action middleware. Legacy `/superadmin` redirects into Admin.

#### Plugin settings registry contract

Single source of truth: plugin package manifests (`App\Core\Extensions\Plugins\PluginManifest` + `PluginSettingsSchema`) collected by `PluginRegistry`. `App\Services\ModuleSettingsRegistry` adapts those manifests into `App\Modules\ModuleSettingsDescriptor` for Admin Plugins pages (same URLs, permissions, and stored values as v0.35).

| Field | Purpose |
| --- | --- |
| `supportsSettings` | Whether Admin → Plugins deep-links to a settings page |
| `schema` | Settings UI/validation shape (`websites_categories`, `recruitment_board`, or `none`) |
| `settingsRouteName` | Named route for the settings editor (default `admin.modules.settings.edit`) |
| `viewPermission` / `managePermission` | Gates for viewing and saving (default `admin.modules.view` / `admin.modules.manage`) |
| `navLabel` | Plugins-index link label (default `Settings`) |
| `searchKeywordsKey` | Lang key for Admin search synonyms (default `{ns}::plugin.keywords`); resolved via `PluginManifest::keywords()` |

Tickets and cases on `apes-cic` remain the `websites_categories` reference. Recruitment uses `recruitment_board` (public board / apply toggles). Consultations and Pet Profiles declare `supportsSettings = false` and show **No configurable settings** on the Plugins index. Do not hardcode module keys in Blade; consume the registry via `ModuleAdministrationCatalogue` / `ModuleSettingsService`.

1. #246 Epic: Unified Admin shell (merge Super Admin)
2. #251 Permission-gated Admin page submenu
3. #250 Collapse primary nav Admin/Super Admin → Admin
4. #252 Legacy `/superadmin` redirects
5. #247 Epic: Per-plugin settings pages
6. #253 Plugin settings registry contract
7. #255 Recruitment (+ remaining plugins) settings pages
8. #254 PHPUnit Admin shell + plugin settings matrix

### v1.5.0 Beta: Recruitment dual-frontend IA (`v0.36.2 Beta`)

Ship order vs neighbors: **v0.35 → v0.36 → v0.37 Structure → v0.38 Language → v0.39 Account security**. Ship only on `apes-cic`. Model name is `RecruitmentRole` (never Spatie `Role`). Copy uses openings / **Open roles**, not Access “Roles”.

Public URLs stay stable (`/recruitment`, `/recruitment/{id}`, `/recruitment/applications*`). Staff manage URLs stay under `/apes-cic/recruitment*`. Respect 0.35.1 Recruitment settings toggles (public board / apply) for the public frontend only — staff manage chrome uses module enabled state, not the public board toggle.

**Public shell:** one **Recruitment** primary + page submenu **Open roles** | **My applications** (signed-in).

**Staff manage shell:** independent primary **Recruit manage** (product-shortened) for users with any staff `apes-cic.recruitment.*` ability (`view-all`, `create`, `update`, `delete`, `review-applications` — not `view-own` alone). Page submenu **Roles** | **Applications**. Parent menu shows if any child is allowed; controllers stay fail-closed. HR with `review-applications` only sees Applications (Roles actions 403). APES CIC hub Recruitment links remain. Distinct from public Recruitment / My applications.

1. #248 Epic: Public Recruitment frontend IA
2. #260 Public primary menu → Recruitment with submenu
3. #256 Open roles / openings page
4. #257 My applications track page
5. #249 Epic: Staff Recruitment manage frontend
6. #258 Staff Recruitment manage menu + roles CRUD IA
7. #259 Staff applications review IA + HR permissions
8. #261 PHPUnit recruitment dual-frontend IA matrix

### v1.6.0 Beta: Structure — Core > Modules > Plugins (`v0.37.11 Beta`, closed)

Shipped after Recruitment IA. Docs: [docs/architecture.md](docs/architecture.md), [ADR 0001](docs/adr/0001-core-modules-plugins.md), [docs/architecture-inventory.md](docs/architecture-inventory.md), [docs/developer-guide.md](docs/developer-guide.md).

- **Core** — sign-in/auth, accounts, profiles, access, notifications, settings, Admin shell, maintenance, audit, health, change log, shared attachments. Core never depends on a Module or Plugin.
- **Module** — organisation area packages under `modules/<slug>`: APES CIC, Pet Care Clinic, Shelter and Rescue.
- **Plugin** — reusable feature packages under `plugins/<slug>`: Tickets, Cases, Recruitment, Consultations, Pet Profiles. Recruitment uses `RecruitmentRole` (never Spatie `Role`).
- Architecture tests **hard-fail** in CI (#294). Generators: `php artisan make:module` / `make:plugin` (#295).

1. #278 Epic — closed with Wave 8
2. #279 ADR + `docs/architecture.md`
3. #280 Inventory
4. #281 Folder, namespace, and autoload layout
5. #282–#295 contracts, enablement, modules, plugin moves, arch hard-fail, generators

### v1.7.0 Beta: Language & keywords (`v0.38.9 Beta`, closed)

Shipped after Structure. Docs: [docs/localisation.md](docs/localisation.md), [docs/glossary.md](docs/glossary.md).

- **en_GB** primary locale with `en` fallback; glossary + string extraction across public / Admin / APES CIC / auth
- Plugin translation namespaces; public SEO + Admin search keywords
- User locale preference groundwork (`users.locale`, `SetLocale`, hidden switcher); CI `lang:check --fail-on-missing`

1. #264 Epic — closed with Wave 4
2. #265–#276 baseline, glossary, inventory, namespaces, extraction, SEO/keywords, locale + CI hard-fail

### v1.8.0 Beta: Account security (`v0.39.10 Beta`, closed)

Shipped after Language. Auth emails, step-up, password/username/verify hardening, secure email change, custom TOTP + recovery, passkeys (`laravel/passkeys`), PHPUnit account-security matrix. No Fortify. Local password MFA only; Cloudron OIDC doors unchanged.

1. #225 Epic — closed with Wave 7 (#234)
2. #226 Auth email brand kit
3. #233 Step-up (`password.confirm` / passkey confirm)
4. #230 Password policy (min 12 + HIBP)
5. #227 / #229 Username + registration/verify
6. #228 Secure email change
7. #231 TOTP 2FA + recovery codes
8. #232 Passkeys via `laravel/passkeys`
9. #234 PHPUnit account-security matrix

===

<laravel-boost-guidelines>
=== foundation rules ===

# Laravel Boost Guidelines

The Laravel Boost guidelines are specifically curated by Laravel maintainers for this application. These guidelines should be followed closely to ensure the best experience when building Laravel applications.

## Foundational Context

This application is a Laravel application running on PHP 8.4. You are an expert with the Laravel ecosystem. Always use the APIs that match the installed major version of each package — do not assume a version.

Before relying on a package's API, confirm its installed version:
- PHP packages: run `composer show --direct` to list direct dependencies with versions, or `composer show <vendor/package>` for a single package.
- JS packages: check `package.json` for the installed versions.

## Skills Activation

This project has domain-specific skills available in `**/skills/**`. You MUST activate the relevant skill whenever you work in that domain—don't wait until you're stuck.

## Conventions

- You must follow all existing code conventions used in this application. When creating or editing a file, check sibling files for the correct structure, approach, and naming.
- Use descriptive names for variables and methods. For example, `isRegisteredForDiscounts`, not `discount()`.
- Check for existing components to reuse before writing a new one.

## Verification Scripts

- Do not create verification scripts or tinker when tests cover that functionality and prove they work. Unit and feature tests are more important.

## Application Structure & Architecture

- Stick to the existing directory structure. Layer roots: `app/Core`, `modules/<slug>`, `plugins/<slug>` ([ADR 0001](docs/adr/0001-core-modules-plugins.md), [docs/architecture.md](docs/architecture.md)). Add packages with `php artisan make:module` / `make:plugin` ([docs/developer-guide.md](docs/developer-guide.md)) — do not edit Core to register them.
- Do not change the application's dependencies without approval.

## Frontend Bundling

- If the user doesn't see a frontend change reflected in the UI, it could mean they need to run `npm run build`, `npm run dev`, or `composer run dev`. Ask them.

## Documentation Files

- You must only create documentation files if explicitly requested by the user.

## Replies

- Be concise in your explanations - focus on what's important rather than explaining obvious details.

=== boost rules ===

# Laravel Boost

## Project Rules

- This project contains committed, area-grouped rules in `.ai/rules` when that directory exists (settled decisions, non-obvious traps, standing constraints). Framework and package guidelines that only apply to specific paths (testing, frontend, components) also live there, under `.ai/rules/boost` — this is not just recorded decisions, it is load-bearing guidance you have not seen inline. Before you enter plan mode or create/edit any file, you MUST first: open @.ai/rules/index.md (it maps file globs to rule files), read every rule file whose globs cover the path(s) in scope, and run `grep -rin 'keyword' .ai/rules` to catch what a path match alone misses. Do not write code until you have read and are following every matching rule. If `.ai/rules` does not exist, continue without it.

## Artisan

- Run Artisan commands directly via the command line (e.g., `php artisan route:list`). Use `php artisan list` to discover available commands and `php artisan [command] --help` to check parameters.
- Inspect routes with `php artisan route:list`. Filter with: `--method=GET`, `--name=users`, `--path=api`, `--except-vendor`, `--only-vendor`.
- Read configuration values using dot notation: `php artisan config:show app.name`, `php artisan config:show database.default`. Or read config files directly from the `config/` directory.

## Tinker

- Execute PHP in app context for debugging and testing code. Do not create models without user approval, prefer tests with factories instead. Prefer existing Artisan commands over custom tinker code.
- Always use single quotes to prevent shell expansion: `php artisan tinker --execute 'Your::code();'`
  - Double quotes for PHP strings inside: `php artisan tinker --execute 'User::where("active", true)->count();'`

=== php rules ===

# PHP

- Always use curly braces for control structures, even for single-line bodies.
- Use PHP 8 constructor property promotion: `public function __construct(public GitHub $github) { }`. Do not leave empty zero-parameter `__construct()` methods unless the constructor is private.
- Use explicit return type declarations and type hints for all method parameters: `function isAccessible(User $user, ?string $path = null): bool`
- Use TitleCase for Enum keys: `FavoritePerson`, `BestLake`, `Monthly`.
- Prefer PHPDoc blocks over inline comments. Only add inline comments for exceptionally complex logic.
- Use array shape type definitions in PHPDoc blocks.

=== deployments rules ===

# Deployment

- Laravel can be deployed using [Laravel Cloud](https://cloud.laravel.com/), which is the fastest way to deploy and scale production Laravel applications.

=== tests rules ===

# Test Enforcement

- Test every code change by adding or updating a test.
- Run the affected tests and ensure they pass.
- Test the changed behavior and its important failure modes, but do not add tests beyond them.
- Read the `testing-best-practices` skill before writing tests.

=== laravel/core rules ===

# Do Things the Laravel Way

- Use `php artisan make:` commands to create new files (i.e. migrations, controllers, models, etc.). You can list available Artisan commands using `php artisan list` and check their parameters with `php artisan [command] --help`.
- If you're creating a generic PHP class, use `php artisan make:class`.
- Pass `--no-interaction` to all Artisan commands to ensure they work without user input. You should also pass the correct `--options` to ensure correct behavior.

### Model Creation

- When creating new models, create useful factories and seeders for them too. Ask the user if they need any other things, using `php artisan make:model --help` to check the available options.

## APIs & Eloquent Resources

- For APIs, default to using Eloquent API Resources and API versioning unless existing API routes do not, then you should follow existing application convention.

## URL Generation

- When generating links to other pages, prefer named routes and the `route()` function.

## Testing

- When creating models for tests, use the factories for the models. Check if the factory has custom states that can be used before manually setting up the model.
- Faker: Use methods such as `$this->faker->word()` or `fake()->randomDigit()`. Follow existing conventions whether to use `$this->faker` or `fake()`.
- When creating tests, make use of `php artisan make:test [options] {name}` to create a feature test, and pass `--unit` to create a unit test. Most tests should be feature tests.

## Vite Error

- If you receive an "Illuminate\Foundation\ViteException: Unable to locate file in Vite manifest" error, you can run `npm run build` or ask the user to run `npm run dev` or `composer run dev`.

=== pint/core rules ===

# Laravel Pint Code Formatter

- If you have modified any PHP files, you must run `vendor/bin/pint --dirty --format agent` before finalizing changes to ensure your code matches the project's expected style.
- Do not run `vendor/bin/pint --test --format agent`, simply run `vendor/bin/pint --format agent` to fix any formatting issues.

=== phpunit/core rules ===

# PHPUnit

- This project uses PHPUnit. Create tests with `php artisan make:test --phpunit {name}`.
- Do not include the test suite directory in `{name}`. Use `SomeFeatureTest`, not `Feature/SomeFeatureTest`.
- Read the `testing-best-practices` skill for guidance on coverage, naming, structure, dependency isolation, and review.

## Running Tests

- Run the narrowest set of tests that covers the change. Pass a file path or `--filter=testName` to `php artisan test --compact`.
- Rerun a test after each change to it.
- Run `vendor/bin/phpunit` to call the test runner directly. It accepts the same file path and `--filter=testName` arguments.

</laravel-boost-guidelines>
