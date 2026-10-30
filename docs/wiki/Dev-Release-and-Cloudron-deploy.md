> This page is synced from `docs/wiki` in the repository. Edits made here are overwritten on the next sync; suggest changes through a pull request or in [Discussions (Docs and guides)](https://github.com/APESCIC/MyAPES.Account/discussions/categories/docs-and-guides).

# Release and Cloudron deploy

**Audience:** developers  
**Purpose:** how a change becomes a release on Cloudron. Source: [README: Release history](https://github.com/APESCIC/MyAPES.Account/blob/main/README.md#release-history-and-semantic-versioning) and [Cloudron deployment](https://github.com/APESCIC/MyAPES.Account/blob/main/README.md#cloudron-deployment-automation).

## Every pull request
1. Prepend exactly one new, higher version to `resources/data/releases.json`:
   ```powershell
   php artisan myapes:changelog-prepare --type=patch --title="Short public title" --issue=<n> --pr=<n>
   ```
   Replace every `TODO:` field.
2. Set `VERSION` and `resources/data/module-runtime-contract.json` `application_version` to the same version.
3. Update the version-pinned tests (change log, health, rollback, release history).
4. Validate: `php artisan myapes:changelog-validate --base-ref=origin/main`.
5. Patch for fixes, minor for new features. Public notes never contain credentials, personal data or exploitable detail.

## After merge
1. Wait for [test-cloudron.yml](https://github.com/APESCIC/MyAPES.Account/blob/main/.github/workflows/test-cloudron.yml) to pass on `main`.
2. Deploy by hand: `gh workflow run "Deploy MyAPES Account to Cloudron" --ref main` ([deploy-cloudron.yml](https://github.com/APESCIC/MyAPES.Account/blob/main/.github/workflows/deploy-cloudron.yml)).
3. The deploy creates GitHub Release `{VERSION} Beta` with tag `v{VERSION}`.
4. Check `/healthz` and the change log at https://myaccount.myapes.me.uk/change-log.

Deploy secrets live in the `cloudron-deploy` GitHub environment. Never print or commit them. Rollback is forward-only; see the README's maintenance section.
