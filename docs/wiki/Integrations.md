> This page is synced from `docs/wiki` in the repository. Edits made here are overwritten on the next sync; suggest changes through a pull request or in [Discussions (Docs and guides)](https://github.com/APESCIC/MyAPES.Account/discussions/categories/docs-and-guides).

# Integrations

**Audience:** admins  
**Purpose:** how to set the keys for outside services MyAPES Account uses (the TinyMCE editor and Google reCAPTCHA) without a deploy. Introduced in v0.62.0.

Open **Admin → Settings → Integrations**. Only administrators can see or change it.

## How keys are kept safe
- A **secret** (for example the reCAPTCHA secret key) is stored encrypted. Once saved, it is never shown again. The page only says a key is saved and, for long keys, shows its last four characters so you can tell keys apart.
- To change a secret, type the new one and save. Leave the box blank to keep the saved one.
- A value set in the server's `.env` file always wins. It shows **Set via .env** and cannot be changed here.
- Every save and every connection test is written to the audit log, naming the fields that changed, never their values.
- Keep the real keys in **APES Vault**, collection **API Keys & Tokens**. Never paste them into tickets, chat, issues or documents.

Each integration shows **Not set**, **Set via .env** or **Set**.

## TinyMCE rich text editor
The editor on ticket, case and other description fields.

1. In tiny.cloud, approve the site's domain for your key.
2. Paste the **TinyMCE Cloud API key** and save.

This key is public by design (it is part of the editor's address on every page with an editor), so it is a normal field, not a masked secret. Without a key, those fields stay plain text boxes. `TINYMCE_API_KEY` in `.env` still works and wins.

## Google reCAPTCHA v3
Invisible spam checks on the public **register**, **forgot password**, **open a ticket** and **job application** forms, and on **Public Login after 3 failed attempts**. Staff signing in through APES Cloudron never see it.

1. In the Google reCAPTCHA admin console, create a **v3** key for the site's domain.
2. Paste the **Site key** and the **Secret key**, and save.
3. Press **Test connection**. It checks Google accepts the secret key.
4. Optionally change the **lowest score allowed** for each form. Scores run from 0.0 (likely a bot) to 1.0 (likely a person); 0.5 is the default. Raise a form's score if spam gets through; lower it if real people are blocked.

What else protects these forms, with or without keys:
- a hidden **honeypot** field that people never see, and a **minimum fill time** (forms sent within two seconds of loading are refused);
- if Google cannot be reached (or answers slowly, after 3 seconds), the form is still accepted but marked unverified in the log, with a stricter limit of 5 unverified sends per form per IP address every 10 minutes. A warning then shows on Admin → Settings → Integrations for a day. Check the secret key and test the connection.

Privacy: reCAPTCHA sends technical data to Google, so its script loads only on those forms, each form shows the Google Privacy Policy and Terms wording, and the [privacy notice](https://myaccount.myapes.me.uk/privacy) and [cookie notice](https://myaccount.myapes.me.uk/cookies) explain it (legitimate interest in keeping the service secure).

For local testing, Google's published test keys work: they always pass.

## Related
- [[General settings|General-settings]] is the first tab in Admin → Settings; [[API clients|API-clients]] and [[Webhooks]] come after Integrations.
- [[Security]]
