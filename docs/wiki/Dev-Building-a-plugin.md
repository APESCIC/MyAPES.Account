> This page is synced from `docs/wiki` in the repository. Edits made here are overwritten on the next sync; suggest changes through a pull request or in [Discussions (Docs and guides)](https://github.com/APESCIC/MyAPES.Account/discussions/categories/docs-and-guides).

# Building a plugin

**Audience:** developers  
**Purpose:** add a feature without editing Core. Full guide: [docs/developer-guide.md](https://github.com/APESCIC/MyAPES.Account/blob/main/docs/developer-guide.md).

## Scaffold
```bash
php artisan make:plugin demo-board --name="Demo Board" --modules=apes-cic --depends=pet-profiles
composer dump-autoload
php artisan test --compact tests/Architecture
```
The command creates the plugin under `plugins/`, adds the PSR-4 entry and registers the service provider. It writes nothing under `app/Core`.

## Then
1. Require the plugin's route files from `routes/web.php` inside the matching module group.
2. Add the slug to each module's `module.php` `plugins` list.
3. Declare dependencies with `PluginDependency`.
4. Name permissions `{module}.{plugin}.{ability}` (via `PermissionNaming`).
5. Settings: `PluginSettingsSchema` in the manifest.
6. Text and search keywords: `lang/en_GB/plugin.php` (`name`, `description`, `keywords`); see [localisation.md](https://github.com/APESCIC/MyAPES.Account/blob/main/docs/localisation.md).
7. Email: `BuildsBrandedMail`, queued after commit, registered in `mailTemplates()`; see [mail.md](https://github.com/APESCIC/MyAPES.Account/blob/main/docs/mail.md).
8. Migrations are forward-only.
9. Recruitment vacancies use `RecruitmentRole`, never Spatie `Role`.

## Turn it on
- Module on or off: Admin > Modules (`OrganisationModule`)
- Plugin per module: `PluginEnablement` (`module_installations`, key `{module}:{plugin}`)

A new module works the same way with `php artisan make:module`.
