---
name: milestone-release-titles
description: At release time, derive the changelog or release-history heading, the tag and the release title from the one milestone version, and check them with composer milestone:check-release before tagging. Use when preparing a release, writing a changelog heading, tagging vX.Y.Z, creating a GitHub Release or reviewing release names.
---

# Milestone release titles

Follows `.cursor/rules/milestone-versioning.mdc`: the changelog heading, tag and release title all come from the same milestone version.

## Steps

1. **Get the version.** `composer milestone:next -- --version-only` prints the version being released (for example `v1.3.0`). It must be the version of the milestone you are shipping; if not, stop and ask.
2. **Write the release history** using the version without the `v`.
   - In this repo: one new head record in `resources/data/releases.json` (`php artisan myapes:changelog-prepare`, see "Release metadata" in `AGENTS.md`). `deploy-cloudron.yml` publishes the GitHub Release after the deploy, so check the title it will use.
3. **Check before tagging**, passing the tag, the release title and the changelog heading you wrote:

   ```bash
   composer milestone:check-release -- v1.3.0 "v1.3.0 Beta" --changelog "## [1.3.0] - 2026-11-09"
   ```

   It fails, with the reason, when the tag is not `vX.Y.Z`, does not match the milestone, or the release title or heading carries another version or a milestone number.
4. **Tag and release** with exactly the checked values: tag `vX.Y.Z`; release title `vX.Y.Z`, optionally followed by the milestone label (`v1.3.0 Beta`).

## Never

- Name a tag or release after GitHub's milestone number (`Milestone 7`, `[#7]`, `M7`).
- Tag a version that differs from the changelog heading or the milestone title.
- Rewrite past tags, releases or published release-history records.
