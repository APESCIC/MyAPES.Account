> This page is synced from `docs/wiki` in the repository. Edits made here are overwritten on the next sync; suggest changes through a pull request or in [Discussions (Docs and guides)](https://github.com/APESCIC/MyAPES.Account/discussions/categories/docs-and-guides).

# Staff, volunteers and students guide

**Audience:** staff, volunteers and students  
**Purpose:** day-to-day work: tickets, cases, pet profiles, consultations and search.

## Creating a public client account (v0.65.0)

Staff, volunteers and students with `admin.clients.create` can open **Clients** and create a local public account for a caller:

- Rejects duplicate email or phone, and staff-domain emails (for example `@apes.org.uk`).
- Requires a 4–8 digit phone PIN. It is shown **once** after save — write it down for the caller. It is stored hashed and is never emailed, logged or printed again.
- Email is optional. With an email and the set-password tick, the person gets a 72-hour one-time set-password link (never the PIN or a password). Without an email, no mail is sent.
- Staff-assisted consent is required; the audit log records who created the account and whether the email was sent, without secrets.

Bookings stays under **Tools** for guests and public users only; staff-side roles get a 403 on `/bookings`.

In-app detail: **Staff guide → Creating a public client account**.
