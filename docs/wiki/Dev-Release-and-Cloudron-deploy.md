> This page is synced from `docs/wiki` in the repository. Edits made here are overwritten on the next sync; suggest changes through a pull request or in [Discussions (Docs and guides)](https://github.com/APESCIC/MyAPES.Account/discussions/categories/docs-and-guides).

# Release and Cloudron deploy

**Audience:** developers  
**Purpose:** how a change becomes a release on Cloudron. Source: [README: Release history](https://github.com/APESCIC/MyAPES.Account/blob/main/README.md#release-history-and-semantic-versioning) and [Cloudron deployment](https://github.com/APESCIC/MyAPES.Account/blob/main/README.md#cloudron-deployment-automation).

Work ships once per milestone: all the milestone's issues are completed on the milestone branch, then the branch goes to `main` in one merge commit, followed by one release and one deploy. See [Milestone shipping in AGENTS.md](https://github.com/APESCIC/MyAPES.Account/blob/main/AGENTS.md#shipping-and-versioning).

The milestone's version is the `vMAJOR.MINOR.PATCH` at the start of its GitHub milestone title, for example `v0.58.3 Dev: Laragon local development`. Branch, pull request, tag and release names all come from it, never from GitHub's milestone number. See [version-shipping.mdc](https://github.com/APESCIC/MyAPES.Account/blob/main/.cursor/rules/version-shipping.mdc).

## The milestone pull request
1. Prepend exactly one new record, at the milestone's version, to `resources/data/releases.json`:
   ```powershell
   php artisan myapes:changelog-prepare --type=<patch|minor|major> --title="Short public title" --issue=<n> --pr=<n>
   ```
   Pick the `--type` that produces the milestone's version (try `--dry-run` first). Replace every `TODO:` field.
2. Set `VERSION` and `resources/data/module-runtime-contract.json` `application_version` to the same version with `composer milestone:bump -- vX.Y.Z`.
3. Update the version-pinned tests (change log, health, rollback, release history).
4. Validate: `php artisan myapes:changelog-validate --base-ref=origin/main`.
5. Public notes never contain credentials, personal data or exploitable detail.

## After merge
Merge with a merge commit (no squash), only after Bambie has approved the milestone.

1. Wait for [test-cloudron.yml](https://github.com/APESCIC/MyAPES.Account/blob/main/.github/workflows/test-cloudron.yml) to pass on `main`.
2. Tag the merge commit `vX.Y.Z` and push it; `release.yml` publishes the GitHub Release. Then deploy that tag by hand: `gh workflow run deploy-cloudron.yml --ref vX.Y.Z` ([deploy-cloudron.yml](https://github.com/APESCIC/MyAPES.Account/blob/main/.github/workflows/deploy-cloudron.yml)).
3. The GitHub Release is titled `v{VERSION} <milestone name>` (for example `v0.60.0 Repository standards and Cloudron mailbox`), with tag `v{VERSION}`. The deploy refuses a tag without it.
4. Check `/healthz` and the change log at https://myaccount.myapes.me.uk/change-log.
5. The milestone closes by itself. After a successful deploy, [milestone-close.yml](https://github.com/APESCIC/MyAPES.Account/blob/main/.github/workflows/milestone-close.yml) closes any milestone whose issues are all closed (closing a finished epic first). A milestone where nothing shipped closes without a deploy. To check by hand, run `composer milestone:close-ready -- --dry-run`.

Deploy secrets live in the `cloudron-deploy` GitHub environment. Never print or commit them. Rollback is forward-only; see the README's maintenance section.

## Queue worker, migrations and health (from v0.62.0)
- **Migrations** run on every deploy (`activate-release.sh` runs `php artisan migrate --force` before the switch). v0.62.0 adds `api_clients`, `personal_access_tokens` and the webhook tables; nothing is dropped or renamed.
- **Queue worker:** `cloudron-run.sh` keeps one `artisan queue:work` and one `artisan schedule:work` running. Webhook deliveries are queued jobs (`App\Jobs\DeliverWebhook`) on that worker; the deploy restarts the app, so the worker picks up the new code.
- **Health:** `/healthz` reports `checks.queue`: `ok`, or `delayed` when a webhook delivery has waited more than 15 minutes past its due time (the worker has probably stopped). It is reported only and never fails the deploy check, which still needs `status: ok`, the new `version` and the merge commit as `release`.
- **After a deploy that touches webhooks,** check one delivery goes through: in Admin → Settings → Webhooks, add (or use) an endpoint and replay a recent delivery, or change a test record, then look for `Delivered` in the delivery log.
- **Keys:** the reCAPTCHA keys, TinyMCE key, API tokens and webhook signing secrets are set in Admin → Settings, never in this repository. Keep the real values in APES Vault ('API Keys & Tokens').
