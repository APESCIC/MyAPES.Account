---
name: release-metadata
description: Prepare and validate release metadata before opening or updating a PR. Use when finishing feature, fix, or chore work that will merge to main, or when bumping VERSION, releases.json, or module-runtime-contract.json.
---

# Release metadata

Use this skill at the **end of work**, after implementation and tests pass, **before** opening or finalizing the pull request.

## Quick checklist

1. `php artisan myapes:changelog-prepare --type=... --title=... --issue=... --pr=...`
2. Edit `resources/data/releases.json` — replace every `TODO:` field in the head record
3. `git fetch origin && php artisan myapes:changelog-validate --base-ref=origin/main`
4. Commit release files in the **same PR** as the feature/fix

## Prepare command

```powershell
# Preview without writing files (still requires --pr)
php artisan myapes:changelog-prepare --dry-run --type=patch --title="Short public title" --issue=67 --pr=68

# Scaffold and sync files
php artisan myapes:changelog-prepare --type=patch --title="Short public title" --issue=67 --pr=68
```

| Option | Purpose |
| --- | --- |
| `--type=patch\|minor\|major` | Semver bump from current `VERSION` |
| `--title=` | Required public release title |
| `--issue=` | GitHub issue number for references |
| `--pr=` | Required pull request number (command fails without it) |
| `--channel=stable` | Release channel (default `stable`) |
| `--date=YYYY-MM-DD` | Release date (default today) |
| `--dry-run` | Show plan without writing |

The command writes:

- `VERSION`
- Prepended head record in `resources/data/releases.json`
- `resources/data/module-runtime-contract.json` → `application_version`
- Version-pinned strings in:
  - `tests/Feature/HealthAndThemeTest.php`
  - `tests/Feature/ModuleRollbackCompatibilityTest.php`
  - `tests/Feature/ReleaseHistoryCommandTest.php`
  - `tests/Feature/ChangeLogPageTest.php`

## Semver policy

- **patch** — backward-compatible bug fix or small compatible change
- **minor** — new backward-compatible capability
- **major** — breaking change (rare pre-1.0; prefer documenting breakage and bumping **minor** while pre-1.0)

## Narrative fields agents must fill

After prepare, replace all `TODO:` text in the head record:

- `summary`, `changes`, `affected_areas`
- `version_rationale`, `validation`, `known_limitations`
- `rollback`, `provenance`
- `references` (if prepare used a placeholder)

Validation rejects records that still contain `TODO:`.

## Public note safety

Do not include in release notes:

- Credentials or tokens
- Personal data
- Private operational identifiers
- Exploitable security detail
- Unnecessary infrastructure detail

## Validate

```powershell
git fetch origin
php artisan myapes:changelog-validate --base-ref=origin/main
```

Structural-only check (no append comparison):

```powershell
php artisan myapes:changelog-validate
```

## Pre-merge

Before pushing, run local dev verify (contract gate + bootstrap upgrade path):

```powershell
composer pre-pr-verify
```

Or the contract gate alone:

```powershell
composer pre-merge
```

## Local verify and deploy timing

Release metadata belongs in the pull request **before merge**. `myapes:changelog-prepare` needs `--pr`, so open the pull request first, then prepare and fill the `TODO:` fields.

On Windows, `composer pre-pr-verify` is the local check. On Linux without PowerShell, run the steps in `scripts/local/pre-merge.ps1`. The owner has ordered agents to merge when **Test MyAPES Core** is green and then deploy. See `.cursor/skills/release-and-deploy/SKILL.md`.

After merge, `.github/workflows/test-cloudron.yml` validates release history and runs tests. Deploy with `workflow_dispatch` on `.github/workflows/deploy-cloudron.yml`, passing `app_version` from `VERSION`. That workflow publishes the live Change Log Hub and the GitHub Release `{VERSION} Beta` / tag `v{VERSION}` on the deployed SHA. Sibling website changelogs are out of scope.

## Do not

- Open a follow-up release-only PR after merging feature work
- Edit or reorder published records in `releases.json`
- Merge without passing `myapes:changelog-validate --base-ref=origin/main`
