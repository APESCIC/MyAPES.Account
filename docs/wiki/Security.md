> This page is synced from `docs/wiki` in the repository. Edits made here are overwritten on the next sync; suggest changes through a pull request or in [Discussions (Docs and guides)](https://github.com/APESCIC/MyAPES.Account/discussions/categories/docs-and-guides).

# Security

**Audience:** everyone

Never report a security problem in an issue, a discussion or this wiki.

- Read [SECURITY.md](https://github.com/APESCIC/MyAPES.Account/blob/main/SECURITY.md)
- Report privately: https://github.com/APESCIC/MyAPES.Account/security/advisories/new

## Operational secrets (names only)

Administrators and operators: which secrets are entered in Admin versus which stay in the server `.env` is listed in [`docs/secrets-inventory.md`](https://github.com/APESCIC/MyAPES.Account/blob/main/docs/secrets-inventory.md). That inventory contains **names and locations only** — never secret values.

On live Cloudron, set the communications@ mailbox password in **Admin → Security → Keys and secrets** (enter, Test, save). `/app/data/shared/.env` is for true server-only keys (`APP_KEY`, `INBOUND_MAIL_TOKEN_KEY`, OIDC/LDAP, database, …) and is only an emergency fallback for the mailbox password.

## Admin → Security (v0.80.0)

Administrators with Security permissions can open:

- **Overview** — counts for two-factor, passkeys, suspended accounts and recent security events (names and counts only).
- **Audit log** — filter by actor, subject, event type and date; export is audited; secret values are never shown.
- **Access** — groups, roles and the permission catalogue (moved here from People).
- **Keys and secrets** — operator-entered secrets (step-up still required).
- **Sign-in policy** — temporary-password lifetime, telephone PIN length bounds and staff two-factor requirement.
