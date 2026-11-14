> This page is synced from `docs/wiki` in the repository. Edits made here are overwritten on the next sync; suggest changes through a pull request or in [Discussions (Docs and guides)](https://github.com/APESCIC/MyAPES.Account/discussions/categories/docs-and-guides).

# Replies by email

**Audience:** everyone, with sections for staff and admins  
**Purpose:** how to reply to a ticket or case by replying to its email, what is ignored, and how admins handle replies that were not added. Introduced in v0.59.0.

## For everyone

When a ticket or case you can see is updated, MyAPES Account emails you. You can now **reply to that email** instead of logging in.

1. Open the email. At the top it says **Reply above this line**.
2. Press **Reply** in your email app and write above that line. Keep the subject as it is; it ends with a reference such as `[MYAPES-T-1234]`.
3. Send it. Within a minute or two your reply appears on the ticket or case, marked **by email**, and everyone who normally hears about updates is told.

Good to know:

- Send from the address on your MyAPES Account. Replies from any other address are not added.
- Only your new text is added. The quoted email underneath, and signatures such as "Sent from my iPhone", are left out.
- You can attach files. The usual upload rules apply (screenshots, screencasts and documents up to the usual sizes). Files that are not allowed are left out; your text is still added.
- If the ticket or case is **closed**, your reply is not added and you get one email telling you so. Log in to see it, or open a new request.
- If MyAPES Account cannot tell which ticket or case your reply is for (for example you started a new email or changed the reference), it is held for staff and you get one email telling you so. Staff will add it to the right ticket or case.
- You can always use **Log in to reply** in the email instead.

## For staff

- Reply to a ticket or case email from your work address, the same way. Your reply is posted as a **public** reply that the owner can see.
- **Internal notes cannot be sent by email.** Log in and choose *Internal* when you need a staff-only note.
- Each email you receive has its own reply address. Do not forward a ticket email for someone else to answer: replies from another person are not added.

## What is ignored

These are recorded on Admin → Emails → Inbound but never posted or answered:

- out-of-office and other automatic replies
- bounces and delivery reports
- bulk mail and mailing lists
- mail sent by MyAPES Account itself
- more than 20 replies an hour from one person, or 30 an hour on one ticket or case

## For admins

**Admin → Emails → Inbound** (`admin.emails.view`) lists every reply received, newest first, with what happened to it:

| Status | Meaning |
| --- | --- |
| Posted | Added to the ticket or case |
| Ignored | Automatic, bulk or bounced mail, or over the hourly limit |
| Rejected | Closed record, empty reply, suspended account or a message too large to keep |
| Quarantined | Needs a person: unknown sender, failed sender checks, the reply address belonged to someone else, no matching record, references to different tickets or cases, or the service is switched off |
| Failed | Could not be read or processed |
| Discarded | An admin discarded it |

With `admin.emails.manage` you can open a quarantined or failed email and:

- **Attach to ticket or case** (quarantined only): type the reference, such as `T12` or `C4`, and post the reply there as a public message. It is posted as the account that sent it; if no account matches the sender you must choose the account, or discard it. Closed tickets and cases cannot be attached to.
- **Retry** it, for example after fixing the sender's account or switching the service back on, or
- **Discard** it, which deletes the stored message.

All three are written to the audit log, without the message text.

### Email import (the reply mailbox)

**Admin → Emails → Import** (v0.63.0; menu item **Email import**, and an **Import** tab next to Inbound) holds the shared `communications@myapes.me.uk` mailbox. `admin.settings.view` shows it and `admin.settings.manage` changes and tests it; both are for administrators only. It used to be the Reply mailbox tab of Admin → Settings, and that old address now takes you here.

**Connection**

- **Turn on replies by email**, the mailbox address, IMAP server, port, encryption, certificate check, username and folder. A new install starts with the APES Cloudron values: `mail.cloudron.apes.org.uk`, port 993 and **SSL/TLS (port 993)**. The Cloudron mail server has IMAP on 993 with SSL/TLS only.
- Port 993 with STARTTLS, or port 143 with SSL/TLS, can never work, so the page refuses them and says which to choose.
- **Username**: the full mailbox address. Alias logins do not work.
- **Mail app password**: the mailbox owner's Cloudron **Mail Client** app password, from APES Vault ('API Keys & Tokens'). It is write-only, encrypted and never shown again; leave the box blank to keep it. A mailbox owned by a group only accepts a member's own password, so the mailbox is owned by a service user.
- **Plus-addressing delivered**: tick it only if mail to `communications+something@` reaches the mailbox (see the runbook). When it is off, replies go to the plain address and are matched by their email headers and subject tag.
- A field marked **Set by .env** comes from the server's env file and wins over this page.

**Test connection** tests the values in the form, even before you save or switch replies on. It checks, in order, the server name, the connection, the encryption, the login and the folder, and stops at the first problem. Each step shows Passed, Failed or Skipped with its time. A password you type is used for that test only and never kept. Leave the password blank to test the saved server with the saved password; to test a different server, port, encryption or username, or with the certificate check off, type the password, because the saved one is only ever sent to the saved server. You can run 5 tests every 10 minutes. A passing test shows how many messages (and unread) are in the folder, which features the server has (IDLE, MOVE, UIDPLUS) and when its certificate expires. A failed test names the problem and what to try:

| Problem | What to try |
| --- | --- |
| The server name was not found | Check the spelling of the IMAP server. On the APES Cloudron it is `mail.cloudron.apes.org.uk`. |
| The server refused the connection | Check the port. On the APES Cloudron IMAP is on port 993; ask IT if a firewall blocks it. |
| The server did not answer in time | Check the server name and port, then try again. If it keeps happening, ask IT whether the mail server is up. |
| Wrong encryption for this port | Use SSL/TLS with port 993, or STARTTLS with port 143. The APES Cloudron offers IMAP on 993 with SSL/TLS only. |
| The server's certificate name does not match | Use the name on the certificate as the IMAP server, for example `mail.cloudron.apes.org.uk` rather than an alias. |
| The server's certificate has expired or is not trusted | Ask IT to renew the mail certificate on Cloudron. Only turn off the certificate check for local testing. |
| The encryption handshake failed | Check the encryption and port, then try again. If it keeps happening, ask IT to check the mail server. |
| The server refused the username or password | Use the full mailbox address and the mailbox owner's Cloudron Mail Client app password. Alias logins do not work. |
| The server has had too many attempts | Wait 15 minutes before trying again, then check the password. |
| The folder was not found | Choose one of the folders the server has (the result lists them). Most mailboxes use INBOX. |
| The server is missing a feature the import uses (a warning) | Imports still work, a little more slowly. Ask IT whether the mail server can offer MOVE and UIDPLUS. |

**Send a plus-address probe** emails a `communications+probe` address; the page shows whether it arrived after the next check. The probe is never added to a ticket or case.

**Import status and readiness.** The page shows the last connection test, the last successful check of the mailbox and, after failures, when the next check will run. **Ready to import?** lists, with a tick or a cross: the reply token key on the server, replies switched on, a valid mailbox address, a saved password, a passing connection test, a scheduler check in the last 5 minutes and an arrived probe. Each cross says who fixes it: an administrator on this page, or IT on Cloudron. Secrets only ever show as set or not set.

**How importing runs.** MyAPES Account checks the mailbox every minute and fetches only new mail. Imported mail moves to the mailbox's **Processed** folder; mail that cannot be stored after 3 tries moves to **Failed**. Both folders are created if missing and emptied of mail older than 30 days. If the mailbox cannot be reached, checks slow down (1, 2, 5, 10, then every 15 minutes) until it works again. After 3 failed checks in a row, or 15 minutes of failures, administrators see a banner and get one email; when importing works again the banner clears and one more email says so.

**Recent connection log** lists the newest 50 tests and checks: when, what, the result, the step, the time and how much mail was fetched and imported. It never keeps a password, username or email content, and entries go after 30 days.

Every save, test and probe is written to the audit log with field names only, never the password. Operators: the set-up steps are the [Cloudron mailbox runbook](https://github.com/APESCIC/MyAPES.Account/blob/main/docs/mail.md#cloudron-mailbox-runbook).

**Replies by email for each service** (same page, `admin.emails.manage`) switches replies by email off for one plugin in one service, for example Tickets in Pet Care Clinic. While it is off, replies there are quarantined and that service's emails go back to the no-reply address.

## Privacy

The full email is kept for 30 days so problems can be checked, then deleted. After that only a record of when it arrived, what happened and why is kept. In the mailbox itself, imported mail moves to a Processed (or Failed) folder and is deleted from there after 30 days. The [privacy notice](https://myaccount.myapes.me.uk/privacy) explains this too.

## Local testing

Developers can try the whole loop on Herd with no real mailbox. Turn replies on with `php artisan inbound-mail:key --enable`, set `INBOUND_MAIL_DRIVER=herd` and `MAIL_USERNAME=myapes-account` in `.env`, then:

1. As staff, add a public message to a demo ticket; its email arrives in Herd > Mail.
2. On **Admin → Emails → Inbound**, the **Local test replies** panel lists that email. Use **Reply as client** to send a reply, with optional variants such as no subject tag.
3. On **Admin → Emails → Import**, press **Fetch now** (or run `php artisan tickets:inbound-loop-check`) and open the ticket: the reply is there, marked **by email**.

Mailpit remains an optional fallback. The mailbox password is set in **Admin → Settings → Keys and secrets** or `INBOUND_MAIL_IMAP_PASSWORD` in the server `.env` — never in a connection form. The panel exists only on a local install. Step by step: [`docs/herd.md`](https://github.com/APESCIC/MyAPES.Account/blob/main/docs/herd.md#replies-by-email).

Developers and operators: see [`docs/mail.md`](https://github.com/APESCIC/MyAPES.Account/blob/main/docs/mail.md#replies-by-email).
