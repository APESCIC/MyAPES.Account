# Per-release changelog files

Canonical release history for MyAPES Account lives here as one `vX.Y.Z.json` file per published version (#705–#706).

- Template: `_template.json` (not synced)
- Prepare the next release (stub file, version bump, regenerated aggregate): `php artisan myapes:changelog-prepare`
- Sync into the `releases` table: `php artisan myapes:releases-sync` (runs on Cloudron in `scripts/deploy/activate-release.sh` before go-live)
- Convert from the legacy aggregate (one-time / parity): `php artisan myapes:releases-convert`
- `resources/data/releases.json` is a generated compatibility artefact — do not hand-edit it as source of truth; edit the per-release file and re-run prepare or the aggregate writer only through tooling
- Production serves `/change-log` from the database when `MYAPES_CHANGELOG_USE_DATABASE=true` (see `.env.example` and `scripts/deploy/production.env.example`)
- There is no Admin release editor. APES Newsroom is the interaction reference for the public hub only.
