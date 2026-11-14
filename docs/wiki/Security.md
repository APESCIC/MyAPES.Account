> This page is synced from `docs/wiki` in the repository. Edits made here are overwritten on the next sync; suggest changes through a pull request or in [Discussions (Docs and guides)](https://github.com/APESCIC/MyAPES.Account/discussions/categories/docs-and-guides).

# Security

**Audience:** everyone

Never report a security problem in an issue, a discussion or this wiki.

- Read [SECURITY.md](https://github.com/APESCIC/MyAPES.Account/blob/main/SECURITY.md)
- Report privately: https://github.com/APESCIC/MyAPES.Account/security/advisories/new

## Operational secrets (names only)

Administrators and operators: which secrets are entered in Admin versus which stay in the server `.env` is listed in [`docs/secrets-inventory.md`](https://github.com/APESCIC/MyAPES.Account/blob/main/docs/secrets-inventory.md). That inventory contains **names and locations only** — never secret values.

On live Cloudron, set the communications@ mailbox password in **Admin → Settings → Keys and secrets** (enter, Test, save). `/app/data/shared/.env` is for true server-only keys (`APP_KEY`, `INBOUND_MAIL_TOKEN_KEY`, OIDC/LDAP, database, …) and is only an emergency fallback for the mailbox password.
