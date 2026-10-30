> This page is synced from `docs/wiki` in the repository. Edits made here are overwritten on the next sync; suggest changes through a pull request or in [Discussions (Docs and guides)](https://github.com/APESCIC/MyAPES.Account/discussions/categories/docs-and-guides).

# Testing and CI

**Audience:** developers  
**Purpose:** what runs on every pull request and how to run it locally.

## Locally
- `composer test` – full PHPUnit suite (SQLite)
- `php artisan test --compact tests/Architecture` – layer rules
- `php artisan lang:check --fail-on-missing` – missing translation keys
- `php artisan myapes:changelog-validate --base-ref=origin/main` – release history
- `composer pre-merge` – the pre-merge checks

## On GitHub
| Workflow | What it checks |
|---|---|
| [test-cloudron.yml](https://github.com/APESCIC/MyAPES.Account/blob/main/.github/workflows/test-cloudron.yml) | Release history, deploy scripts, frontend tests, translations, PowerShell scripts, build, PHPUnit, packaging; MySQL 8.4 authorisation contract |
| [codeql.yml](https://github.com/APESCIC/MyAPES.Account/blob/main/.github/workflows/codeql.yml) | CodeQL for Actions and JavaScript |
| Semgrep, Socket, Snyk | Security and dependency scans on each PR |

## Rules
- Every action is pinned to a full commit SHA with a version comment.
- Tests use seeded fake data only; never real people, pets or cases.
- Every PR bumps the version (see [[Release and Cloudron deploy|Dev-Release-and-Cloudron-deploy]]), or the SQLite job fails.
