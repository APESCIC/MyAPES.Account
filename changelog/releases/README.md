# Per-release changelog files

Canonical release history for MyAPES Account lives here as one `vX.Y.Z.json` file per published version (#705–#706).

- Template: `_template.json` (not synced)
- Sync into the `releases` table: `php artisan myapes:releases-sync`
- Convert from the legacy aggregate (one-time / parity): `php artisan myapes:releases-convert`
- `resources/data/releases.json` is a generated compatibility artefact — do not hand-edit it after conversion
