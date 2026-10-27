# MyAPES Core

MyAPES Core is the APES CIC service-user and staff portal built on Laravel for Cloudron LAMP deployments. The GitHub repository remains `APESCIC/MyAPES-Account`. Account login and registration surfaces may still say **MyAPES Account**.

## Product vocabulary

| Public name | Meaning | Internal identifiers (unchanged) |
|-------------|---------|----------------------------------|
| **MyAPES Core** | Platform software (auth chrome, Admin, dashboard, changelog) | Application / `APP_NAME` |
| **Services** | Service hubs: APES CIC, Shelter and Rescue, Pet Care Clinic | `sub_cores` |
| **Plugins** | Capabilities: Tickets, Cases, Pet Profiles, Consultations | `module_types` / module instances |

## Repository status

- Last verified README health review: `2026-08-14T16:05:40+01:00`
- Source-controlled application version: [`VERSION`](VERSION)
- Continuous integration and manual Cloudron deploy: [Deploy MyAPES Core to Cloudron](https://github.com/APESCIC/MyAPES-Account/actions/workflows/deploy-cloudron.yml)
- Public release history: [MyAPES Core Change Log](https://myaccount.myapes.me.uk/change-log)

## Support and maintainers

- [Report a bug](https://github.com/APESCIC/MyAPES-Account/issues/new?template=bug_report.yml)
- [Request a feature](https://github.com/APESCIC/MyAPES-Account/issues/new?template=feature_request.yml)
- [Report a security vulnerability privately](SECURITY.md)
- [Browse existing issues](https://github.com/APESCIC/MyAPES-Account/issues)
- [Browse discussions](https://github.com/APESCIC/MyAPES-Account/discussions)
- Maintained by [APES CIC](https://github.com/APESCIC) with repository administration by [bmurphy-apescic](https://github.com/bmurphy-apescic). New issues are assigned to [apescic-director](https://github.com/apescic-director).

Do not disclose suspected security vulnerabilities in a public issue. Follow the private reporting path in [SECURITY.md](SECURITY.md).

## Core architecture

- **Authentication and session context**:
  - Public service users authenticate with local email/password. Directory identities cannot use `/login` or public remember-me restoration.
  - Staff, volunteers, students, and administrators authenticate through APES Cloudron OIDC on Staff Login (`/staff/login`) with exact LDAP group eligibility. Local staff password login is local/testing only.
  - One Laravel `web` guard carries explicit `password`, `cloudron_oidc`, or local/testing-only `qa` session provenance.
  - Durable authorization epochs, recent directory-validation timestamps, suspension checks, remember-token rotation, and the Phase B session-cutover marker force reauthentication whenever authorization changes.
- **Protected authorization**:
  - The exact protected roles are `service-user`, `student`, `volunteer`, `staff`, `administrator`, and `super-admin`.
  - Students and volunteers receive staff-class service and plugin access for all enabled modules, but do not receive module `*.delete` abilities. Staff, administrators, and super-admins retain delete where the registry grants it.
  - The core code-owned permission catalogue contains `staff.access`, `admin.access`, `superadmin.access`, `admin.users.view`, `admin.users.manage`, `admin.groups.view`, `admin.group-mappings.manage`, `admin.roles.view`, `admin.roles.manage`, `admin.permissions.view`, `admin.modules.view`, `admin.modules.manage`, `admin.analytics.view`, and `admin.maintenance.manage`.
  - The first-party registry contributes 59 namespaced permissions for shipped instances. All 73 permissions are synchronized from immutable code definitions and enforced by the application-owned Gate and policies.
  - Administrators retain `admin.modules.view`; `admin.modules.manage` is super-admin-only.
  - Direct user permissions remain an internal central-materializer capability with mandatory provenance and no arbitrary Admin assignment UI. Authorized Admin user details show the same deduplicated role-plus-direct effective set used by the Gate and list each direct source with only a system label or granting account ID.
  - Spatie provides role/permission storage only. Its automatic Gate hook remains disabled while the teams schema and wildcard matching are enabled for the application-owned authorization path. Direct user permissions are allowed only through the central provenance materializer; direct pivot mutation remains disabled.
  - Every effective role has `system`, `directory`, `local`, or `legacy-compatibility` provenance. Local assignments require the persisted user ID of the actor who granted them, may use custom roles, and cannot assign protected roles or replace missing directory eligibility; non-local sources cannot claim an actor.
  - Staff assignment and notification eligibility requires an unsuspended protected staff-class pivot and a qualifying source for that same role. Production accepts directory provenance only; local/testing may also accept the approved system and legacy compatibility fixtures. A custom role containing `staff.access` never qualifies by itself.
  - Privileged role, mapping, and user mutations lock the singleton authorization state before users or directory records, then revalidate the session method, user and global epochs, directory generation, suspension, exact-role provenance, and effective protected role. Final-super-admin safeguards use this same present-group-backed predicate; an unprovenanced or stale pivot cannot satisfy them.
- **Directory catalogue and mappings**:
  - The only immutable mappings are the five preset `myapesaccount.*` groups: `staff`, `admin`, `superadmin`, `volunteer`, and `student` to their matching protected roles. Super-admins may add a mutable job-role mapping on the same managed group without replacing those presets.
  - Fresh installs seed four custom job roles (`board-of-directors`, `management`, `client-services-advisor`, `receptionist`) with reviewed default permission packs. Later metadata sync creates any missing defaults and does not overwrite Super Admin permission edits. Job roles never replace missing directory eligibility for staff-class access.
  - Matching is normalized and exact. Only groups with the configured `myapesaccount.` prefix are synchronized into the working catalogue; wildcards are rejected, and known legacy or misspelled aliases map to the canonical names.
  - Historical non-prefix catalogue rows remain in the database for audit continuity but are hidden from the Access Groups tab and are not marked missing by sync.
  - The catalogue stores normalized group identity, optional external ID, aggregate member count, presence state, and synchronization timestamps; individual directory members are never persisted in the catalogue, but directory sync can pre-provision Cloudron OIDC users and staff profiles before first login.
  - Manual and scheduled sync rematerialize directory (`cloudron_oidc`) users who belong to required `myapesaccount.*` groups. Directory users missing from those memberships are suspended with reason `directory-disabled` (public local accounts are never created or updated by sync). Staff Login and session checks deny suspended accounts; a later successful directory proof can clear only `directory-disabled` suspensions.
  - Manual and scheduled catalogue requests share one unique, coalesced job and the same database lease. Attempts, backoff, execution, queue reservation, and LDAP connection/search times are bounded; the queue reservation always exceeds one job attempt.
- **Administration**:
  - Admin Users supports safe identity detail, search/filtering, custom local-role assignment, suspension/reactivation, effective permissions, provenance, and audit history within target-aware authorization boundaries. Target lookup occurs only after authorization, so missing and existing identifiers produce the same sanitized denial for unauthorized actors.
  - **Access** (`/admin/access`) replaces separate Groups, Roles, and Permissions pages with one workspace: Groups (Cloudron catalogue, read-only access tier, optional job-role mappings, Sync from Cloudron), Job roles (default and custom roles with reviewed capability packs plus Advanced permissions), and a read-only permission catalogue. Legacy `/admin/groups`, `/admin/roles`, and `/admin/permissions` URLs redirect into the matching tab. Enable/disable and protected-mapping edits stay unavailable.
- **First-party plugin registry** (internal module contracts):
  - Immutable Laravel contracts define the permanent Services (`apes-cic`, `shelter-rescue`, and `pet-care-clinic` sub-cores) plus the Plugins (`tickets`, `cases`, `pet-profiles`, and `consultations` module types). Executable providers, instance-scoped active-record detectors, routes, summaries, bounded recent activity, and typed analytics snapshots are registered from reviewed source code only; database or writable-storage discovery is unsupported.
  - The eight shipped instances are APES CIC Tickets and Cases; APES Shelter and Rescue Pet Profiles, Tickets, and Cases; and APES Pet Care Clinic Pet Profiles, Tickets, and Consultations. Fresh and upgraded databases keep them installed and enabled by default without overwriting a later intentional disabled state. All other matrix cells are explicitly incompatible.
  - Lifecycle operations are transactional, super-admin-only, and serialized with module write requests through the same durable per-instance advisory/file lock. Lock acquisition has a bounded wait, while ownership lasts for the complete operation. Dependencies and active records are rechecked under the transition transaction; disablement never deletes records, and no uninstall operation exists.
  - Each installation carries a monotonic transition version, so even multiple enable/disable operations within one second invalidate stale Admin forms. Direct route checks read authoritative installation state. Generated navigation and aggregate dashboard summaries use a short versioned projection cache, invalidated after committed lifecycle transitions and synchronization repairs.
  - v0.14.0 adds no database migration. The Shelter modules reuse the existing sub-core-discriminated `support_tickets`/`support_ticket_messages`, `pet_profiles`, `shelter_cases`/`case_updates`, and media storage. Existing records are neither copied nor assigned new IDs, owners, pets, assignees, morph identities, or media paths.
  - v0.15.0 also adds no database migration. APES Pet Care Clinic reuses its existing rows in `pet_profiles` and `pet_care_consultations` plus the shared sub-core-discriminated `support_tickets` and `support_ticket_messages` tables. Existing IDs, owners, pet links, assignments, schedules, statuses, closure timestamps, audit and notification identities, media paths, and physical media remain unchanged.
- **Core app features**: account dashboard, profile/settings, role-aware navigation, media uploads.
- **Services** (hubs; internal sub-cores):
  - **APES CIC** (`/apes-cic`) - organisational support Tickets and Cases. Owners can create and view their own records and add public updates. Staff Ticket replies and Case updates can be explicitly public or internal; owner views, notifications, hub activity, and audits never disclose internal bodies. Ticket and Case records, lifecycle checks, navigation, dashboard totals, recent activity, and analytics are all scoped to the `apes-cic` plugin instance.
  - **APES Shelter and Rescue** (`/shelter`) - Pet Profiles, Tickets, and pet-linked Cases. Every record, route, summary, activity item, analytics result, attention item, assignee, recipient, and photo request is constrained to the Shelter domain and `shelter-rescue` instance before authorization.
  - **APES Pet Care Clinic** (`/petcare`) - Pet Profiles, Tickets, and Consultations with exact owner/staff permissions and instance-scoped dashboard providers
- **Cloudron service integrations**: MySQL, Redis (cache/session/queue), and sendmail-compatible SMTP delivery.

### Login entry points

- `/` - landing page with Public Login, Register, and Staff Login choices
- `/login` and `/register` - public local password accounts only. Directory (`cloudron_oidc`) users are redirected to Staff Login and cannot use public password authentication.
- `/staff/login` - the only production sign-in path for Cloudron directory users; it starts Cloudron OIDC. The local staff password form exists only in local/testing.
- `/change-log` - public, searchable MyAPES Core release history for guests, public users, and staff
- local/testing only: QA role switcher (Public/Student/Volunteer/Staff/Admin/Super Admin) available in the app layout for one-click identity switching

Public service-user accounts stay separate from Cloudron staff accounts: directory sync creates `identity_type=cloudron_oidc` users only, never `identity_type=local` public users, and does not convert an existing local public account. Public users receive own-record module abilities plus selected services; they cannot open Staff, Admin, or Super Admin routes. Volunteers and students share staff-class service access without delete. Admins can open Admin Users; Super Admins can open Access, Modules, and Maintenance.

### APES CIC route and permission contract

The APES CIC hub exposes 12 authenticated application routes: the stable hub route, five unchanged Ticket routes, five Case resource routes, and one Case-update route. The Cases permission namespace is:

`apes-cic.cases.{view-own,create,update-own,comment-own,view-all,update-all,assign,close,delete}`

`update-own` remains code-owned for shared Shelter compatibility, but the APES CIC public controller intentionally exposes only owner comments; ownership, category, priority, status, assignment, visibility, and lifecycle timestamps remain staff-controlled.

### APES Shelter and Rescue route, permission, and privacy contract

The Shelter hub exposes the installed Pet Profiles, Tickets, and Cases modules.
Pet Profiles use the exact
`shelter-rescue.pet-profiles.{view-own,create,update-own,view-all,update-all}`
namespace. Tickets use
`shelter-rescue.tickets.{view-own,create,comment-own,view-all,update-all,assign,close,delete}`;
Cases use
`shelter-rescue.cases.{view-own,create,update-own,comment-own,view-all,update-all,assign,close,delete}`.
Owners need exact `view-own` to see their own rows; removing it removes those
rows. Owner changes also require the corresponding `create`, `update-own`, or
`comment-own` ability. Staff-wide records, internal content, assignee choices,
and notification recipients require an unsuspended eligible protected
staff-class identity plus exact `view-all` in that same module namespace.
Assignment additionally requires exact `assign`, Case close/reopen requires
exact `close`, and cross-owner metadata changes require exact `update-all`.

Shelter Tickets are available at `GET|POST /shelter/tickets`,
`GET /shelter/tickets/{ticket}`, and
`PUT|PATCH /shelter/tickets/{ticket}` through
`shelter.tickets.index|store|show|update`. Their service areas are exactly
`adoption`, `surrender`, `rescue`, `fostering`, `animal_welfare`, and `other`.
Ticket owners can see public conversation messages; eligible exact-namespace
staff can also see and create internal notes. Public replies may notify an
authorized owner and eligible exact-namespace staff, while internal replies
never disclose their body to the owner. Reply bodies are excluded from audit
and notification metadata.

Shelter Cases remain linked to a real Shelter-domain Pet Profile and retain the
`adoption`, `surrender`, `rescue`, and `fostering` types plus
`open`, `in_review`, and `closed` states. Public updates may notify an
authorized owner and touch activity ordering; internal updates are visible
only to eligible exact-namespace staff, do not notify the owner, and do not
touch or reorder the parent Case. Update bodies are excluded from audit and
notification metadata. APES CIC, `petcare`-domain, missing-pet, cross-owner,
and cross-sub-core identifiers fail closed without disclosing a foreign row.

The code-owned Shelter Ticket and Case `delete` abilities remain part of the
wholesale module permission contract, but there is no Shelter DELETE route,
controller action, form, or link. Existing APES CIC deletion behavior is
unchanged.

Pet photos keep their database paths and physical
`storage/app/public/pet-profiles` location. Authorized requests stream them
through `shelter.pets.photo` or `petcare.pets.photo` with private, no-store,
MIME-safe responses. Wrong-domain, malformed-path, missing-file, and
unauthorized requests return 404; `/storage/pet-profiles/*` is never a public
delivery path, and only avatars retain a public runtime link.

### APES Pet Care Clinic route, permission, and privacy contract

The APES Pet Care Clinic hub exposes the installed Pet Profiles, Tickets, and
Consultations modules at the stable `/petcare` route family. Pet Profiles use
the exact
`pet-care-clinic.pet-profiles.{view-own,create,update-own,view-all,update-all}`
namespace. Tickets use the exact
`pet-care-clinic.tickets.{view-own,create,comment-own,view-all,update-all,assign,close,delete}`
namespace. Consultations use the exact
`pet-care-clinic.consultations.{view-own,create,update-own,view-all,update-all,assign,close}`
namespace. Owners require the matching exact owner permission for their own
records in the `petcare` domain. Staff-wide visibility, assignee candidates,
and staff notification recipients require an unsuspended eligible protected staff-class
identity plus the relevant exact module permission; ordinary staff updates,
assignment changes, and terminal close/reopen transitions remain independently
authorized.

APES Pet Care Clinic Tickets are available at
`GET|POST /petcare/tickets`, `GET /petcare/tickets/{ticket}`, and
`PUT|PATCH /petcare/tickets/{ticket}` through
`petcare.tickets.index|store|show|update`. Their service areas are exactly
`appointment`, `consultation`, `prescription`, `billing`, `follow_up`, and
`other`. Ticket owners see public messages only; eligible exact-namespace staff
can see and create internal notes. Message bodies are excluded from audit and
notification metadata, and internal messages never notify or disclose their
body to owners. The code-owned Ticket `delete` ability remains part of the
shared wholesale permission contract, but APES Pet Care Clinic has no DELETE
route, controller action, form, or link.

Pet Profile and linked-pet requests require the `petcare` domain, and every
Ticket request requires the `pet-care-clinic` sub-core before record
authorization. Foreign Shelter or APES CIC identifiers fail with a safe 404.
Enabled Pet Profiles, Tickets, and Consultations independently contribute
instance-scoped summaries, bounded recent activity, and typed analytics;
Tickets and Consultations also contribute attention items. Disabled modules
contribute no navigation, records, activity, attention, or analytics.

## Brand assets

- Canonical artwork: `resources/branding/source/apes-logo-v3.png`
- Regenerate application derivatives on Windows with:

```powershell
pwsh -NoProfile -File .\scripts\branding\generate-brand-assets.ps1
```

The generator preserves the supplied square animal artwork for the sidebar and visible brand marks, creates padded maskable icons, and places the artwork on the dark MyAPES surface for social previews.

### App-visible logo

| Asset | Purpose |
| --- | --- |
| `public/branding/logo-myapes-account.png` | Shared desktop sidebar, mobile header, landing, and staff-login logo |
| `public/branding/email-header-logo.png` | Square email/header-safe logo export |
| `public/branding/login-hero.png` | Wide dark-surface brand export |
| `public/logos/myapes-mark-128x128.png` | Compact raster mark |
| `public/logos/myapes-mark-256x256.png` | Compact high-density raster mark |

### Bearded dragon mascot

| Asset | Purpose |
| --- | --- |
| `public/mascot/spike-welcome.png` | Cartoon Spike portrait for landing, staff sign-in, and the dashboard identity card |
| `public/mascot/spike-tip.png` | Smaller Spike artwork for in-page help callouts |
| `public/mascot/spike-dock.png` | Full-body pointing Spike for the dismissible helper dock |

Spike is a named cartoon helper who offers short operational tips. Page-keyed copy lives in `app/Support/MascotTips.php`. Guests and signed-in users can hide the dock for the current page; that choice is stored locally as `myapes-mascot-dismissed-v2`. Admin and change-log pages do not show the dock.

The interface uses a light-first desert theme with a sun-baked earth sidebar, sand canvas, clay and sage accents, and teal as the oasis focus colour. A saved colour-theme choice is stored locally in the browser as `myapes-theme`; the first visit always starts in light mode.
### Browser, PWA, and social assets

| Asset | Purpose |
| --- | --- |
| `public/favicon.ico` | Multi-resolution browser favicon |
| `public/favicons/favicon-16x16.png` | 16px favicon |
| `public/favicons/favicon-32x32.png` | 32px favicon |
| `public/favicons/favicon-48x48.png` | 48px favicon |
| `public/favicons/safari-pinned-tab.svg` | Safari pinned-tab icon |
| `public/icons/apple-touch-icon.png` | iOS home-screen icon |
| `public/icons/pwa-192x192.png` | PWA install icon |
| `public/icons/pwa-512x512.png` | PWA install icon |
| `public/icons/pwa-maskable-192x192.png` | Maskable PWA icon |
| `public/icons/pwa-maskable-512x512.png` | Maskable PWA icon |
| `public/social/og-image-1200x630.jpg` | Open Graph / social preview image |
| `public/site.webmanifest` | Web app manifest |
| `public/browserconfig.xml` | Microsoft tile metadata |

## Local environment setup

Run the bootstrap script from the repository root. It installs PHP/Node dependencies, creates a local `.env` from `.env.local.example`, configures SQLite plus file-backed cache/sessions, generates the app key once, migrates, seeds, and builds the frontend.

The script refuses to rewrite an environment unless `APP_ENV` is already `local` or `testing`. Local development does not require MySQL or Redis.

### One-command local QA bootstrap (deterministic test data)

Use a fresh migration+seed reset when you need the same test accounts and records every time.

### macOS / Linux

```bash
bash scripts/local/bootstrap.sh --fresh
```

### Windows PowerShell

```powershell
powershell -ExecutionPolicy Bypass -File .\scripts\local\bootstrap.ps1 -Fresh
```

The `--fresh` / `-Fresh` option is destructive for local data (`migrate:fresh --seed`) and is intended for QA resets.

If you need non-destructive seeding, use:

```bash
bash scripts/local/bootstrap.sh --seed
```

```powershell
powershell -ExecutionPolicy Bypass -File .\scripts\local\bootstrap.ps1 -Seed
```

### Local development with Laragon (Windows)

Use Laragon when you prefer an Apache virtual host (for example `http://myapes-account.test`) instead of `php artisan serve` on port `8000`. The Laragon profile keeps the same SQLite QA stack; MySQL and Redis are not required for local testing.

**Prerequisites**

- Laragon running with **PHP 8.3+** (prefer **8.4** to match CI)
- Typical Laravel PHP extensions enabled in Laragon (`pdo_sqlite`, `openssl`, `mbstring`, `curl`, `fileinfo`, and related defaults)
- `php`, `composer`, `node`, and `npm` available in the Laragon terminal

**Virtual host**

- The owner's clone is `C:\laragon\www\myapes-account`, which Laragon serves as `http://myapes-account.test`
- Document root must be **`{repo}/public`** (Laragon “Quick app → Laravel” or an equivalent manual vhost)
- Restart Apache after vhost changes

**One-time Laragon bootstrap**

```powershell
cd C:\laragon\www\myapes-account
powershell -ExecutionPolicy Bypass -File .\scripts\local\bootstrap.ps1 -Laragon -Fresh
```

Use `-AppUrl http://your-vhost.test` when the auto-generated hostname differs from `myapes-account.test`. The bootstrap copies [`.env.laragon.example`](.env.laragon.example), sets `VITE_DEV_SERVER_URL=http://127.0.0.1:5173`, and keeps SQLite plus file-backed cache and sessions.

**Daily dev loop**

1. Start Laragon (Apache)
2. In the repo terminal: `composer run dev:laragon` (or `powershell -ExecutionPolicy Bypass -File .\scripts\local\dev-laragon.ps1`)
3. Open `http://myapes-account.test` (or your `-AppUrl` value)
4. Use the seeded QA accounts below (`/login` auto-login in local)

**Testing**

- Feature and unit tests: `php artisan test` or `composer test`
- Pre-merge validation: `composer pre-merge`

**Troubleshooting**

- **404 or wrong site:** confirm the vhost document root is `public/`, not the repository root
- **Unstyled pages:** ensure `composer run dev:laragon` is running, or run `npm run build`
- **Passkeys / WebAuthn locally:** browsers require a secure context. Prefer Laragon HTTPS (`https://myapes-account.test`) with a trusted local certificate, and set `APP_URL` to that HTTPS origin so `relying_party_id` / allowed origins match. Plain `http://127.0.0.1:8000` will not complete device ceremonies. Live Cloudron uses `https://myaccount.myapes.me.uk` (changing rpId invalidates existing passkeys).
- **URL or OIDC callback mismatch:** re-run bootstrap with `-Laragon`, or align `APP_URL`, `OIDC_REDIRECT_URI`, and `VITE_DEV_SERVER_URL` in `.env`

Both local bootstrap scripts enforce the tracked selective-media boundary at
`public/storage/.myapes-selective-media`. They refuse marker changes or
unexpected entries and create only `public/storage/avatars`, targeting
`storage/app/public/avatars`. Pet Profile photos remain in
`storage/app/public/pet-profiles` but are delivered only through authenticated,
authorization-checked Shelter or APES Pet Care Clinic routes; they are never exposed by a
public-storage link.

### Seeded local QA accounts

All seeded users use this password:

- `MyAPES-Local-QA-2026!`

In local/testing, opening `/login` immediately signs into the seeded public account. Use the in-app QA role switcher to move between Public, Student, Volunteer, Staff, Admin, and Super Admin without re-entering credentials. These are local identities with `qa` session provenance and system-provenanced protected baselines; they do not contain OIDC subjects, directory memberships, production group aliases, or direct user permissions. The Staff fixture also has the deterministic local custom role `local-qa-reviewer`. If a seeded account is missing, re-run `php artisan db:seed` in the local environment.

| Role | Login email | Login route | Primary QA coverage |
| --- | --- | --- | --- |
| Public service user | `qa.service.user@myapes.local` | `/login` (auto-login) or QA switcher | Public dashboard, profile/settings, APES CIC Tickets/Cases, Shelter Pet Profiles/Tickets/Cases, and APES Pet Care Clinic Pet Profiles/Tickets/Consultations (owner-scoped views) |
| Student | `qa.student@myapes.local` | QA switcher or `/staff/login` (local direct form) | Staff-class service workflows without module delete abilities |
| Volunteer | `qa.volunteer@myapes.local` | QA switcher or `/staff/login` (local direct form) | Staff-class service workflows without module delete abilities |
| Staff | `qa.staff@myapes.local` | QA switcher or `/staff/login` (local direct form) | Exact-namespace staff visibility, assignment updates, internal Ticket messages and Case updates, status workflows, delete abilities, and the local custom-role fixture |
| Admin | `qa.admin@myapes.local` | QA switcher or `/staff/login` (local direct form) | Staff workflows plus Admin Users and the simplified Admin overview KPIs |
| Super Admin | `qa.superadmin@myapes.local` | QA switcher or `/staff/login` (local direct form) | Super Admin panel (`/superadmin`): directory groups, roles, permissions, modules, maintenance, and technical analytics charts |

### Live public walkthrough account (production)

Production walkthroughs and public-surface QA should use the dedicated local public account below instead of a superadmin session. Credentials live in the APES operator secret store (Vaultwarden); they are not stored in this repository, GitHub issues, or release notes.

| Field | Value |
| --- | --- |
| Email | `developer@apes.org.uk` |
| Username | `walkthrough-public` |
| Sign-in route | `/login` (public local password auth) |
| Protected role | `service-user` only (`identity_type=local`, no OIDC subject) |
| Services | APES CIC, APES Shelter and Rescue, and APES Pet Care Clinic |

Separation rules:

- Use `/login` only. Staff Login, Cloudron OIDC, and directory sync must never own or convert this account.
- The account can reach public modules and owner-scoped records only; Admin, Super Admin, and staff-only routes remain forbidden.
- Local QA seeds such as `qa.service.user@myapes.local` stay local/testing-only fixtures and do not substitute for this live account.
- An Admin or Super Admin can issue a one-time temporary password from Admin → Public users for this local account. Guest forgot-password remains tracked in issue #121. Directory / Cloudron / pending first-login accounts stay on Cloudron.

### Feature test matrix by seeded role

| Role | Key flows to validate quickly |
| --- | --- |
| Public | Create/view/comment on own APES CIC and Shelter Tickets/Cases; create/view/comment on own APES Pet Care Clinic Tickets; update own Shelter and APES Pet Care Clinic Pet Profiles and Clinic Consultations; edit profile/settings; and verify owner-only/public-update visibility |
| Student / Volunteer | Use staff-class visibility and updates across services; confirm delete actions are denied |
| Staff | Use exact instance permissions to see all users' Tickets/Cases/Consultations, assign eligible staff/admin accounts, update statuses, delete where permitted, and verify internal-note privacy |
| Admin | Run full staff workflows plus inspect Admin Users and the simplified Admin overview |
| Super Admin | Open `/superadmin`, exercise Access/Modules/Maintenance, and confirm overview charts stay fixed-height |

### Seeded data included for quick E2E checks

- APES CIC tickets: two open/in-progress examples plus a resolved example with message history and staff/admin assignment
- APES CIC cases: owner-scoped categories, priorities, public updates, internal staff notes, assignment, and close/reopen transitions
- Shelter Tickets: an open unassigned adoption enquiry, an in-progress staff-assigned rescue request, and a closed admin-assigned animal-welfare follow-up, each with deterministic public and internal messages
- Shelter Cases: the existing Mango Pet Profile with two open/in-review cases plus a closed example; the rescue Case has deterministic public and internal updates without changing its parent identity or timestamp
- APES Pet Care Clinic: a seeded Pet Profile with two open/in-progress Consultations plus a closed example, and three deterministic Tickets covering appointment/open/unassigned/low, prescription/in-progress/staff/high, and billing/closed/admin/medium states with one public and one internal message each
- Profiles: the public QA account has a `UserProfile`; student, volunteer, staff, admin, and superadmin QA accounts have `StaffProfile` workplace details. Repeated non-destructive seeding preserves Ticket/Case parent and child IDs, owners, pets, assignees, values, and timestamps without cross-sub-core overwrite

Start Laravel, the queue listener, application logs, and Vite together with the cross-platform Composer script:

```powershell
composer run dev
```

The launcher automatically uses Laravel Pail for logs on macOS and Linux. On native Windows it follows `storage/logs/laravel.log` with PowerShell instead, because PHP's Unix-only `pcntl` extension is not available there; do not try to install `pcntl` on native Windows.

Set `APP_PORT` before starting the launcher to use a Laravel port other than `8000`. The platform wrappers below delegate to `composer run dev` and remain available when you prefer an OS-specific entry point.

On Laragon with an Apache virtual host, skip `composer run dev` and use `composer run dev:laragon` instead; see “Local development with Laragon (Windows)” above.

### macOS / Linux

```bash
bash scripts/local/dev.sh
```

### Windows PowerShell

```powershell
powershell -ExecutionPolicy Bypass -File .\scripts\local\dev.ps1
```

## Release history and semantic versioning

The current application version is stored in the root `VERSION` file without a display-only `v` prefix. Reviewed public release records live in `resources/data/releases.json`; the newest record is authoritative and must match `VERSION`. The shared footer links the displayed version to the public `/change-log` hub.

Every future change merged to `main` must:

1. Prepend exactly one higher semantic version to `resources/data/releases.json`.
2. Update `VERSION` to that same version.
3. Keep `resources/data/module-runtime-contract.json` → `application_version` in sync with `VERSION`.
4. Leave every previously published record unchanged and in the same order.
5. Use a minor version for a new backward-compatible capability and a patch version for a compatible fix. While the application remains pre-1.0, document a breaking change explicitly and advance the minor version.
6. Keep public notes free of credentials, personal data, private operational identifiers, exploitable security detail, and unnecessary infrastructure detail.

Preferred workflow (agents and humans): scaffold the next release in the same pull request as the feature or fix:

```powershell
php artisan myapes:changelog-prepare --type=patch --title="Short public title" --issue=<n> --pr=<n>
```

Replace every `TODO:` field in the new head record, then validate:

```powershell
php artisan myapes:changelog-validate
```

Compare a proposed release against the current remote main branch:

```powershell
git fetch origin
php artisan myapes:changelog-validate --base-ref=origin/main
```

Pull-request and `main` workflows perform the same append-only comparison. Manual workflow dispatch of the test workflow performs structural validation without requiring another version. In-PR release metadata does not create Git tags automatically. Agents run local dev verify on the PR branch before merge. A successful Cloudron deploy (last post-merge step) creates the GitHub Release with display title `{VERSION} Beta` and tag `v{VERSION}` on the deployed SHA. See ship-gate.

### GitHub Release tags

Every documented app version in `releases.json` has a matching GitHub Release tag `v{VERSION}` (for example `v0.31.6` for `VERSION` `0.31.6`). The Change Log Hub and `/healthz` `version` field read from `VERSION` / `releases.json`; GitHub Releases are the external mirror on [the repository Releases page](https://github.com/APESCIC/MyAPES-Account/releases). Deploy sets the release **display name** to `{VERSION} Beta`.

- Map versions to merge commits: `node scripts/local/map-release-commits.mjs --write`
- Backfill or verify releases: `node scripts/local/backfill-github-releases.mjs --dry-run` then `node scripts/local/backfill-github-releases.mjs --resume`
- Rename display titles to `{VERSION} Beta`: `bash scripts/github/rename-release-titles.sh`
- `/healthz` `release` remains the immutable **deploy commit SHA**; the GitHub tag points at the **version introduction commit** on `main`. Neither replaces the other.

## Cloudron deployment automation

Deployments are handled by `.github/workflows/deploy-cloudron.yml`.

### Deployment triggers

1. Deploy runs only on manual `workflow_dispatch` (for example `gh workflow run "Deploy MyAPES Core to Cloudron" --ref main`). There is no auto-deploy after a green `main` test run.
2. Run local dev verify on the PR branch before merge (`composer pre-pr-verify` or ship-gate steps). Prefer Cloudron deploy only after `test-cloudron.yml` has succeeded for the target `main` revision, local verify passed, and changelog metadata on `main` is confirmed. Cloudron is the **last** post-merge deploy step; pass `app_version` from `VERSION` when dispatching (ship-gate).
3. After a successful Cloudron deploy, the app Change Log Hub at `/change-log` shows the head `releases.json` record, and the deploy workflow publishes GitHub Release `{VERSION} Beta` with tag `v{VERSION}`. Sibling APES websites keep separate changelogs; update those only when the operator asks (ship-gate).

### Target app

- Cloudron app ID: `3465c63f-0e1b-4e49-8f5f-799a471055a1`

### Required GitHub secrets

| Secret | Description |
| --- | --- |
| `CLOUDRON_FQDN` | Cloudron dashboard domain used by the CLI |
| `CLOUDRON_TOKEN` | Personal Cloudron API token with permission to back up, push to, execute in, and restart the target app |

Create these as secrets in the GitHub environment named `cloudron-deploy`. Do not commit either value.

### Staff OIDC and LDAP setup

Create a one-time Cloudron OpenID client named **MyAPES Account** in
**Users → OpenID** with this callback:

`https://myaccount.myapes.me.uk/staff/auth/callback`

Use the issuer `https://my.cloudron.apes.org.uk/openid` and the
`openid profile email` scopes. Store `OIDC_ISSUER`, `OIDC_CLIENT_ID`,
`OIDC_CLIENT_SECRET`, and `OIDC_REDIRECT_URI` only in the Cloudron app
environment. The generated credentials must not be copied into GitHub,
the repository, release archives, logs, or chat.

Before deploy, create or rename Cloudron directory groups to these exact
`myapesaccount.*` names and move members off legacy `myapes.*` duplicates:

- `myapesaccount.staff` → staff role
- `myapesaccount.admin` → administrator role
- `myapesaccount.superadmin` → super-admin role
- `myapesaccount.volunteer` → volunteer role (optional membership; group must exist)
- `myapesaccount.student` → student role (optional membership; group must exist)

Delete obsolete legacy groups after migration, including `myapes.staff`,
`myapes.admins`, `myapes.superadmins`, plural variants, and misspellings such as
`myapes.vounteers`, `myapesaccount.vounteer`, and `myapesaccont.*`. The app
temporarily normalizes those misspellings to the canonical `myapesaccount.*`
names so deploy readiness can succeed while operators rename Cloudron groups.
Only the five preset groups above are synchronized and mapped; custom group
mappings and enable/disable controls are not supported.

The LAMP package injects rotating `CLOUDRON_LDAP_*` credentials. MyAPES uses
OIDC for authentication and LDAP membership for authorization; LDAP credentials
must not be copied into the Laravel environment file. Group matching is
lowercase-normalized and exact; legacy aliases and wildcards are rejected.
LDAP connection and search limits default to five and ten seconds through
`LDAP_CONNECT_TIMEOUT_SECONDS` and `LDAP_SEARCH_TIMEOUT_SECONDS`.
`DB_QUEUE_RETRY_AFTER` defaults to 300 seconds and must remain greater than the
directory job's own 240-second execution timeout. The Cloudron worker keeps a
60-second default timeout so the hardened launcher retained during code
rollback also remains below v0.7.1's 90-second queue reservation; the Phase B
directory job's explicit timeout overrides that worker default.

The authorization lifecycle commands are:

```bash
php artisan myapes:authorization-preflight --no-interaction --no-ansi
php artisan myapes:authorization-sync --no-interaction --no-ansi
php artisan myapes:authorization-check --no-interaction --no-ansi
php artisan myapes:directory-sync --source=manual
php artisan myapes:directory-sync --source=scheduled
```

The module lifecycle deployment commands are:

```bash
php artisan myapes:modules:preflight --no-interaction --no-ansi
php artisan myapes:modules:sync --no-interaction --no-ansi
php artisan myapes:modules:check --no-interaction --no-ansi
php artisan myapes:modules:rollback-check --target-release=/absolute/release/path --no-interaction --no-ansi
```

`myapes:modules:preflight` validates the supported database driver and complete
3×4 code registry before migration. `myapes:modules:sync` creates missing
shipped defaults without changing existing installation state or actor history;
a repaired dependent remains disabled while any prerequisite is disabled. The
synchronizer acquires all 12 code-owned lifecycle locks before its transaction
and reads installation state under database row locks, so a concurrent
transition completes before dependency state is materialized.
`myapes:modules:check` verifies shipped installation, dependency, and permission
postconditions. The rollback command is read-only, drains all code-owned module
instance locks, and checks whether current database state is representable by
the target release contract. Migration teardown derives the exact module
permission names from the immutable registry and removes only `web` records
that are still marked code-owned; similarly prefixed custom permissions,
other guards, and exact-name permissions deliberately demoted to custom
ownership are preserved with their pivots and provenance.

Module locks use MySQL connection-scoped advisory locks in production
and operating-system file locks on SQLite. Each database advisory-lock name is
derived from both the active database namespace and module-instance key, so
applications in separate databases on the same server do not block each other.
`MODULE_LOCK_WAIT_SECONDS` bounds acquisition (five seconds by default), but an
acquired lock does not expire while its operation is still running. Navigation
and dashboard projections default to 30 seconds through
`MODULE_PROJECTION_CACHE_SECONDS`; the version key is advanced atomically after
a lifecycle transaction commits or synchronization creates a missing
installation. A cache outage is recorded with a stable reason without
converting an already-committed transition into a failed response; the short
projection TTL remains the bounded recovery path.

`myapes:authorization-preflight` runs before migration and validates the
supported Phase A or retry-safe Phase B database state, OIDC discovery/PKCE,
LDAP connectivity, and the five preset immutable groups without printing
client, bind, member, or user data. `myapes:authorization-sync` repairs
code-owned metadata, exact mappings, provenanced grants, compatibility mirrors,
and the one-time session cutover. `myapes:authorization-check` performs the
read-only Phase B postcondition gate. Directory catalogue synchronization is
available for `manual` or `scheduled` sources. Both sources dispatch the same
unique job, so pending or running work is coalesced; the job has three bounded
attempts, bounded backoff and execution, and the database lease remains the
final catalogue-integrity guard. Queued attempts persist their framework UUID,
attempt number, and lease owner. A final resolver failure or hard timeout
transactionally finalizes only that correlated attempt, clears only its lease,
and advances the sanitized session generation idempotently. Repeated callbacks,
including callbacks for an already-terminal attempt, still clear a matching
stale lease but never disturb a newer owner. While a database
lease remains active, it is itself a secondary fail-closed generation signal.

Directory-backed sessions revalidate authorization at most every five minutes.
Removing all approved groups revokes directory provenance, advances the
authorization epoch, rotates remembered authentication, and signs the user out
without removing permitted local custom roles. Directory outages fail closed
without erasing stored authorization. Suspension, role changes, mapping
changes, and the v0.8.0 cutover use the same backend-independent reauthentication
boundary. Explicit staff logout clears the MyAPES session and forces a fresh
Cloudron credential prompt on the next Staff Login; it does not end other
Cloudron sessions because the provider does not publish a global logout
endpoint.

## Maintenance operations and recovery

Production uses Laravel's shared Redis-backed maintenance state:

```dotenv
APP_MAINTENANCE_DRIVER=cache
APP_MAINTENANCE_STORE=redis
```

An authenticated administrator or super-admin with
`admin.maintenance.manage` can use `/admin/maintenance` to activate or
deactivate maintenance. Activation requires a message and explicit
confirmation; an optional planned end is informational and never restores the
application automatically. Public requests and ordinary Staff requests are
blocked with the branded HTTP 503 response. The only recovery exceptions are
`/healthz`, `/staff/login`, `/staff/auth/login`, `/staff/auth/callback`, and the
three `/admin/maintenance` console and transition routes. Those exceptions do
not bypass authentication or permission checks: guests remain unauthenticated,
and ordinary Staff receive the same branded 503 response after signing in.

The Laravel maintenance store is authoritative. The `maintenance_windows`
table is its auditable history and recovery projection. Every console status
request reconciles interrupted transitions: native active state completes a
pending activation, native inactive state completes a pending deactivation,
and native maintenance without a current history row creates a bounded
system-reconciled record. Duplicate current history fails closed and requires
operator review. Failures retain only bounded codes and summaries; exception
details, credentials, and provider payloads are never persisted.

The production queue worker deliberately runs without `--force`. It pauses
while Laravel maintenance is active, queued Redis jobs remain durable, and
processing resumes after maintenance is deactivated. No queued job is discarded
by the maintenance console.

Deployment activation and rollback first probe the authoritative maintenance
state as `www-data`. Pre-existing operator maintenance is never overwritten and
is never lifted by deployment success or recovery. A deployment that entered
maintenance may lift only its own state. Redis-backed maintenance survives the
release symlink switch and application restart. A compatible rollback to code
without this Admin console still retains Laravel CLI recovery.

If the console cannot recover the application, run this exact argument-separated
Cloudron command from an authenticated operator shell:

```bash
cloudron --server "$CLOUDRON_FQDN" --token "$CLOUDRON_TOKEN" \
  exec --app "$CLOUDRON_APP_ID" -- \
  sudo -E -u www-data /usr/bin/php8.4 \
    /app/data/current/artisan up --no-interaction --no-ansi
```

After emergency CLI recovery, revisit `/admin/maintenance`; the console will
reconcile an active or deactivation-pending history row to `ended` without
putting the application back into maintenance.

### Forward-only rollback contract

Phase B removes `users.role` only after verifying the complete canonical Phase
A trigger definitions and mirror, installing the Phase B schema/guard, and
proving provenanced protected-role parity. Trigger names alone are never
trusted. `legacy_access_level` is retained strictly as the rollback mirror and
is not an application authorization source. The retained Phase B database
guard keeps protected provenance and effective pivots aligned for writes from
v0.7.1 code.

Cutover reconciliation is database-owned. The verified Phase B guard
self-assigns the current compatibility mirror so its insert/update triggers
remove only stale canonical protected sources, retain clean source identity
and timestamps, and preserve every pivot backed by another provenance source.
Migration reconciliation, exact source/pivot parity, and its cutover marker run
under one ordered user-lock transaction before the Phase A guard or
`users.role` is removed.

A roleless retry first requires the complete Phase B schema, exact trigger
definitions, and the existing cutover marker, then repeats reconciliation and
exact parity idempotently. A retry from the narrower Phase-A-guard-dropped
boundary reinstalls and verifies that guard before resuming role removal.
Authorization synchronization and the final integrity check use the same
state-then-user lock order. These one-time gates can briefly delay concurrent
account writes while the user set is reconciled; they do not require
maintenance mode and never make partially verified authorization active.

Code rollback is forward-only for the database: it atomically restores the
previous application release under `/app/data/releases` and its shared runtime
links, but never runs a down migration. The tested archive contains an exact
four-entry `DEPLOYMENT-CONTROLS.sha256` manifest for activation, rollback,
Apache, and launcher controls. Before any third-party action or package
dependency runs, a dependency-independent job reads those four blobs from the
exact Git revision and exports the trusted manifest digest. The deploy runner,
activation script, and rollback path all require that exact digest, exact fixed
paths, and complete-file hashes before executing or publishing a control.
Cloudron's LAMP startup normalizes ownership below `/app/data` to the application
user. The launcher therefore refuses to run Laravel while any protected path is
not root-controlled. Because the package sources the trusted launcher before
its recursive normalization and starts Apache afterward, the launcher accepts
only that exact normalization signature, restores and verifies ownership
synchronously, validates the launcher, Apache, and release bootstrap/cache
chains as ordinary canonical paths, starts Laravel workers, and then permits
Apache to start. Worker-log directories, files, and append handles are created
or opened only after privilege has dropped to `www-data`; pre-existing links
fail closed without a root process traversing application-writable storage. A
changed or incomplete package contract fails closed. The deployment restores
root ownership to `/app/data`, the release and shared-runtime parents, the
launcher, Apache configuration, package-generated runtime files, and every path
in the active and rollback releases except their explicit Laravel cache
directories. The shared environment remains owned by root and readable by the
application group; only Laravel cache and shared storage remain
application-owned and writable. Activation authenticates a
root-only control copy under `/run/myapes-deployment-controls/<sha>`. After the
restart, CI restores those ownership boundaries before reading the retained
archive, recreates the control copy, requires the externally exported manifest
digest and every complete-file hash, and verifies ownership and write access
before health acceptance. Rollback repeats the same extraction and
authentication immediately before use and refuses a previous release that lost
its root-owned immutable boundary. The staging archive is removed only after a
successful release verification or completed code rollback. Marker-preserving
altered content therefore fails before a control is executed or a code link
changes. The pre-deployment Cloudron backup remains the recovery boundary if
database recovery, rather than code rollback, is required.

Selective-media releases carry the exact tracked marker under `public/storage`.
Packaging verifies before and after archive creation that this marker is the
only `public/storage` member. The complete source and archive may contain only
ordinary directories and regular files: symbolic links and other filesystem
entry types are rejected globally, including under `bootstrap/cache`. Archive
validation also rejects absolute or backslash paths, control characters, raw or
normalized collisions (including file/directory trailing-slash aliases), empty
components, and dot or dot-dot aliases before extraction. Activation and
rollback accept only canonical semantic versions with string-safe comparisons,
verify the data, release, bootstrap/cache, runtime-control, shared-storage,
public, and avatar ancestor chains before any mutation or Artisan command,
accept only the marker plus an exact `avatars` link, refuse writable or
unexpected paths without traversing shared media, and never link
`pet-profiles`. A compatible
pre-v0.14 rollback target has no marker and may retain or create only its
historical full `public/storage` link after the module rollback check accepts
that target; a selective directory is not a valid legacy layout.

Before any rollback-path link or release/runtime-file mutation, the active
release enters maintenance and runs the read-only module compatibility check
against the exact target directory. A
target with `resources/data/module-runtime-contract.json` must support every
persisted installation. A target without that manifest is treated as the
v0.8.3 legacy contract and is representable only when exactly the five
legacy-visible instances remain enabled and no extra installation exists.
Disabled legacy-visible modules therefore deliberately block rollback to
v0.8.3; operators must re-enable them through the guarded lifecycle or retain
the current release and assess database recovery.

v0.13.0 deliberately installs `apes-cic:cases` as the sixth persisted module
instance while retaining the original five entries as the legacy-visible
baseline. v0.12.1 cannot represent that sixth installation, even when it is
disabled, so the unchanged compatibility checker fails closed with
`target_contract_unrepresentable`. Take and verify the normal pre-deployment
backup before migration. After the sixth installation exists, returning to
v0.12.1 requires restoration of the corresponding pre-deployment database
backup; code rollback alone is not a supported recovery path.

v0.14.0 synchronizes `shelter-rescue:tickets` as the seventh persisted module
installation while retaining the same five legacy-visible entries. A v0.13.1
target declares only six shipped instances and cannot represent that seventh
installation, even when it is disabled, so compatibility validation fails
closed with `target_contract_unrepresentable`. There is no supported
disable-or-delete workaround: operators must take and verify the normal
pre-deployment database backup, and restoration of that corresponding backup
is required to return the installation state to v0.13.1.

v0.15.0 synchronizes `pet-care-clinic:tickets` as the eighth persisted module
installation while retaining the same five legacy-visible entries. A v0.14.0
target declares only seven shipped instances and cannot represent that eighth
installation, even when it is disabled, so compatibility validation fails
closed with `target_contract_unrepresentable` without mutating module state.
There is no supported disable-or-delete workaround: operators must take and
verify the normal pre-deployment database backup, and restoration of that
corresponding backup is required to return the installation state to v0.14.0.

Code rollback enters Laravel maintenance mode before compatibility validation,
then acquires every code-owned module-instance lock so in-flight writes finish
before the representability snapshot. While requests remain quiesced, the
rollback target synchronizes and verifies its own authorization matrix before
the atomic link switch. A failure before the switch restores and verifies the
current release's authorization matrix before service can leave maintenance;
an unverifiable restoration remains fail-closed in maintenance mode. Database
migrations are never reversed by this code-rollback path.

A deliberate maintenance downgrade first requires Laravel maintenance mode and
then fails before schema mutation when any suspension or non-default
authorization epoch cannot be represented by Phase A. For a permitted
downgrade, remember tokens and every supported server-side session are
invalidated before Phase B-only fields are removed; unsupported session
backends fail closed.

### Deployment flow (every deployment request)

GitHub-authored Actions are pinned to reviewed full commit SHAs. The current
checkout, Node setup, and artifact transfer pins use Node 22
runtimes; version comments beside each pin make deliberate upgrades auditable.

1. A dependency-independent job fetches the exact Git revision with the built-in runner tools, reads the four deployment-control blobs directly from Git, and exports their fixed-path manifest digest before any action or package dependency can influence it.
2. The SQLite/package job checks out that revision, validates the append-only release history, runs the complete PHP and frontend suites, validates shell and PowerShell syntax, builds Vite assets, and creates an immutable production archive with exact `VERSION`, full-SHA `REVISION`, and deployment controls that must match the Git-derived digest.
3. An independent PHP 8.4 job creates a clean `myapes_test` database on MySQL 8.4 and runs the guarded authorization cutover plus module migration, synchronization, dependency, rollback, concurrent lifecycle/write-lock, catalogue, mapping, directory-role, and real PCNTL queue-timeout suites through `pdo_mysql`.
4. Only a successful `main` push proceeds. Cloudron creates the pre-deployment backup before any upload or activation.
5. The pinned Cloudron CLI uploads the archive into `/app/data/.deploy/<sha>` only after local verification of the externally exported control-manifest digest and all four complete control files.
6. The activation script publishes a root-only authenticated control copy under `/run`, extracts and validates the complete application payload under `/app/data/releases/<sha>`, rejects noncanonical release/bootstrap/cache or launcher/Apache paths, restores the shared `.env` and storage links, and verifies the tracked selective-media boundary plus its single shared `avatars` link as root before any application command. It creates shared runtime children as `www-data`, revalidates them before root hardening, and assigns protected runtime and release paths to root while restoring application ownership only to Laravel's cache and shared storage. It then forces and verifies `APP_ENV=production` and runs every Artisan step as `www-data` through that release:
   1. `optimize:clear`;
   2. `myapes:authorization-preflight --no-interaction --no-ansi`;
   3. `myapes:modules:preflight --no-interaction --no-ansi`;
   4. `migrate --force`;
   5. `myapes:modules:sync --no-interaction --no-ansi`;
   6. configuration, route, and view caches;
   7. `permission:cache-reset --no-interaction`;
   8. `myapes:directory-sync --source=manual --no-interaction --no-ansi`;
   9. `myapes:authorization-sync --no-interaction --no-ansi`;
   10. `permission:cache-reset --no-interaction`;
   11. `myapes:modules:check --no-interaction --no-ansi`;
   12. `myapes:authorization-check --no-interaction --no-ansi`; and
   13. the atomic `/app/data/current` switch.
   For an upgrade, the active release enters maintenance after both preflights
   and before the first shared-database mutation. The activation exit handler
   remains armed until the atomic switch. A pre-mutation failure reopens the
   active release directly; a post-mutation pre-switch failure first
   resynchronizes and verifies that release's authorization matrix and then
   reopens it. Failed restoration or reopening leaves maintenance active while
   preserving the original activation failure. First deployment and
   same-release activation skip this quiescence. Immediately after the atomic
   switch is marked committed, the new release leaves maintenance only when
   that deployment entered it. Pre-existing operator maintenance remains
   authoritative and active throughout the deployment.
   If activation fails, the workflow reauthenticates the tested controls and
   classifies the authoritative `current` and `previous` symlinks against the
   new and captured prior SHAs. A verified pre-switch failure leaves the
   recovered prior release active without rollback; a verified post-switch
   failure, including failure to leave maintenance, enters the authenticated
   exact-release rollback path. Missing prior identity or any ambiguous link
   state remains fail-closed with backup and staging evidence retained.
7. Cloudron restart is a separate operation after a successful switch. The LAMP package sources `/app/data/run.sh` before its exact recursive ownership-normalization command and starts Apache afterward. The trusted launcher intercepts that command, restores and verifies root ownership plus ordinary canonical launcher, Apache, bootstrap, and cache paths synchronously, prepares and opens worker logs only as `www-data`, starts the queue worker and scheduler, and permits Apache to serve `/app/data/current/public` only after the boundary is restored. A changed normalization signature, linked control/cache/log path, or attempted Apache start before restoration fails closed.
8. The deploy job restores root ownership for both immutable releases and the protected runtime parents before reading the retained archive. It then re-extracts the four fixed controls into root-only `/run`, verifies the external manifest digest and every file hash, and checks ownership plus the active/rollback release, launcher, Apache, cache, environment, and shared-storage write boundaries.
9. CI requires `/healthz` to return a valid semantic version equal to `VERSION`, a full 40-character SHA equal to `REVISION`, healthy dependencies, and a real boolean `maintenance` value that may be either `true` or `false`, then verifies the exact Cloudron OIDC authorization endpoint, callback, scopes, state, nonce, and PKCE S256 challenge. Rollback verification tolerates the maintenance field being absent only for a pre-v0.12 target.
10. A same-release retry prepares and verifies the immutable release idempotently without rewriting the previous-release pointer. Activation, restart, runtime-control, or verification failure for a new release may roll back only after the normalized recovery decision proves that `current` is the exact failed SHA and `previous` is the captured pre-activation SHA; any mismatch or unavailable prior identity fails closed. Rollback reauthenticates its control copy under `/run`, enters maintenance, drains durable module locks, verifies module representability, and synchronizes/checks the target authorization matrix before switching. A pre-switch failure restores the current matrix before lifting maintenance and is never sent through a stale rollback. A restored release is checked against the captured semantic version/full SHA, its version-compatible database/cache health payload, the production environment through a separate Cloudron Artisan check, and the OIDC PKCE contract. Deployment staging is removed only after accepted release health or a rollback whose environment, health, and OIDC verification all succeed.

`version` in `/healthz` is the human-facing semantic application version. `release` is the immutable deployment commit SHA; neither replaces the other.

Rotating MySQL, Redis, SMTP, and LDAP credentials are read from Cloudron-provided environment variables and are not copied into the shared `.env`. Laravel environment selection is explicitly pinned to production for Apache, activation commands, queue workers, and the scheduler.

## Security and audit controls

- **Audit log model** records security-sensitive events such as:
  - OIDC login/logout, authorization revocation, and permission denials
  - protected/custom role, directory mapping, suspension/reactivation, and synchronization decisions
  - sanitized Admin and assignment authorization denials without submitted identifiers
  - profile updates
  - ticket/case/consultation lifecycle updates
  - pet profile create/update actions
- **Audit retention control**:
  - Configure `AUDIT_LOG_RETENTION_DAYS` (default `180`)
  - Run `php artisan audit:prune` to remove expired audit records
  - The scheduler is configured to run pruning daily
- **Session hardening defaults** in `.env.example`:
  - `SESSION_ENCRYPT=true`
  - `SESSION_SECURE_COOKIE=true` in the template for HTTPS deployments; local bootstrap rewrites the copied `.env` to `false` for localhost QA
  - `SESSION_HTTP_ONLY=true`
- **Upload safeguards**:
  - Avatar and pet photo uploads are restricted to JPEG/PNG/WebP
  - File replacement removes superseded media files to reduce stale exposure
  - Pet photos stream inline only after authenticated domain and record authorization checks, with private no-store caching and MIME-sniffing disabled
  - Apache and the application both return 404 for direct `/storage/pet-profiles/*` requests; only avatars retain a public runtime link

## Data model highlights

- `users`: identity source, rollback-only legacy access mirror, authorization epoch, suspension state, and bounded directory-group snapshot
- `roles`, `permissions`, and Spatie pivots: one-guard protected/custom role and code-owned permission storage
- `role_sources`: application-owned provenance ledger kept in parity with effective role pivots by the retained Phase B database guard
- `authorization_states`: cutover/session markers, global authorization epoch, and the database-fenced directory synchronization lease
- `directory_groups`, `directory_group_role_mappings`, and `directory_sync_runs`: aggregate catalogue, exact mappings, sanitized synchronization history, and non-secret queue-attempt/lease correlation
- `maintenance_windows`: auditable activation/deactivation history, nullable actors, bounded failure state, and a unique nullable guard enforcing one current transition across SQLite and MySQL
- `module_installations`: durable per-sub-core shipped-module state with a monotonic transition version, transition timestamps, and sanitized actor account IDs; state is unique by `(sub_core_key, module_key)` and never stores executable class names
- `user_profiles`: shared profile/settings data
- `support_tickets` + `support_ticket_messages`: sub-core-discriminated Tickets with preserved identities, owner/staff conversations, and explicit public/internal staff replies
- `pet_profiles`: shared pet record model (`shelter` or `petcare` domain)
- `shelter_cases`: compatibility persistence identity for both pet-linked Shelter cases and pet-optional APES CIC cases, with sub-core, category, shared priority, widened status, and lifecycle timestamps
- `case_updates`: public or internal Case updates linked to the stable `shelter_cases` identity; update text is excluded from audit metadata
- `pet_care_consultations`: consultation lifecycle tracking
