> This page is synced from `docs/wiki` in the repository. Edits made here are overwritten on the next sync; suggest changes through a pull request or in [Discussions (Docs and guides)](https://github.com/APESCIC/MyAPES.Account/discussions/categories/docs-and-guides).

# Architecture

**Audience:** developers  
**Purpose:** a short map of Core > Modules > Plugins. The source of truth is [docs/architecture.md](https://github.com/APESCIC/MyAPES.Account/blob/main/docs/architecture.md) and [ADR 0001](https://github.com/APESCIC/MyAPES.Account/blob/main/docs/adr/0001-core-modules-plugins.md).

## Layers
| Layer | What it is | Where |
|---|---|---|
| Core | Sign-in, people, roles, settings, mail, **web push** (VAPID, subscriptions, queued delivery, profile preferences), navigation, search | `app/Core`, `app/` |
| Modules | Organisation areas: APES CIC (`/apes-cic`), Shelter and Rescue (`/shelter`), Pet Care Clinic (`/petcare`) | `modules/` |
| Plugins | Reusable features: tickets, pet profiles, cases, recruitment, consultations | `plugins/` |

## Rules
- Core never depends on a module or plugin.
- Modules compose plugins (listed in `modules/{slug}/module.php`).
- A plugin may use another plugin only through its `Contracts`, `Models` or `Support` namespaces, declared with `PluginDependency`.
- Permissions are named `{module}.{plugin}.{ability}`.
- Architecture tests in `tests/Architecture` enforce these rules and fail CI.

## Web push (v0.72.0)

- **Core** owns VAPID credentials, device subscriptions, user preferences (types, quiet hours, divisions), the `push` queue job, metadata-only `push_deliveries` records, and the service worker assets. Features add push through `SendsWebPush` and notification type keys — not by calling the push HTTP client directly.
- **Lock-screen payloads** use notification type and record reference only (`PushPayloadBuilder`).
- **Browser vendors** relay encrypted messages; APES does not host a push gateway. See [[Push notifications|Push-notifications]] and the privacy notice.

## More
- [Architecture inventory](https://github.com/APESCIC/MyAPES.Account/blob/main/docs/architecture-inventory.md)
- [Localisation](https://github.com/APESCIC/MyAPES.Account/blob/main/docs/localisation.md) and [mail](https://github.com/APESCIC/MyAPES.Account/blob/main/docs/mail.md)
- [Glossary](https://github.com/APESCIC/MyAPES.Account/blob/main/docs/glossary.md)
