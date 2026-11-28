> This page is synced from `docs/wiki` in the repository. Edits made here are overwritten on the next sync; suggest changes through a pull request or in [Discussions (Docs and guides)](https://github.com/APESCIC/MyAPES.Account/discussions/categories/docs-and-guides).

# Admins guide

**Audience:** admins  
**Purpose:** Admin menu sections — People, Security, Modules, Communication and System.

## Menu sections (v0.80.0)

- **Overview** — account, service and plugin numbers.
- **People** — Public Accounts, Public Profiles (profile-only), Staff Accounts. Access moved out of People into Security.
- **Security** — Overview (2FA / passkey / suspension counts and recent events), Audit log (filter and export), Access (groups, roles, permissions), Keys and secrets, Sign-in policy. Links to API clients and Webhooks (those pages stay under Settings).
- **Modules** — Modules, Plugins, Versions.
- **Communication** — Email Templates, Email import.
- **System** — Settings (General, Integrations, Push, API clients, Webhooks, API docs — no Keys tab), Maintenance, Embed forms (Forms hub arrives later).
- **Support** — Admin guide in Help.

Related: [mail.md](https://github.com/APESCIC/MyAPES.Account/blob/main/docs/mail.md), [[Security]], [[General settings|General-settings]].

## Settings and related pages

Admin → Settings (v0.62.0): [[Integrations]] (TinyMCE and Google reCAPTCHA keys), [[API clients|API-clients]] (tokens for APES apps) and [[Webhooks]] (signed change events for APES apps).

Admin → Settings → General (v0.64.0) is the first tab and the one Settings opens on: the support contact, whether people can register for a public account, the site banner on public pages, and read-only retention and upload limits. Saving writes an `admin.settings.general_saved` audit event naming the changed settings only. See [[General settings|General-settings]].

Admin → Communication → Email import (v0.63.0): the reply mailbox connection, its step-by-step test, import status, readiness checklist and connection log. See [[Replies by email|Replies-by-email]].

Admin → Settings → Push notifications (v0.72.0): VAPID keys (generate, paste or clear), pause all web push, anonymous stats and send test to your own subscribed devices. Keys saved under **Admin → Security → Keys and secrets** beat server `.env`; rotating keys clears every subscription. See [[Push notifications|Push-notifications]] and the deploy section in [[Release and Cloudron deploy|Dev-Release-and-Cloudron-deploy]].
