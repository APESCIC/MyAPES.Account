# Contributing to MyAPES Account

Thank you for helping. Please write in UK English, follow the [Code of Conduct](CODE_OF_CONDUCT.md), and report security issues as described in [SECURITY.md](SECURITY.md). Local development uses Laragon only; see [Local development with Laragon](docs/laragon.md). The [developer guide](docs/developer-guide.md) covers modules, plugins and testing.

## Before you start

Run `powershell -NoProfile -ExecutionPolicy Bypass -File scripts\preview.ps1` before any work. It stops if you have uncommitted changes, pulls the latest code, merges `origin/main` into your branch (never a rebase), installs changed dependencies, and checks the Laragon preview at <http://myapes-account.test> serves that code through FastCGI. If the pull or the merge fails, stop and ask for help. Don't force-push, `reset --hard` or rebase shared branches to get round it.

## How work ships

Each milestone is named by the version at the start of its GitHub milestone title (for example `v0.60.0 Beta: Repository standards and Cloudron mailbox`), never by GitHub's milestone number, and ships once: all its issues are committed on one `milestone/vX-Y-Z-<slug>` branch behind one draft pull request, which is merged once with a merge commit, tagged and released as `vX.Y.Z`, and deployed. A hotfix can ship earlier only with Bambie's written approval on its issue.

The rule, with the names, the version fields, the flow and the hotfix route, is [`.cursor/rules/version-shipping.mdc`](.cursor/rules/version-shipping.mdc). The decisions behind it are in [AGENTS.md](AGENTS.md#shipping-and-versioning).

- `composer milestone:next` shows the next version, its branch and its pull request and release titles.
- `composer milestone:check-title -- "<title>"` and `composer milestone:check-branch -- "<branch>"` tell you what is wrong. The `milestone-version-check` CI job runs them on every pull request.
- To check your branch name before each push, run `git config core.hooksPath .githooks` once.
- Milestones close by themselves after the deploy: the `milestone-close` workflow runs `composer milestone:close-ready` once all of a milestone's issues are closed. Add `-- --dry-run` to see what it would do.
