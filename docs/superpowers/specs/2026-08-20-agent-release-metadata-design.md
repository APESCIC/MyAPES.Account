# Agent release metadata before deploy

**Date:** 2026-08-20  
**Issue:** [#67](https://github.com/APESCIC/MyAPES-Account/issues/67)

## Problem

Release metadata (`VERSION`, `resources/data/releases.json`, `resources/data/module-runtime-contract.json`) must be present before Cloudron deploy can succeed, but agents only had a one-line note in `AGENTS.md`. Feature PRs sometimes merged without a bump, requiring a follow-up release PR (#65 → #66).

## Decisions

| Decision | Choice |
| --- | --- |
| When to bump | Same PR as feature/fix, before merge |
| Mechanism | `myapes:changelog-prepare` (stub-then-fill) + agent narrative |
| CI changes | Extend validator only (manifest sync, reject `TODO:`) |
| Local verify | On PR branch before merge (`composer pre-pr-verify` or ship-gate steps) |
| Deploy | Manual `workflow_dispatch` after merge; Cloudron last after changelog metadata; GitHub Release created automatically on successful deploy |

## Flow

```
Feature/fix complete
  → myapes:changelog-prepare
  → agent replaces TODO: fields
  → myapes:changelog-validate --base-ref=origin/main
  → local dev verify on PR branch (pre-pr-verify)
  → same PR merges to main
  → (ask) confirm changelog metadata on main (this repo only)
  → (ask) deploy via workflow_dispatch (Cloudron last; pass app_version from VERSION)
  → verify live Change Log Hub (/change-log from releases.json)
  → verify GitHub Release {VERSION} Beta display title on deployed SHA (tag v{VERSION}; created by deploy workflow)
```

## Components

- **`ReleaseHistoryPreparer`** — semver bump, stub record, sync three metadata files, patch version-pinned tests
- **`myapes:changelog-prepare`** — artisan entrypoint with `--dry-run`
- **`ReleaseHistoryValidator`** — also checks manifest `application_version` and rejects scaffold `TODO:` text
- **Agent docs** — `AGENTS.md`, `.cursor/rules/release-metadata.mdc`, `.cursor/skills/release-metadata/SKILL.md`, ship-gate rule/skill

## Out of scope (original)

- Separate release PR workflow for metadata

## Later change (#167)

- Auto-deploy on green `main` removed; deploy is `workflow_dispatch` only
- Agents verify the MyAPES Account Change Log Hub after deploy; sibling website changelogs/hubs are out of scope

## Later change (#183)

- Deploy workflow stamps GitHub Deployments with `{VERSION} Beta` (no SHA in the title)
- Successful deploy publishes GitHub Release `{VERSION} Beta` (display name) with tag `v{VERSION}` on the deployed SHA; feature title remains in release body
- Issue milestones are one per minor line, titled with a full version (`v0.N.P Beta`: projected final patch while open, last shipped patch once closed) until beta exit
- One-time `scripts/github/rename-release-titles.sh` renames existing GitHub Release display titles to `{VERSION} Beta`

## Later change (#189)

- Local dev verify on the PR branch before commit/merge
- Post-merge gate order: changelog hubs → Cloudron deploy last → live hub and GitHub Release verification
