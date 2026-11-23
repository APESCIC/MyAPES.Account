# Hard-coded UI string inventory

Generated for [#266](https://github.com/APESCIC/MyAPES-Account/issues/266). This is a checklist for Language extraction children — **do not** treat it as a mandate to extract everything in one PR.

Regenerate:

```bash
php artisan lang:inventory --write
```

Scanner: `App\Services\Localisation\HardCodedStringScanner` (reusable by [#276](https://github.com/APESCIC/MyAPES-Account/issues/276)).
Allow-list: `config/lang_inventory.php` → `allowlist`.
Machine JSON (gitignored under storage): `storage/app/lang-inventory.json`.

## Section index (extraction children)

| Extraction issue | Inventory section | Anchor |
| --- | --- | --- |
| [#268](https://github.com/APESCIC/MyAPES-Account/issues/268) Public | [Public](#public-268) + [Plugin: Recruitment](#plugin-recruitment) (public views) + [Plugin: Pet Profiles](#plugin-pet-profiles) | `docs/i18n-inventory.md#public-268` |
| [#269](https://github.com/APESCIC/MyAPES-Account/issues/269) APES CIC staff | [APES CIC staff](#apes-cic-staff-269) + [Cases](#plugin-cases) + [Tickets](#plugin-tickets) + [Consultations](#plugin-consultations) + Recruitment staff views | `docs/i18n-inventory.md#apes-cic-staff-269` |
| [#270](https://github.com/APESCIC/MyAPES-Account/issues/270) Admin shell | [Admin shell](#admin-shell-270) | `docs/i18n-inventory.md#admin-shell-270` |
| [#271](https://github.com/APESCIC/MyAPES-Account/issues/271) Auth / mail / flash | [Auth / emails / notifications / flash / validation](#auth-emails-flash-validation-271) | `docs/i18n-inventory.md#auth-emails-flash-validation-271` |

## Totals

| Area | Count |
| --- | ---: |
| [Public](#public-268) | 4 |
| [APES CIC staff](#apes-cic-staff-269) | 0 |
| [Admin shell](#admin-shell-270) | 0 |
| [Auth / emails / notifications / flash / validation](#auth-emails-flash-validation-271) | 0 |
| [Plugin: Cases](#plugin-cases) | 0 |
| [Plugin: Tickets](#plugin-tickets) | 0 |
| [Plugin: Recruitment](#plugin-recruitment) | 0 |
| [Plugin: Consultations](#plugin-consultations) | 0 |
| [Plugin: Pet Profiles](#plugin-pet-profiles) | 0 |
| [Other / unclassified](#other-unclassified) | 0 |
| **All (inventory)** | **4** |
| Allow-listed (omitted below) | 111 |

_Generated at 2026-11-23T18:03:32+00:00_

<a id="public-268"></a>

## Public

Path anchor for children: `docs/i18n-inventory.md#public-268` — extraction [#268](https://github.com/APESCIC/MyAPES-Account/issues/268).

| File | Line | Kind | Snippet | Suggested key | Target |
| --- | ---: | --- | --- | --- | --- |
| `resources/views/components/critter.blade.php` | 10 | `blade.text` | class('critter'); | `nav.critter.class_critter` | `lang/en_GB/nav.php` |
| `resources/views/components/theme-switcher.blade.php` | 3 | `blade.text` | false]; $skins = config('themes.skins', []); $modes = config('themes.modes', ['light', 'dark']); $currentSkin = $appearance['skin'] ?? 'glassy'; $currentMode =… | `nav.theme-switcher.false_skins_config_themes_skins_modes_co` | `lang/en_GB/nav.php` |
| `resources/views/layouts/app.blade.php` | 2 | `blade.text` | false]) | `seo.app.false` | `lang/en_GB/seo.php` |
| `resources/views/vendor/mail/html/header.blade.php` | 6 | `blade.attribute.alt` | Laravel Logo | `nav.header.laravel_logo` | `lang/en_GB/nav.php` |

**Section total:** 4

<a id="apes-cic-staff-269"></a>

## APES CIC staff

Path anchor for children: `docs/i18n-inventory.md#apes-cic-staff-269` — extraction [#269](https://github.com/APESCIC/MyAPES-Account/issues/269).

_No hard-coded findings in this area (or all allow-listed)._

<a id="admin-shell-270"></a>

## Admin shell

Path anchor for children: `docs/i18n-inventory.md#admin-shell-270` — extraction [#270](https://github.com/APESCIC/MyAPES-Account/issues/270).

_No hard-coded findings in this area (or all allow-listed)._

<a id="auth-emails-flash-validation-271"></a>

## Auth / emails / notifications / flash / validation

Path anchor for children: `docs/i18n-inventory.md#auth-emails-flash-validation-271` — extraction [#271](https://github.com/APESCIC/MyAPES-Account/issues/271).

_No hard-coded findings in this area (or all allow-listed)._

<a id="plugin-cases"></a>

## Plugin: Cases

Path anchor for children: `docs/i18n-inventory.md#plugin-cases` — extraction [#269](https://github.com/APESCIC/MyAPES-Account/issues/269).

_No hard-coded findings in this area (or all allow-listed)._

<a id="plugin-tickets"></a>

## Plugin: Tickets

Path anchor for children: `docs/i18n-inventory.md#plugin-tickets` — extraction [#269](https://github.com/APESCIC/MyAPES-Account/issues/269).

_No hard-coded findings in this area (or all allow-listed)._

<a id="plugin-recruitment"></a>

## Plugin: Recruitment

Path anchor for children: `docs/i18n-inventory.md#plugin-recruitment` — extraction [#268](https://github.com/APESCIC/MyAPES-Account/issues/268).

_No hard-coded findings in this area (or all allow-listed)._

<a id="plugin-consultations"></a>

## Plugin: Consultations

Path anchor for children: `docs/i18n-inventory.md#plugin-consultations` — extraction [#269](https://github.com/APESCIC/MyAPES-Account/issues/269).

_No hard-coded findings in this area (or all allow-listed)._

<a id="plugin-pet-profiles"></a>

## Plugin: Pet Profiles

Path anchor for children: `docs/i18n-inventory.md#plugin-pet-profiles` — extraction [#268](https://github.com/APESCIC/MyAPES-Account/issues/268).

_No hard-coded findings in this area (or all allow-listed)._

<a id="other-unclassified"></a>

## Other / unclassified

Path anchor for children: `docs/i18n-inventory.md#other-unclassified`.

_No hard-coded findings in this area (or all allow-listed)._

## Allow-list / false positives

Configured in `config/lang_inventory.php`. Matches are omitted from the tables above so extraction PRs stay focused. CI (#276) should load the same config when warning on new hard-coded strings.

Common categories: HTTP method tokens, digit-only snippets, HTML entities, decorative glyphs, long path/FQCN-like tokens without spaces.

## Design notes for #276

1. Call `HardCodedStringScanner::scan()` (or `inventoryFindings()`).
2. Prefer report-only warnings on **changed files** until extraction waves finish.
3. Re-use `config/lang_inventory.php` allow-list — do not fork a second list.
4. Optional: diff against committed JSON snapshot when one is published for CI.
