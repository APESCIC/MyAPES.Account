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
| Quarantined | Needs a person: unknown sender, failed sender checks, the reply address belonged to someone else, no matching record, or the service is switched off |
| Failed | Could not be read or processed |
| Discarded | An admin discarded it |

With `admin.emails.manage` you can open a quarantined or failed email and:

- **Retry** it, for example after fixing the sender's account or switching the service back on, or
- **Discard** it, which deletes the stored message.

Both are written to the audit log.

**Replies by email for each service** (same page, `admin.emails.manage`) switches replies by email off for one plugin in one service, for example Tickets in Pet Care Clinic. While it is off, replies there are quarantined and that service's emails go back to the no-reply address.

## Privacy

The full email is kept for 30 days so problems can be checked, then deleted. After that only a record of when it arrived, what happened and why is kept. The mailbox itself never keeps processed mail. The [privacy notice](https://myaccount.myapes.me.uk/privacy) explains this too.

Developers and operators: see [`docs/mail.md`](https://github.com/APESCIC/MyAPES.Account/blob/main/docs/mail.md#replies-by-email).
