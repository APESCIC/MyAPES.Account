---
name: ship-gate
description: Retired wait-for-permission gates. Use the release-and-deploy skill. The owner has ordered agents to commit, merge on green CI, and deploy.
---

# Ship-gate

The old stop-and-wait gates are retired. Follow `AGENTS.md` (**Definition of done**, **Completing work**) and `.cursor/skills/release-and-deploy/SKILL.md`.

Still end every report with Next actions and one recommended option. That block does not pause the definition of done unless a step needs a secret or a person.

Release metadata still uses `.cursor/skills/release-metadata/SKILL.md` in the same pull request, before merge. The GitHub Release is created by `.github/workflows/deploy-cloudron.yml` after a successful Cloudron deploy.

Commands that used to live in this skill (local verify, merge, `workflow_dispatch`, live `healthz`, and the release view) are in `.cursor/skills/release-and-deploy/SKILL.md`. Do not create the GitHub Release by hand unless that publish job failed.
