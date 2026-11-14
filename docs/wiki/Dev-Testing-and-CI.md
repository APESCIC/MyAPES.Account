> This page is synced from `docs/wiki` in the repository. Edits made here are overwritten on the next sync; suggest changes through a pull request or in [Discussions (Docs and guides)](https://github.com/APESCIC/MyAPES.Account/discussions/categories/docs-and-guides).

# Testing and CI

**Audience:** developers  
**Purpose:** what runs on every pull request and how to run it locally.

## Locally
Run these in your Herd site (see [[Local setup (Herd)|Dev-Local-setup-Herd]]); demo data for manual checks comes from `composer demo:reset`.
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
| [dependency-review.yml](https://github.com/APESCIC/MyAPES.Account/blob/main/.github/workflows/dependency-review.yml) | Fails a pull request that adds a dependency with a known high or critical vulnerability |
| [release.yml](https://github.com/APESCIC/MyAPES.Account/blob/main/.github/workflows/release.yml) | Publishes the GitHub Release when a `vX.Y.Z` tag is pushed |

## Rules
- Every action is pinned to a full commit SHA with a version comment.
- Tests use seeded fake data only; never real people, pets or cases.
- Every PR into `main` bumps the version, or the SQLite job fails. Work ships once per milestone, so that is one PR and one bump per milestone (see [[Release and Cloudron deploy|Dev-Release-and-Cloudron-deploy]]).
