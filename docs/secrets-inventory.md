# Secrets inventory: Admin UI vs `.env`-only

Names and locations only. **Never** put secret values in chat, issues, pull requests, docs, commits or the repository. Live values belong in APES Vault (collection **API Keys & Tokens**) and, where the app supports it, in Admin.

On **live Cloudron**, prefer Admin where a field exists. `/app/data/shared/.env` holds true server-only keys and is an emergency/fallback for secrets that also have an Admin path.

| Secret (name only) | Admin UI? | `.env` still required? | Notes |
| --- | --- | --- | --- |
| communications@ Mail Client app password (`INBOUND_MAIL_IMAP_PASSWORD`) | **Yes** — Settings → **Keys and secrets** (enter, Test, save) | Fallback only | **Primary path on live is Keys and secrets.** Code still reads `.env` when no Keys value is saved. Vault item name only in docs. |
| TinyMCE API key | **Yes** — Settings → **Integrations** | Optional override | `.env` `TINYMCE_API_KEY` wins if set. |
| Google reCAPTCHA site key + secret | **Yes** — Settings → **Integrations** (Test available) | Optional override | `.env` wins if set. Use Google’s public test keys locally only. |
| Webhook signing secrets | **Yes** — Settings → **Webhooks** | No for day-to-day | Created/rotated in Admin; never shown again after save. |
| API client tokens | **Yes** — Settings → **API clients** | No for day-to-day | Issued in Admin; treat like passwords. |
| `APP_KEY` | No (Keys and secrets **server-only** panel shows set/missing) | **Yes** | Cloudron `/app/data/shared/.env`. Not enterable in Admin. |
| `INBOUND_MAIL_TOKEN_KEY` | No (server-only panel) | **Yes** | Reply-token HMAC key. Locally: `php artisan inbound-mail:key`. Not enterable in Admin. |
| `INBOUND_MAIL_AUTHSERV_ID` | No | **Yes** on live when auth results are enforced | Trusted Authentication-Results authserv-id (name only). |
| OIDC client secret (`OIDC_CLIENT_SECRET`) | No | **Yes** for staff OIDC | Remains `.env`-only until Bambie decides otherwise. |
| LDAP bind password (`LDAP_BIND_PASSWORD`) | No | **Yes** when LDAP is used | Remains `.env`-only until Bambie decides otherwise. |
| `PASSKEYS_USER_HANDLE_SECRET` | No | Optional | Separates passkey handles from `APP_KEY` rotation. |
| Mail SMTP credentials (`MAIL_*`) | No | As required by the mail transport | Live Cloudron mail transport; locally Herd catcher needs no secret. |
| Database / Redis (`DB_*`, `REDIS_*`) | No | **Yes** | Infrastructure only. |

Related: [mail.md](mail.md#replies-by-email), wiki [Replies by email](https://github.com/APESCIC/MyAPES.Account/wiki/Replies-by-email), wiki [Integrations](https://github.com/APESCIC/MyAPES.Account/wiki/Integrations), local exercise path [herd.md](herd.md#keys-and-secrets-and-integrations-locally).
