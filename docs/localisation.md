# Localisation

MyAPES Account ships **UK English** as the only locale for the Language line (`v0.38.x`). Primary locale is `en_GB`; fallback is `en`.

Related: product wording in [glossary.md](glossary.md) and `lang/en_GB/terms.php` ([#267](https://github.com/APESCIC/MyAPES-Account/issues/267)); deprecated synonyms in `config/glossary.php`. Hard-coded string inventory for extraction children: [i18n-inventory.md](i18n-inventory.md) ([#266](https://github.com/APESCIC/MyAPES-Account/issues/266)). Architecture layer names stay in [architecture.md](architecture.md).

## Hard-coded string inventory

Run the scanner (offline, no network):

```bash
php artisan lang:inventory --write
```

- Writes [docs/i18n-inventory.md](i18n-inventory.md) (committed checklist) and `storage/app/lang-inventory.json` (machine copy under storage).
- Logic: `App\Services\Localisation\HardCodedStringScanner` + allow-list in `config/lang_inventory.php`.
- Extraction children link to sections:
  - [#268](https://github.com/APESCIC/MyAPES-Account/issues/268) → `docs/i18n-inventory.md#public-268` (also Recruitment/Pet Profiles public plugin sections)
  - [#269](https://github.com/APESCIC/MyAPES-Account/issues/269) → `docs/i18n-inventory.md#apes-cic-staff-269` (+ Cases / Tickets / Consultations / Recruitment staff)
  - [#270](https://github.com/APESCIC/MyAPES-Account/issues/270) → `docs/i18n-inventory.md#admin-shell-270`
  - [#271](https://github.com/APESCIC/MyAPES-Account/issues/271) → `docs/i18n-inventory.md#auth-emails-flash-validation-271`
- [#276](https://github.com/APESCIC/MyAPES-Account/issues/276) reuses the same scanner and allow-list. CI runs `php artisan lang:check --fail-on-missing` so **missing keys fail the build**; unused keys are reported only (non-blocking).

## Translation key check (CI)

```bash
php artisan lang:check
php artisan lang:check --fail-on-missing
```

- Logic: `App\Services\Localisation\TranslationKeyChecker` (+ hard-coded count via `#266` scanner).
- Config: `config/lang_check.php`.
- Wired into `.github/workflows/test-cloudron.yml` with **`--fail-on-missing`** (Wave 4 / #276). Unused-key output stays informational and does not fail CI.
- Dynamic keys (interpolated segments) are reported as missing by the scanner, so write literal keys in views (for example a `match` over known values) and keep enum/status label maps in `terms.php` behind `WorkflowLabels`, covered by dedicated Feature tests.

## Locale configuration

| Setting | Value | Where |
| --- | --- | --- |
| `APP_LOCALE` | `en_GB` | `.env.example`, `.env.local.example`, `.env.laragon.example`, `scripts/deploy/production.env.example`, `phpunit.xml`, Cloudron shared `.env` (upserted on activate) |
| `APP_FALLBACK_LOCALE` | `en` | Same |
| `APP_FAKER_LOCALE` | `en_GB` | Same |
| Config defaults | `en_GB` / `en` / `en_GB` | `config/app.php` |
| `supported_locales` | `en_GB` → English (UK) only | `config/app.php` (#275) |

`html lang` uses `str_replace('_', '-', app()->getLocale())` so the document language is `en-GB` when the app locale is `en_GB`.

### User locale preference (#275)

- `users.locale` string(10), default `en_GB`, set by factory and migration backfill.
- `SetLocale` middleware (web group): authenticated `user->locale` → session `locale` → `config('app.locale')`. Unsupported values fall back to `en_GB`.
- `User` implements `HasLocalePreference` via `preferredLocale()` for queued mail/notifications.
- `<x-locale-switcher>` on profile and footer renders **only when** `count(supported_locales) > 1`. Guest `POST /locale` (`locale.store`) is CSRF-protected and returns 404 while a single locale is configured.
- Cloudron OIDC and directory sync update identity fields only — they must **not** overwrite `users.locale`.

Do **not** rename stored permission strings or live URLs for wording. Display labels only move into lang files.

## File layout

Prefer **grouped PHP files** under `lang/en_GB/`:

```
lang/
  en_GB/          # primary (UK spelling)
    auth.php
    pagination.php
    passwords.php
    validation.php
    terms.php         # canonical product labels (#267)
    # later waves: nav.php, admin.php, seo.php, flash.php, …
  en/             # fallback (framework defaults; keep in sync structurally)
```

Plugin packages add their own trees (loaded in [#272](https://github.com/APESCIC/MyAPES-Account/issues/272)):

```
plugins/<slug>/lang/en_GB/plugin.php
modules/<slug>/lang/en_GB/…   # rare; prefer plugin or Core keys
```

JSON (`lang/en_GB.json`) is only for short one-off phrases if needed. Prefer grouped PHP so CI key checks ([#276](https://github.com/APESCIC/MyAPES-Account/issues/276)) can find keys.

## Key naming

Pattern: `{file}.{area}.{thing}` with **snake_case** segments.

Examples:

- `nav.dashboard`
- `nav.admin`
- `recruitment.public.apply`
- `recruitment.staff.roles.create`
- `admin.plugins.title`
- `flash.saved`
- `validation.attributes.email` (framework)

Rules:

- No full sentences as keys
- No HTML in values — use `:placeholders` and Blade for markup
- Keys describe meaning, not the English sentence

## Helpers

| Helper | Use |
| --- | --- |
| `__('nav.dashboard')` | PHP and Blade |
| `@lang('…')` | Blade only |
| `trans('…')` | Same as `__()` |
| `trans_choice('recruitment.applications.count', $n)` | Plurals |

### Plurals

```php
// lang/en_GB/recruitment.php
'applications' => [
    'count' => '{0} No applications|{1} :count application|[2,*] :count applications',
],
```

```blade
{{ trans_choice('recruitment.applications.count', $count) }}
```

### Placeholders

```php
'greeting' => 'Hello, :name.',
```

```blade
{{ __('mail.greeting', ['name' => $user->name]) }}
```

### Workflow labels

Statuses, priorities and similar stored values (`in_progress`, `not_neutered`) are never shown raw. Use `App\Support\WorkflowLabels`, which reads `terms.<group>.<value>` from `lang/en_GB/terms.php` and falls back to a headline of the value when a key is missing. Groups: `status`, `priority`, `case_type`, `recruitment_category`, `sex`, `neutering_status` ([glossary](glossary.md#workflow-labels)).

```blade
<span class="status">{{ \App\Support\WorkflowLabels::status($ticket->status) }}</span>
{{ \App\Support\WorkflowLabels::priority($ticket->priority) }}

@foreach(\App\Support\WorkflowLabels::options('status', $statuses) as $value => $label)
    <option value="{{ $value }}" @selected($current === $value)>{{ $label }}</option>
@endforeach
```

Keep option `value`s and validation rules on the stored values; only the visible text changes. Add new values to `terms.php` (both `en_GB` and `en`) and the glossary in the same change.

Convention:

- **Title Case** for every label: capitalise each word, but keep short joining words such as *on*, *of* and *and* lower case (**Waiting on User**, **Not Neutered**, **Code Not Shipped**).
- **One source**: do not add per-controller label maps or `str_replace('_', ' ', …)` in Blade. Plugin screens, emails and dashboard items all call `WorkflowLabels`.
- **Emails** pass the label, not the stored value, into `:status` / `:priority` placeholders.
- **Admin chrome** that is not a workflow value (module code status, enable/disable actions, user and group status) uses literal `admin.*` keys, for example `admin.plugins.code_status.*`.
- **Search synonyms** for these labels live in `admin.search.keywords.*` and `{ns}::plugin.keywords` only; there is no Language editor.

## Plugin namespaces

Each plugin declares `PluginManifest::$translationNamespace` (Structure). [#272](https://github.com/APESCIC/MyAPES-Account/issues/272) loads package trees via `PluginServiceProvider::loadTranslationsFrom`:

| Namespace | Example |
| --- | --- |
| `cases::` | `__('cases::plugin.name')` |
| `tickets::` | `__('tickets::ui.index.blade.status')` (column heading; status values use `WorkflowLabels`) |
| `recruitment::` | `__('recruitment::roles.title')` |
| `consultations::` | `__('consultations::plugin.name')` |
| `pet_profiles::` | `__('pet_profiles::plugin.keywords')` |

Standard seed file: `plugins/<slug>/lang/en_GB/plugin.php` with `name`, `short_name`, `description`, and `keywords`. Manifest `nameKey` / `descriptionKey` default to `{ns}::plugin.name` / `{ns}::plugin.description`; Admin → Plugins resolves them through `PluginManifest::label()` / `descriptionLabel()` (slug fallback if a key is missing).

Admin shell search (#274) reads `PluginManifest::$searchKeywordsKey` (default `{ns}::plugin.keywords`) via `PluginManifest::keywords()`, plus Core section keys under `admin.search.keywords.*` and optional `{ns}::settings.keywords` for settings pages. Results are permission-gated.

App-level overrides use Laravel’s vendor path: `lang/vendor/{namespace}/en_GB/plugin.php` (for example `lang/vendor/recruitment/en_GB/plugin.php`).

Translations load when the plugin package provider boots, independent of enablement — disabled plugins do not break other pages.

Core / shared strings stay in `lang/en_GB/*.php` without a package prefix.

## Dates and numbers

- Set Carbon from the app locale: `Carbon::setLocale(app()->getLocale())` when formatting for display
- Prefer UK date format `j F Y` (for example `29 September 2026`) in user-facing copy
- Postcodes and phone numbers follow UK conventions in validation attributes

## Validation attributes

Friendly attribute names live in `lang/en_GB/validation.php` → `attributes` (for example `email` → “email address”, `postcode` → “postcode”). Add common form fields there as screens are extracted.

## Out of scope for this line

- Second locales (for example Welsh content) — #275 only prepares the preference pipe
- Permission catalogue key rewrites or live URL renames for wording
