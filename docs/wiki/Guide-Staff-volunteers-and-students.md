> This page is synced from `docs/wiki` in the repository. Edits made here are overwritten on the next sync; suggest changes through a pull request or in [Discussions (Docs and guides)](https://github.com/APESCIC/MyAPES.Account/discussions/categories/docs-and-guides).

# Staff, volunteers and students guide

**Audience:** staff, volunteers and students  
**Purpose:** day-to-day work: tickets, cases, pet profiles, consultations and search.

## Finding people and the client workspace (v0.78.0)

Staff, volunteers and students with `staff.clients.search` can:

- Press **Ctrl K** (⌘K on Mac) and use the **People** group to find Public Accounts and Public Profiles by name, email, username, phone, postcode, date of birth or reference.
- Open **Clients** for a directory with tabs **All**, **With account**, **Profile only** and **Invited**. Each row shows a text badge (**Active account**, **Invited** or **Profile only**). Search, the selected tab and the Account state filter stay in the URL.
- Open a person to the shared workspace tabs: **Overview** (identity, PIN check, call log, notes, quick actions), **Account Management**, **Account Profile Management**, **Records** and **Activity**. Caller `/staff/callers/{id}` URLs redirect here.
- Date of birth on Account Profile Management is only shown with `staff.clients.sensitive` (administrators).

Creating an account from a Profile only row arrives in a later release; the action stays hidden until then.

## Creating a public client account (v0.65.0)

Staff, volunteers and students with `staff.clients.create` can open **Clients** and use **Add a client** for a local public account:

- Rejects duplicate email or phone, and staff-domain emails (for example `@apes.org.uk`).
- Requires a 4–8 digit phone PIN. It is shown **once** after save — write it down for the caller. It is stored hashed and is never emailed, logged or printed again.
- Email is optional. With an email and the set-password tick, the person gets a 72-hour one-time set-password link (never the PIN or a password). Without an email, no mail is sent.
- Staff-assisted consent is required; the audit log records who created the account and whether the email was sent, without secrets.

Bookings stays under **Tools** for guests and public users only; staff-side roles get a 403 on `/bookings`.

In-app detail: **Staff guide → Clients and the Public Account workspace**.

## Account settings (v0.77.0)

Open **Account** from your avatar for four tabs: **Account**, **Profile**, **Security** and **Privacy**. See [[Account settings|Account-settings]].

## Push notifications (v0.72.0)

Open **Account → Privacy → Notifications** to turn push on for this browser, choose which types may use push or email, set **quiet hours**, and follow the iPhone **Add to Home Screen** guide when Safari needs it. Staff and volunteers can filter **new ticket** pushes by division.

See [[Push notifications|Push-notifications]] for unblock steps and what appears on the lock screen.
