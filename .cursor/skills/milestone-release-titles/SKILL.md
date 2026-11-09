---
name: milestone-release-titles
description: At release time, derive the release history (changelog heading or record), the tag and the GitHub Release title from the one milestone version, and check them with composer milestone:check-release before tagging. Use when preparing a release, writing a changelog heading or release record, tagging vX.Y.Z, creating a GitHub Release or reviewing release names.
---

# Milestone release titles

Follows `.cursor/rules/milestone-versioning.mdc`: the release history, tag and GitHub Release title all come from the same milestone version. The "Names built from the version" table in that rule gives this repository's formats; the section names below are the rule's.

## Steps

1. **Get the version.** `composer milestone:next -- --version-only` prints the version being released (for example `v1.3.0`). It must be the version of the milestone you are shipping. If not, or if that version is already taken on `main`, stop and report to the owner.
2. **Write the release history** in the rule's "Release history" format, and set the "Version fields" once, in the milestone PR into `main`.
3. **Check before tagging**, passing the tag, the GitHub Release title and, where the repository has one, the changelog heading:

   ```bash
   composer milestone:check-release -- v1.3.0 "v1.3.0 Beta"
   composer milestone:check-release -- v1.3.0 "v1.3.0" --changelog "## [1.3.0] - 2026-11-09"
   ```

   It fails, with the reason, when the tag is not `vX.Y.Z` or doesn't match the milestone, or when the title or heading carries another version or a milestone number.
4. **Tag and release** with exactly the checked values, in the way the rule says for this repository (some repositories create the tag or the Release in a workflow; don't create those by hand).

## Never

- Name a tag or release after GitHub's milestone number (`Milestone 7`, `[#7]`, `M7`, `/milestone/7`).
- Tag a version that differs from the release history or the milestone title.
- Rewrite past tags, releases or published release-history records.
