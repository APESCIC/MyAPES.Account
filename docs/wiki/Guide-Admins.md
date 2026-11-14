> This page is synced from `docs/wiki` in the repository. Edits made here are overwritten on the next sync; suggest changes through a pull request or in [Discussions (Docs and guides)](https://github.com/APESCIC/MyAPES.Account/discussions/categories/docs-and-guides).

# Admins guide

**Audience:** admins  
**Purpose:** people, roles, modules, plugins, settings and staff email.

> **To be written.** This page will be filled in with milestone v0.52 People split and v0.53 staff email.

Related now: [mail.md](https://github.com/APESCIC/MyAPES.Account/blob/main/docs/mail.md)

Admin → Settings (v0.62.0): [[Integrations]] (TinyMCE and Google reCAPTCHA keys), [[API clients|API-clients]] (tokens for APES apps) and [[Webhooks]] (signed change events for APES apps).

Admin → Settings → General (v0.64.0) is the first tab and the one Settings opens on: the support contact, whether people can register for a public account, the site banner on public pages, and read-only retention and upload limits. Saving writes an `admin.settings.general_saved` audit event naming the changed settings only. See [[General settings|General-settings]].

Admin → Emails → Import (v0.63.0): the reply mailbox connection, its step-by-step test, import status, readiness checklist and connection log. See [[Replies by email|Replies-by-email]].
