# Contributing to MyAPES Account

Thank you for helping. Please write in UK English, follow the [Code of Conduct](CODE_OF_CONDUCT.md), and report security issues as described in [SECURITY.md](SECURITY.md). Local development uses Laragon only; see [Local development with Laragon](docs/laragon.md). The [developer guide](docs/developer-guide.md) covers modules, plugins and testing.

## Before you start

Always start from the latest `main`, so you never build on old code. Before you pick up an issue or create a branch:

1. Run `git status`. If you have changes you haven't committed, stop and sort them out first. Don't stash, reset or throw them away to get past this step.
2. Get the latest code: `git fetch origin --prune`, then `git checkout main` and `git pull --ff-only origin main`.
3. Bring that code into the branch you'll work from. For the milestone branch, run `git checkout milestone/<slug>` then `git merge origin/main`. If it doesn't exist yet, create it from `main` and push it. If you're going back to an issue branch, check it out and run `git merge origin/main`.
4. Only then create your issue branch from the milestone branch, or carry on with the one you resumed.

If the pull or the merge fails, stop and ask for help. Don't force-push, `reset --hard` or rebase shared branches to get round it. Cursor agents follow the same steps from [`.cursor/rules/sync-before-work.mdc`](.cursor/rules/sync-before-work.mdc).

## Milestone versioning tools

Milestones are named and shipped by version, never by GitHub's milestone number: branch `milestone/v0-58-3-laragon-local-dev`, issue PR title `[v0.58.3] Fix login (#42)`, tag `v0.58.3`. The formats are in [`.cursor/rules/milestone-versioning.mdc`](.cursor/rules/milestone-versioning.mdc).

- `composer milestone:next` shows the next milestone's version and branch name; `composer milestone:pr-title -- <issue>` gives the PR title.
- `composer milestone:check-title -- "<title>"` and `composer milestone:check-branch -- "<branch>"` tell you what is wrong. The `milestone-version-check` CI job runs them on every pull request.
- To check your branch name before each push, run `git config core.hooksPath .githooks` once.

Cursor agents also use the skills in `.cursor/skills/` (`start-next-milestone`, `milestone-release-titles` and `/next-milestone`). See [AGENTS.md](AGENTS.md#milestone-versioning).

## How work ships

Work ships once per GitHub milestone, not once per change. There are four stages, in order:

1. **Complete the milestone.** Every issue in the milestone is finished and committed on the milestone branch (`milestone/<slug>`, created from an up-to-date `main`).
2. **One merge.** The milestone branch goes to `main` in one merge commit (no squash), after Bambie has approved it.
3. **Release.** One release for the milestone.
4. **Deploy.** One deploy for the milestone.

Nothing reaches `main`, and nothing is tagged, released or deployed, before stage 2. A hotfix can skip these stages only with Bambie's written approval on its issue.

The full rules, including the definition of done and release metadata, are in [AGENTS.md](AGENTS.md#milestone-shipping).
