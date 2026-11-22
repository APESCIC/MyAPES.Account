> This page is synced from `docs/wiki` in the repository. Edits made here are overwritten on the next sync; suggest changes through a pull request or in [Discussions (Docs and guides)](https://github.com/APESCIC/MyAPES.Account/discussions/categories/docs-and-guides).

# Release and Cloudron deploy

**Audience:** developers  
**Purpose:** how a change becomes a release on Cloudron. Source: [README: Release history](https://github.com/APESCIC/MyAPES.Account/blob/main/README.md#release-history-and-semantic-versioning) and [Cloudron deployment](https://github.com/APESCIC/MyAPES.Account/blob/main/README.md#cloudron-deployment-automation).

Work ships once per milestone: all the milestone's issues are completed on the milestone branch, then the branch goes to `main` in one merge commit, followed by one release and one deploy. See [Milestone shipping in AGENTS.md](https://github.com/APESCIC/MyAPES.Account/blob/main/AGENTS.md#shipping-and-versioning).

The milestone's version is the `vMAJOR.MINOR.PATCH` at the start of its GitHub milestone title, for example `v0.63.1 Dev: Laravel Herd local development`. Branch, pull request, tag and release names all come from it, never from GitHub's milestone number. See [version-shipping.mdc](https://github.com/APESCIC/MyAPES.Account/blob/main/.cursor/rules/version-shipping.mdc).

## The milestone pull request
1. Scaffold the milestone release with `php artisan myapes:changelog-prepare` (adds `changelog/releases/vX.Y.Z.json`, sets `VERSION` and `application_version`, and regenerates `resources/data/releases.json`):
   ```powershell
   php artisan myapes:changelog-prepare --type=<patch|minor|major> --title="Short public title" --issue=<n> --pr=<n>
   ```
   Pick the `--type` that produces the milestone's version (try `--dry-run` first). Edit the new per-release file only; do not hand-edit `resources/data/releases.json`.
2. Replace every `TODO:` in `changelog/releases/vX.Y.Z.json`.
3. Update the version-pinned tests (change log, health, rollback, release history).
4. Validate: `php artisan myapes:changelog-validate --base-ref=origin/main`, then `composer milestone:bump -- vX.Y.Z --check`.
5. Public notes never contain credentials, personal data or exploitable detail. There is no Admin release editor; APES Newsroom is the interaction reference for the hub UI only.

## After merge
Merge with a merge commit (no squash), only after Bambie has approved the milestone.

1. Wait for [test-cloudron.yml](https://github.com/APESCIC/MyAPES.Account/blob/main/.github/workflows/test-cloudron.yml) to pass on `main`.
2. Tag the merge commit `vX.Y.Z` and push it; `release.yml` publishes the GitHub Release. Then deploy that tag by hand: `gh workflow run deploy-cloudron.yml --ref vX.Y.Z` ([deploy-cloudron.yml](https://github.com/APESCIC/MyAPES.Account/blob/main/.github/workflows/deploy-cloudron.yml)).
3. The GitHub Release is titled `v{VERSION} <milestone name>` (for example `v0.60.0 Repository standards and Cloudron mailbox`), with tag `v{VERSION}`. The deploy refuses a tag without it.
4. Check `/healthz` and the change log at https://myaccount.myapes.me.uk/change-log.
5. The milestone closes by itself. After a successful deploy, [milestone-close.yml](https://github.com/APESCIC/MyAPES.Account/blob/main/.github/workflows/milestone-close.yml) closes any milestone whose issues are all closed (closing a finished epic first). A milestone where nothing shipped closes without a deploy. To check by hand, run `composer milestone:close-ready -- --dry-run`.

Deploy secrets live in the `cloudron-deploy` GitHub environment. Never print or commit them. Rollback is forward-only; see the README's maintenance section.

## Change log on Cloudron
- Canonical release copy lives in git under `changelog/releases/vX.Y.Z.json`. Production sets `MYAPES_CHANGELOG_USE_DATABASE=true` so `/change-log` reads the projected `releases` table.
- During deploy, `scripts/deploy/activate-release.sh` runs `php artisan myapes:releases-sync` after migrations and before the app is switched live (fail-closed if sync fails).
- Local development uses Laravel Herd only (see [[Local setup (Herd)|Dev-Local-setup-Herd]]); run `php artisan myapes:releases-sync` when you need the database view to match files.

## Queue worker, migrations and health (from v0.62.0)
- **Migrations** run on every deploy (`activate-release.sh` runs `php artisan migrate --force` before the switch). v0.62.0 adds `api_clients`, `personal_access_tokens` and the webhook tables; nothing is dropped or renamed.
- **Queue worker:** `cloudron-run.sh` keeps one `artisan queue:work --queue=default,push` and one `artisan schedule:work` running. Webhook deliveries and web push jobs share that worker (`default` and `push` queues); the deploy restarts the app, so the worker picks up the new code. After deploy or an app restart, check `/app/data/shared/storage/logs/queue-worker.log` for `queue:work` processing the `push` queue.
- **Health:** `/healthz` reports `checks.queue`: `ok`, or `delayed` when a webhook delivery has waited more than 15 minutes past its due time (the worker has probably stopped). It is reported only and never fails the deploy check, which still needs `status: ok`, the new `version` and the merge commit as `release`.
- **After a deploy that touches webhooks,** check one delivery goes through: in Admin → Settings → Webhooks, add (or use) an endpoint and replay a recent delivery, or change a test record, then look for `Delivered` in the delivery log.
- **Keys:** the reCAPTCHA keys, TinyMCE key, API tokens and webhook signing secrets are set in Admin → Settings, never in this repository. Keep the real values in APES Vault ('API Keys & Tokens').

## Web push notifications (from v0.72.0)

Production needs VAPID credentials, outbound HTTPS to push providers, and the `push` queue worker.

### VAPID keys

1. **Generate** a key pair on **Admin → Settings → Push notifications** (Generate key pair), or paste values from APES Vault. The subject should be a monitored APES `mailto:` address (for example `mailto:communications@myapes.me.uk`).
2. **Preferred store:** Admin → Settings → Push notifications (saved in Keys and secrets; takes precedence over the server `.env`). The tab shows which source is active: keys saved here, or `VAPID_*` from `/app/data/shared/.env`.
3. **Emergency fallback:** set empty placeholders in `scripts/deploy/production.env.example` (`VAPID_PUBLIC_KEY`, `VAPID_PRIVATE_KEY`, `VAPID_SUBJECT`) on the server only, then restart the app so `cloudron-run.sh` reloads config.
4. **Vault:** keep one copy outside the app in APES Vault (collection **API Keys & Tokens**), item **MyAPES Account – Web Push VAPID keys (production)**. Never put values in chat, issues, pull requests or logs.
5. **Key change impact:** replacing, generating or clearing VAPID keys **removes every device subscription**. Users must turn push on again on each device. Plan comms before rotation.

### Worker and network

- **Queue:** push deliveries are queued on `push`. `cloudron-run.sh` runs `queue:work --queue=default,push` (see above). After deploy, confirm jobs appear in `queue-worker.log`.
- **Outbound:** the app must reach browser push endpoints over **HTTPS (port 443)** from Cloudron (no extra inbound ports).
- **Local Herd:** generate or paste **separate** test keys in `.env`; never use production VAPID material locally.

### After deploy (smoke)

1. `/healthz` reports `status: ok` and the new `version`.
2. Admin → Settings → Push notifications shows **Configured** and the correct key source.
3. Send **Send test push to my devices** from that tab (operator device subscribed first). Full production proof is part of post-deploy ops (#804); this page records the runbook only.
