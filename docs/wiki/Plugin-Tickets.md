> This page is synced from `docs/wiki` in the repository. Edits made here are overwritten on the next sync; suggest changes through a pull request or in [Discussions (Docs and guides)](https://github.com/APESCIC/MyAPES.Account/discussions/categories/docs-and-guides).

# Plugin: Tickets

**Audience:** everyone  
**Purpose:** what the Tickets plugin does and who can do what.

## Open a ticket (public)

From v0.65.0, `/open-a-ticket` is login-first:

1. Guests see **Public Login** and **Public Register** links, not a submit form.
2. Signed-in public users pick a service and area on one combined form; the ticket goes straight into that service's ticket system (APES CIC, Shelter, or Pet Care). There is no triage queue.
3. Staff Cloudron accounts cannot complete the public form.
4. Old `/open-a-ticket/{service}/{area}/start` URLs redirect to the combined form with the service and area pre-selected (guests are sent through sign-in or Public Register first).

## Embed a guest open-a-ticket form (v0.69.0)

Other APES websites can embed a **guest** ticket form (no MyAPES sign-in). This is separate from `/open-a-ticket`:

1. In **Admin → Embed forms**, create a form, pick enabled services, and list allowed parent origins (HTTPS hosts). Include Herd hosts such as `https://myapes-account.test` when testing locally.
2. Copy the embed snippet (loader script + `data-public-key`). Paste it on an allowed parent page.
3. Guests submit name, email, service, area, subject and details with GDPR consent. They confirm their email before staff are notified. If the email matches an existing public account, that user owns the ticket after verify.
4. Logged-in ticket screens are never embedded. Staff cannot use the guest embed as themselves.

## Replying by email

Ticket owners and staff can reply to a ticket update email instead of logging in. The reply is posted as a public message marked **by email**; internal notes cannot be sent by email, and replies to closed tickets are not added. See [[Replies by email|Replies-by-email]].

Code: [`plugins/`](https://github.com/APESCIC/MyAPES.Account/blob/main/plugins)
