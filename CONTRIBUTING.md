# Contributing to MyAPES Account

Thank you for helping. Please write in UK English, follow the [Code of Conduct](CODE_OF_CONDUCT.md), and report security issues as described in [SECURITY.md](SECURITY.md). The [developer guide](docs/developer-guide.md) covers local setup and testing.

## How work ships

Work ships once per GitHub milestone, not once per change. There are four stages, in order:

1. **Complete the milestone.** Every issue in the milestone is finished and committed on the milestone branch (`milestone/<slug>`, created from `main`).
2. **One merge.** The milestone branch goes to `main` in one merge commit (no squash), after Bambie has approved it.
3. **Release.** One release for the milestone.
4. **Deploy.** One deploy for the milestone.

Nothing reaches `main`, and nothing is tagged, released or deployed, before stage 2. A hotfix can skip these stages only with Bambie's written approval on its issue.

The full rules, including the definition of done and release metadata, are in [AGENTS.md](AGENTS.md#milestone-shipping).
