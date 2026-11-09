---
name: next-milestone
description: Show the next milestone's version, milestone branch name and title formats by running composer milestone:next.
disable-model-invocation: true
---

# /next-milestone

1. Run `composer milestone:next` in the repository root and show its output to the user as it is, including any `Warning:` lines.
2. If it fails, show the error and stop. A milestone whose title doesn't start with a valid `vMAJOR.MINOR.PATCH` must be retitled by the owner before work starts.
3. Show the "Names built from the version" table from `.cursor/rules/milestone-versioning.mdc`, with the example column filled in from the printed version and milestone title: milestone branch, milestone PR into `main`, issue PR into the milestone branch, release history, tag and GitHub Release title.
4. Remind the user of the checks: `composer milestone:pr-title -- <issue>`, `composer milestone:check-title -- "<title>"` and `composer milestone:check-branch -- "<branch>"`.
