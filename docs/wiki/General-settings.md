> This page is synced from `docs/wiki` in the repository. Edits made here are overwritten on the next sync; suggest changes through a pull request or in [Discussions (Docs and guides)](https://github.com/APESCIC/MyAPES.Account/discussions/categories/docs-and-guides).

# General settings

**Audience:** admins  
**Purpose:** the everyday options on **Admin → Settings → General**: the support contact, public registration and the site banner. Introduced in v0.64.0.

**Admin → Settings** opens on **General**, its first tab. Only administrators can see or change it. Changes take effect straight away, without a deploy.

## Support contact
The **support email address** and **support phone number** people can use to reach the APES support team. Leave a box blank to remove it. The phone number may use digits, spaces, brackets and hyphens, with an optional `+` at the start.

## Public registration
**Allow people to register for a public account** is on by default. When you switch it off:
- the **Register** links disappear from the public pages, the menu, the sign-in page and the help pages, and `/register` is left out of the sitemap;
- anyone who opens the Register page, or sends its form, is taken to **Public Login** with a notice that registration is closed;
- links that would normally send a guest to register (for example **Open a ticket** or applying for a role) send them to Public Login instead.

People who already have an account can still sign in. Staff and administrators still sign in through **APES Cloudron** (Staff Login), which this switch never affects.

## Site banner
A short notice shown at the top of public pages, for example planned downtime. Tick **Show the banner on public pages** and type the **banner text** (plain text, up to 500 characters). The banner is not shown on Admin pages. It is hidden when it is switched off or has no text.

## Read-only summaries
The tab also shows, for reference only:
- **How long records are kept**: the audit log, staff call logs, the email send log, imported emails and the mailbox's Processed and Failed folders. These are set in the server's `.env` file by the APES IT Administrator.
- **Upload limits** for files attached to tickets and cases, and the server's PHP limits.

Links at the bottom go to **Maintenance**, **Integrations**, **Modules**, **Emails → Inbound** and **Emails → Import** (the reply mailbox).

## Audit
Every save that changes something writes an `admin.settings.general_saved` event to the audit log. It names the settings that changed, never their values.

## Related
- [[Integrations]], [[API clients|API-clients]] and [[Webhooks]] are the other tabs in Admin → Settings.
- [[Replies by email|Replies-by-email]] for Admin → Emails → Import.
