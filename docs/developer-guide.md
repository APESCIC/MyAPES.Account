# Developer guide — Modules and Plugins

How to add a **Module** (organisation area) or **Plugin** (reusable feature) to MyAPES Account without editing Core. How Cursor agents start work, ship a release, and deploy is [AGENTS.md](../AGENTS.md).

Layer rules: [architecture.md](architecture.md). Decision record: [ADR 0001](adr/0001-core-modules-plugins.md). Inventory: [architecture-inventory.md](architecture-inventory.md).

## Quick start

```bash
# New organisation area
php artisan make:module community-hub --name="Community Hub" --prefix=/community

# New feature, enabled for APES CIC, depending on pet-profiles
php artisan make:plugin demo-board --name="Demo Board" --modules=apes-cic --depends=pet-profiles

composer dump-autoload
php artisan test --compact tests/Architecture
```

Neither command writes under `app/Core`. They scaffold the package tree, add a PSR-4 entry in `composer.json`, and register the service provider in `bootstrap/providers.php`.

## Adding a module

1. Run `php artisan make:module {slug} --name= --prefix=`.
2. `composer dump-autoload`.
3. Wire the hub route from `modules/{slug}/routes/web.php` into `routes/web.php` under the same auth/middleware stack as existing hubs (`/apes-cic`, `/shelter`, `/petcare`). **Keep the live prefix** you chose; do not rename existing prefixes.
4. List plugins this module composes in `modules/{slug}/module.php` → `plugins: ['tickets', …]`.
5. Seed / Admin enablement: organisation modules use `organisation_modules`; plugin enablement uses `module_installations` (see #288).
6. Confirm architecture tests stay green.

Permission convention for module-only abilities (rare): `{module}.{ability}`. Prefer plugin-scoped names.

## Adding a plugin

1. Run `php artisan make:plugin {slug} --name= --modules=a,b --depends=x`.
2. `composer dump-autoload`.
3. Require each generated route file from `routes/web.php` inside the matching module group (copy the pattern used by Tickets / Cases / Recruitment).
4. Add the slug to each composing module’s `module.php` → `plugins` list.
5. Declare cross-plugin dependencies with `PluginDependency` in the provider. Dependents may import only:
   - `Plugins\{Studly}\Contracts\*`
   - `Plugins\{Studly}\Models\*` (Eloquent relations)
   - `Plugins\{Studly}\Support\*`
6. Permissions stay `{module}.{plugin}.{ability}` (built via `PermissionNaming`). Do **not** invent `core.*` renames in this line.
7. Settings: use `PluginSettingsSchema` in the manifest (`websites_categories`, `recruitment_board`, or `supportsSettings: false`). Seed `lang/en_GB/plugin.php` keywords for Admin search (`searchKeywordsKey` / `PluginManifest::keywords()`).
8. Translations / keywords: see [localisation.md](localisation.md). Plugin `translationNamespace` + `lang/en_GB/plugin.php` (`name`, `description`, `keywords`) load via `PluginServiceProvider` (#272). Admin Plugins labels use `nameKey` / `descriptionKey` → `label()` / `descriptionLabel()`. Keywords power Admin search in #274.
9. Migrations are forward-only; historical tables keep their names (`shelter_cases` stays).
10. Vacancy model for Recruitment is `RecruitmentRole` — never Spatie `Role`, never an Access job role.

## Enablement

- **Module on/off:** `OrganisationModule` / Admin Modules.
- **Plugin per module:** `PluginEnablement` → `module_installations` keyed `{module}:{plugin}`.
- Public Recruitment board / apply toggles are **plugin settings**, not the same as staff manage chrome (staff uses module+plugin enabled state).

## Architecture tests (hard-fail)

`tests/Architecture/*` runs in CI and `php artisan test`. Rules:

| Rule | Enforced by |
| --- | --- |
| Core must not `use` Modules\* / Plugins\* | `StructureArchitectureHardFailTest` |
| Plugins must not import Modules\* | same |
| Plugin→plugin only via declared `PluginDependency` + public API | same |
| Module↛module imports | same |
| Modules import plugins only via Contracts\* | same |
| Legacy `ApesCic` / `Shelter` / `PetCare` controller dirs and `app/Modules/{Activity,Analytics,…}` stay empty of PHP | same |

String FQCNs in Core (morph map aliases) are allowed — they are not `use` imports.

## Release checklist

Every merge to `main` includes release metadata in the **same PR**:

```bash
php artisan myapes:changelog-prepare --type=patch --title="Short public title" --issue=N --pr=N
# Fill every TODO: in resources/data/releases.json head record
git fetch origin
php artisan myapes:changelog-validate --base-ref=origin/main
composer pre-pr-verify   # or ship-gate manual steps
```

Keep `VERSION`, `resources/data/releases.json`, and `resources/data/module-runtime-contract.json` → `application_version` in sync.

## Agent skill

Cursor agents: see `.cursor/skills/new-plugin/SKILL.md` for the generator workflow.
