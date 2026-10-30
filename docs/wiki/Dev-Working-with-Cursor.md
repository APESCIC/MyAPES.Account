> This page is synced from `docs/wiki` in the repository. Edits made here are overwritten on the next sync; suggest changes through a pull request or in [Discussions (Docs and guides)](https://github.com/APESCIC/MyAPES.Account/discussions/categories/docs-and-guides).

# Working with Cursor

**Audience:** developers  
**Purpose:** the agent workflow used for this repository.

## Flow
1. A report is approved before anything is added to GitHub.
2. Issues: title prefix `[v0.N.x] NN`, labels `area:admin`, `area:public`, `area:auth`, `area:tests` or `area:cloudron`, assignee apescic-director, added to a milestone.
3. Cursor works on a branch, updates the issue as it goes and opens a PR with a release record.
4. CI must pass; local verify on the PR branch (`composer pre-pr-verify`).
5. Merge, then deploy to Cloudron (see [[Release and Cloudron deploy|Dev-Release-and-Cloudron-deploy]]), then verify live.

## Conventions
- UK English, plain wording.
- Never print or commit passwords, keys or tokens.
- Recruitment uses `RecruitmentRole`, never Spatie `Role`.
- Wiki pages are edited in `docs/wiki` through a PR, never in the wiki itself.

> The agent rules file (AGENTS.md / Cursor rules) is the source of truth. This page will link to it once its location on `main` is confirmed.
