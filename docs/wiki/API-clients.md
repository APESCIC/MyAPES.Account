> This page is synced from `docs/wiki` in the repository. Edits made here are overwritten on the next sync; suggest changes through a pull request or in [Discussions (Docs and guides)](https://github.com/APESCIC/MyAPES.Account/discussions/categories/docs-and-guides).

# API clients

**Audience:** admins and developers of APES apps  
**Purpose:** how other APES apps (first APES Newsroom and EASM) read from MyAPES Account through `/api/v1`, and how admins issue, rotate and revoke their tokens. Introduced in v0.62.0.

## For admins
Open **Admin → Settings → API clients**. Only administrators can see or change it.

- **Add an app:** press **Add APES Newsroom…** or **Add EASM…** for the approved set-up, or fill in the form: the app's name, its **organisation**, the **abilities** it may use, how many days the token lasts (90 by default), an optional **IP allowlist** and a rate limit.
- **The token is shown once**, straight after you add the app or rotate it. Copy it into **APES Vault** (**API Keys & Tokens**) and into the app's own settings. It cannot be seen again; if it is lost, rotate.
- **Rotate** issues a new token. The old one keeps working for 24 hours so the app can switch over, then stops.
- **Revoke** stops every token for that app straight away.
- Each app shows when it was last used and from which IP address. Adding, changing, rotating and revoking are all in the audit log; tokens never are.

| App | Organisation | Abilities |
| --- | --- | --- |
| APES Newsroom | `apes-cic` | `staff:read`, `groups:read`, `users:read`, `consent:read`, `tickets:read` |
| EASM | `shelter-rescue` | `users:read`, `pets:read`, `tickets:read`, `cases:read` |

## For developers
The full reference is [`docs/api/openapi-v1.yaml`](https://github.com/APESCIC/MyAPES.Account/blob/main/docs/api/openapi-v1.yaml). Admins also see a guide in **Admin → Settings → API docs**.

- Base address: `https://myaccount.myapes.me.uk/api/v1`.
- Send the token on every request: `Authorization: Bearer <token>`. Start with `GET /api/v1/me` to check it.
- Everything is **read-only** and limited to the client's **organisation**: people who chose that service, its tickets, cases and pets. APES CIC clients see all staff; other organisations see their own team.
- Each endpoint needs its ability on the token. Lists take `page` and `per_page` (at most 100).
- Answers never include passwords, two-factor details, addresses, phone numbers, staff notes or internal case updates.
- Errors are JSON with a `message`: 401 no or bad token, 403 missing ability, blocked IP or another organisation, 404 not found or outside the organisation, 422 bad query, 429 over the rate limit.
- Ticket and case numbers are the ones in emails: `GET /api/v1/tickets/123` is "Ticket #123".

| Endpoint | Ability |
| --- | --- |
| `GET /me` | none |
| `GET /staff`, `GET /staff/{id}` | `staff:read` |
| `GET /groups` | `groups:read` |
| `GET /users/lookup?email=`, `GET /users/{id}` | `users:read` |
| `GET /users/{id}/consents` | `consent:read` |
| `GET /users/{id}/pets`, `GET /pets/{id}` | `pets:read` |
| `GET /tickets?category=&organisation=`, `GET /tickets/{ref}` | `tickets:read` |
| `GET /cases/{ref}` | `cases:read` |

Changing the API: update `routes/api.php` and `docs/api/openapi-v1.yaml` together. The test `tests/Feature/Api/OpenApiSpecTest.php` fails in CI when they disagree.

## Related
- [[Webhooks]] tell your app when something changes.
- [[Integrations]]
