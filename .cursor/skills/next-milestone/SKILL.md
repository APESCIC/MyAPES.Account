---
name: next-milestone
description: Show the next milestone's version, milestone branch name and title formats by running composer milestone:next.
disable-model-invocation: true
---

# /next-milestone

1. Run `composer milestone:next` in the repository root and show its output to the user as it is, including any `Warning:` lines.
2. If it fails, show the error and stop. A milestone whose title does not start with `vX.Y.Z` must be retitled before work starts.
3. Then show these formats, filled in with the printed version (example: milestone `v1.3.0 Beta: Reporting`, issue #42):

   | What | Format | Example |
   | --- | --- | --- |
   | Milestone branch | `milestone/vX-Y-Z-<slug>` | `milestone/v1-3-0-reporting` |
   | Issue branch | `<type>/<issue>-<slug>` | `chore/42-fix-login` |
   | PR title | `[vX.Y.Z] Summary (#issue)` | `[v1.3.0] Fix login (#42)` |
   | Tag | `vX.Y.Z` | `v1.3.0` |
   | Release title | `vX.Y.Z`, optionally with the label | `v1.3.0 Beta` |

4. Remind the user of the checks: `composer milestone:pr-title -- <issue>`, `composer milestone:check-title -- "<title>"` and `composer milestone:check-branch -- "<branch>"`.

See `.cursor/rules/milestone-versioning.mdc`.
