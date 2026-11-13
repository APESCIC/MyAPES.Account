# Mail

How MyAPES Account builds, sends, previews and records outbound email. Built by the email templates epic ([#351](https://github.com/APESCIC/MyAPES-Account/issues/351), releases 0.42.0 to 0.42.11).

There is no WYSIWYG editor and no stored-HTML CMS. Blade views and `lang/` files are the source of truth for every email. Admins can only adjust a template's subject and opening line in plain text.

## Building an email

Every outbound email is a Laravel `Notification` with a `mail` channel. There are no Mailables.

- Use `App\Core\Mail\Concerns\BuildsBrandedMail`. `brandedMail()` returns a `MailMessage` whose sender name is `terms.app_name` ("MyAPES Account"), and `recipientName()` falls back to `mail.brand.greeting_fallback`.
- Render on the shared brand shell in `resources/views/mail/brand/`:
  - `layout.blade.php` is the HTML shell.
  - `message.blade.php` adds the logo header, footer and Help link (`mail.brand.footer.*`).
  - `notification.blade.php` renders a line-based `MailMessage` (`->greeting()`, `->line()`, `->action()`) inside that shell. Use `->markdown('mail.brand.notification')`.
  - Auth emails use their own views in `resources/views/mail/auth/`, which wrap `mail.brand.message`.
- Implement `ShouldQueue`, use `Queueable`, and call `$this->afterCommit()` in the constructor, so a send inside a database transaction waits for the commit. Notifications that carry Eloquent models also set `public bool $deleteWhenMissingModels = true;`.
- Keep secrets out of subjects and bodies: no passwords, TOTP codes, raw tokens or internal staff notes. Signed links are minted when the email is built, never stored.

## Registering a template

`App\Core\Mail\MailTemplateRegistry` lists every template. Admin Emails and the test suite both read it.

- Core templates live in `App\Core\Mail\CoreMailTemplates`.
- A plugin overrides `PluginServiceProvider::mailTemplates()` and returns its own `MailTemplate` list, so Core never imports plugin classes.

Each `App\Core\Mail\MailTemplate` has:

| Field | Purpose |
| --- | --- |
| `key` | Stable `group.name` in snake case, for example `tickets.ticket_updated`. Group is `auth`, `accounts`, or the plugin slug. |
| `notification` | The notification class. Compatibility subclasses resolve to the same template. |
| `category` | `MailCategory::Security`, `Service` or `Optional` (see below). |
| `labelKey`, `descriptionKey` | Lang keys for the Admin list. Plugins use their own namespace, for example `tickets::mail.templates.ticket_updated.name`. |
| `sample` | Closure that builds the notification from in-memory sample data for preview and send-test. It must not save or send anything. |
| `owner` | `core` or the plugin slug. |
| `subjectKey`, `introKey` | Optional lang keys Admins may override (see [Wording overrides](#wording-overrides)). |

Adding a template also means adding its key to `tests/Feature/Mail/MailTemplateMatrixTest.php`; that test fails until the matrix covers every registered key.

## Categories and the Email preference

| Category | Sent when | Examples |
| --- | --- | --- |
| Security | Always | Verify email, reset password, password changed, authenticator and passkey notices, email change |
| Service | Always | Staff Login reminder, ticket, case and consultation updates, Recruitment application received |
| Optional | Only when the person's Email contact preference is on | Recruitment decisions (shortlisted, rejected, accepted, closed) |

Optional notifications use `App\Core\Mail\Concerns\RespectsMailPreference` and return `$this->optionalMailChannels($notifiable)` from `via()`. The preference is `user_contact_preferences.email`; it is off until the person turns it on in their profile.

## Admin Emails

Admin → Emails (`/admin/emails`).

| Permission | Roles | Allows |
| --- | --- | --- |
| `admin.emails.view` | Administrator | List templates, open a template, preview it, read the outbound log and the Inbound tab |
| `admin.emails.manage` | Administrator only | Send a test, edit and reset wording, retry or discard inbound replies, switch replies by email per service |

The page has three tabs: **Templates**, **Outbound log** and **Inbound** (see [Replies by email](#replies-by-email)).

- **Preview** renders the sample with the viewing admin's name inside a sandboxed iframe (`/admin/emails/{key}/preview`, served with its own `sandbox` Content Security Policy). Nothing is sent.
- **Send test** sends the sample to the acting admin's own address only, immediately and on the mail channel only, so no in-app notification is written. Limited to 5 tests per admin every 10 minutes and audited as `admin.emails.test_sent`.
- **Resend verification** is on the account page (Admin → Public users → account) for Public Login accounts whose email is not verified, gated by `admin.users.manage`. Each resend builds a brand-new signed link; older links are never reused. Limited to 3 per account per hour and audited as `auth.verification_resent`.

## Wording overrides

For templates with `subjectKey` or `introKey`, an Administrator can change the subject and opening line on the template page.

- Plain text only, up to 200 characters for the subject and 1,000 for the opening line. Angle brackets are rejected.
- Placeholders such as `:role` must already appear in the shipped wording.
- Saves use an optimistic lock, so a stale form is rejected. Each change is audited as `admin.emails.copy_updated` with the before and after text.
- **Reset to shipped wording** deletes the override and is audited as `admin.emails.copy_reset`.
- Overrides are stored in `mail_template_overrides` for the app locale (`en_GB`). `App\Core\Mail\MailCopyOverrides` pushes them into the translator after boot and before every queued job, so web requests and long-running queue workers both pick up changes and resets.

## Outbound log

Every mail-channel send writes one row to `outbound_mail_logs` (listener `App\Core\Mail\RecordOutboundMail` on `NotificationSent` and `NotificationFailed`).

- Stored: template key, notification class, mailer, user id (when the recipient is an account), an HMAC-SHA256 hash of the lower-cased address keyed by `APP_KEY`, the recipient's domain, status (`sent` or `failed`) and time.
- Never stored: the full address, subject, body, links or tokens.
- Admin → Emails → outbound email log shows the newest entries with the domain and a short hash prefix.
- Retention: `mail:prune-log` runs daily and deletes rows older than `MAIL_LOG_RETENTION_DAYS` (default 90). Run it by hand with `php artisan mail:prune-log --days=30`.

## Sending on Cloudron

Transport is unchanged: Cloudron's SMTP add-on.

- `config/mail.php` falls back to `CLOUDRON_MAIL_SMTP_SERVER`, `CLOUDRON_MAIL_SMTP_PORT`, `CLOUDRON_MAIL_SMTP_USERNAME` and `CLOUDRON_MAIL_SMTP_PASSWORD`, and to `CLOUDRON_MAIL_FROM` for the sender address. The sender name always comes from `terms.app_name`.
- Mail is queued. `scripts/deploy/cloudron-run.sh` starts the Redis `queue:work` worker and `schedule:work`, which runs `mail:prune-log`, `audit:prune`, `inbound-mail:prune`, `inbound-mail:empty-folders` and, every minute, `tickets:inbound-poll`.
- Locally, `.env.example` (the Laragon profile) sends SMTP to Laragon's Mailpit on `127.0.0.1:1025`; read the messages at `http://localhost:8025`. Nothing is delivered. The agent-only profile (`.env.local.example`) uses the `log` mailer, which writes messages to `storage/logs`, and so does the default when no `MAIL_MAILER` is set.

## Replies by email

Ticket and case owners and staff can answer a ticket or case email by replying to it ([#589](https://github.com/APESCIC/MyAPES-Account/issues/589), v0.59.0). Replies go to one mailbox, `communications@myapes.me.uk`, which the app polls over IMAP on `mail.cloudron.apes.org.uk:993` with SSL/TLS. User-facing guide: wiki [Replies by email](https://github.com/APESCIC/MyAPES.Account/wiki/Replies-by-email).

### Settings

Administrators manage the mailbox on **Admin → Emails → Import** (v0.63.0; it was the Reply mailbox tab of Admin → Settings in v0.61.0, and `/admin/settings/reply-mailbox` now redirects there with a 301). `admin.settings.view` shows the page and its menu item, `admin.settings.manage` changes and tests it; both are administrator-only. The page has the connection form, the last test result, the import status (last test, last successful check, the next check while backing off, the plus-address probe), the readiness checklist and the recent connection log.

The form holds on/off, address, IMAP server, port, encryption, certificate check, username, password (write-only, encrypted with the app key in `app_settings`), folder and whether plus-addressing is delivered. A fresh install starts with `mail.cloudron.apes.org.uk`, port 993 and **SSL/TLS (port 993)**, from `config/inbound-mail.php`. Saving or testing port 993 with STARTTLS, or port 143 with SSL/TLS, is refused with a message saying which to choose; **None** is offered only on local and testing installs. The username is the full mailbox address; the password is the mailbox owner's Cloudron Mail Client app password from APES Vault ('API Keys & Tokens'); alias logins do not work. A non-empty value in the environment (`config/inbound-mail.php`, read through `config/app-settings.php`) always wins and the field shows **Set by .env**. The token key, authserv-id and limits are environment-only. Nothing secret is committed. With replies off or no token key, outgoing mail keeps the no-reply Reply-To and the poller does nothing.

### Connection test and error types

**Test connection** tests the values in the form, saved or not, and before replies are switched on (`App\Core\Mail\Inbound\Diagnostics\MailboxDiagnostics`, v0.63.0). It runs five steps and stops at the first failure: server name (DNS), connection (TCP), encryption (TLS or STARTTLS), login, then folder. Each step shows passed, failed or skipped with its time in ms. A blank password uses the saved one, but only when the server, port, encryption and username are the saved ones and the certificate check is not turned off; otherwise the password must be typed, so a test can never send the saved password to another server. A typed password is used in memory for that one test and is never stored, logged, cached, audited or sent back to the page. Each administrator can run 5 tests every 10 minutes. Each test is audited as `admin.emails.import_tested` with the result type, step and tested host only.

A passing test shows the message and unread counts in the folder, whether the server offers IDLE, MOVE and UIDPLUS, and the certificate name and expiry date. A failure shows one of these types, with what to try. The result panel uses `role=status` and works without JavaScript.

| Type | Message | What to try |
| --- | --- | --- |
| `dns_not_found` | The server name was not found. | Check the spelling of the IMAP server. On the APES Cloudron it is `mail.cloudron.apes.org.uk`. |
| `connection_refused` | The server refused the connection. | Check the port. On the APES Cloudron IMAP is on port 993; ask IT if a firewall blocks it. |
| `timeout` | The server did not answer in time. | Check the server name and port, then try again. If it keeps happening, ask IT whether the mail server is up. |
| `wrong_encryption` | Wrong encryption for this port. | Use SSL/TLS with port 993, or STARTTLS with port 143. The APES Cloudron offers IMAP on 993 with SSL/TLS only. |
| `certificate_name_mismatch` | The server's certificate name does not match. | Use the name on the certificate as the IMAP server, for example `mail.cloudron.apes.org.uk` rather than an alias. |
| `certificate_untrusted` | The server's certificate has expired or is not trusted. | Ask IT to renew the mail certificate on Cloudron. Only turn off the certificate check for local testing. |
| `handshake_failed` | The encryption handshake failed. | Check the encryption and port, then try again. If it keeps happening, ask IT to check the mail server. |
| `login_failed` | The server refused the username or password. | Use the full mailbox address as the username and the mailbox owner's Cloudron Mail Client app password. Alias logins do not work. |
| `too_many_attempts` | The server has had too many attempts. | Wait 15 minutes before trying again, then check the password. |
| `folder_not_found` | The folder was not found. | Choose one of the folders the server has (the panel lists them). Most mailboxes use INBOX. |
| `feature_missing` (warning) | The server is missing a feature the import uses. | Imports still work, a little more slowly. Ask IT whether the mail server can offer MOVE and UIDPLUS. |

The wording lives in `lang/en_GB/admin.php` under `emails.import.errors`. No raw server text, username or password appears in any message.

### Readiness checklist

The Import page lists, with a tick or a cross: the reply token key is set on the server, replies by email are on, the mailbox address is valid, a password is set, the last connection test passed, the scheduler checked the mailbox in the last 5 minutes (a heartbeat written by every `tickets:inbound-poll` run) and the plus-address probe arrived. Each cross says who fixes it: an administrator on the page, or IT on Cloudron (the token key, the scheduler and any field set in `.env`). Secrets show only as set or not set (`App\Core\Mail\Inbound\ImportReadiness`).

### Folders, UIDs, lock and backoff

- **Folders.** Stored mail (and duplicates) move to the mailbox's `Processed` folder with IMAP MOVE, or COPY then delete when the server has no MOVE. A message that fails to store 3 times moves to `Failed`. Both folders are created when missing. `inbound-mail:empty-folders` runs daily and deletes mail older than 30 days from both (`INBOUND_MAIL_FOLDER_RETENTION_DAYS`).
- **UIDs.** `inbound_mailbox_states` keeps the last UID handled per mailbox and folder with its UIDVALIDITY (the mailbox is stored as a SHA-256 hash of the username). Each poll fetches only higher UIDs; when UIDVALIDITY changes the folder is read again and the Message-ID hash skips anything already imported.
- **Lock and timeouts.** `tickets:inbound-poll` keeps `withoutOverlapping`, and a cache lock (`inbound-mail:poll-lock`) stops **Fetch replies now** overlapping a scheduled poll. Connect and read timeouts are 15 seconds (`INBOUND_MAIL_IMAP_TIMEOUT`). The batch stays at 50.
- **Backoff.** After failed polls in a row, scheduled polls wait 1, 2, 5, 10, then 15 minutes before connecting again; the next successful poll resets it. The Import page shows when the next check will run. **Fetch replies now** does not wait.

### Alerts and health

After 3 failed polls in a row, or 15 minutes of failures while replies are on, an incident opens: administrators see a banner on every page and get one **Email import stopped** email (`inbound.import_failing`). The next successful poll closes it, clears the banner and sends one **Email import working again** email (`inbound.import_recovered`). Suspended accounts are not emailed. `/healthz` reports `checks.inbound_mail` as `ok`, `failing` (an incident is open) or `off` (polling not set up), never a host, user or error; like `checks.queue` it is reported, not fatal.

### Connection log

The Import page's **Recent connection log** keeps the newest 50 tests and polls (`inbound_mailbox_log`): time, kind, step reached, failure type, duration and counts. It never holds a host, username, password, IMAP line, subject or body. Entries older than 30 days are deleted by `inbound-mail:prune`.

| Variable | Meaning |
| --- | --- |
| `INBOUND_MAIL_ENABLED` | Turns replies by email on |
| `INBOUND_MAIL_ADDRESS` | The mailbox, default `communications@myapes.me.uk` |
| `INBOUND_MAIL_PLUS_ADDRESSING` | `true` when the mail server delivers `communications+token@` to the mailbox. When `false`, Reply-To is the plain address and matching uses the subject tag and In-Reply-To |
| `INBOUND_MAIL_TOKEN_KEY` | Dedicated HMAC key for reply tokens. Never `APP_KEY`. Locally: `php artisan inbound-mail:key --enable` |
| `INBOUND_MAIL_AUTHSERV_ID` | The receiving server's name in `Authentication-Results`. Only that header is trusted; empty trusts the topmost header |
| `INBOUND_MAIL_REQUIRE_AUTH` | Require SPF or DKIM to pass with DMARC not failing (default `true`) |
| `INBOUND_MAIL_IMAP_HOST`, `_PORT`, `_ENCRYPTION`, `_USERNAME`, `_PASSWORD`, `_FOLDER` | The mailbox over IMAP. Defaults: `mail.cloudron.apes.org.uk`, 993, `ssl`, `INBOX`. No username or password means no polling |
| `INBOUND_MAIL_IMAP_TIMEOUT` | Seconds to connect and to wait for each answer (default 15) |
| `INBOUND_MAIL_RETENTION_DAYS` | Days raw messages are kept (default 30) |
| `INBOUND_MAIL_FOLDER_RETENTION_DAYS` | Days mail stays in the mailbox's `Processed` and `Failed` folders (default 30) |

### Outgoing ticket and case emails

`TicketUpdatedNotification`, `ApesCicCaseUpdatedNotification` and `ShelterCaseUpdatedNotification` use `App\Core\Mail\Concerns\OffersEmailReply`. When replies are on for that service, each message gets:

- `Reply-To: communications+t1234.<token>@myapes.me.uk`. The token is the first 20 hex characters of an HMAC-SHA256 of the record prefix, record id and recipient's user id under `INBOUND_MAIL_TOKEN_KEY`, so it only works for that person and that record (`App\Core\Mail\Inbound\ReplyToken`).
- A `[MYAPES-T-1234]` subject tag (`C` for cases).
- A stored `Message-ID` (`mail_reply_threads`), so In-Reply-To and References can be matched.
- `Auto-Submitted: auto-generated` and `X-Auto-Response-Suppress: All` (these two are always set).
- A "Reply above this line" marker at the top and footer wording "Reply to this email, or: Log in to reply".

### How a reply is handled

1. `tickets:inbound-poll` (every minute, no overlap, with backoff after failures) fetches up to 50 messages with a UID above the last one handled, stores each raw message on the private disk with an `inbound_emails` row keyed by a hash of its Message-ID, queues `ProcessInboundEmail`, and only then moves it to the mailbox's `Processed` folder. A duplicate Message-ID is never stored twice; an IMAP outage or a failed store leaves mail in the folder for the next poll, and the third failed store moves it to `Failed`.
2. `App\Core\Mail\Inbound\InboundMailProcessor`:
   1. **Ignore** machine mail (`Protection\LoopGuard`): `Auto-Submitted` other than `no`, `X-Autoreply` and similar, `Precedence: bulk/list/junk`, `List-Id`, bounces (`Return-Path: <>`, `MAILER-DAEMON`, `multipart/report`), out-of-office subjects, and our own addresses. Ignored mail is never answered.
   2. **Match** the record (`Matching\ReplyMatcher`, v0.61.0): the token in To/Cc/Delivered-To (only when plus-addressing is on), then a stored Message-ID in In-Reply-To/References, then the subject tag. When they point to different records the email is quarantined as `conflicting_reference`. Unmatched or conflicting mail from a known, active, authenticated account holder gets one `EmailReplyNotPostedNotification` an hour; unknown senders get nothing.
   3. **Service switch**: off means quarantined (`service_off`).
   4. **Check the sender** (`Verification\SenderVerifier`): SPF or DKIM pass and DMARC not `fail`; an account with the From address; not suspended; the token's or thread's recipient; and owner or eligible staff (`Verification\InboundReplyEligibility`, which checks roles and service choices directly because the queue worker has no sign-in session).
   5. **Closed** record: rejected, with one `EmailReplyNotPostedNotification` (marked `Auto-Submitted: auto-replied`) at most once an hour per sender.
   6. **Hourly limits** (`Protection\InboundRateLimits`): 20 posts per sender, 30 per record (`INBOUND_MAIL_SENDER_LIMIT`, `INBOUND_MAIL_RECORD_LIMIT`).
   7. **Extract** the text (`Parsing\ReplyTextExtractor`): cut at the marker, drop quoted text and signatures with `willdurand/email-reply-parser`, and convert HTML-only mail to text. Empty replies are rejected. The text is stored escaped; no sender HTML is kept.
   8. **Attachments** (`InboundAttachmentSorter`): the v0.58 types, sizes, counts and 100 MB total, private disk, normal scan status and download rules. Inline signature images are skipped; others are turned away with a reason in `detail`.
   9. **Post** through the plugin's `InboundReplyHandler` (`TicketEmailReplyHandler`, `CaseEmailReplyHandler`): always a public ticket message or case update with `via_email = true`, shown with a **by email** badge, and the usual notifications.

Plugins register handlers with `PluginServiceProvider::inboundReplyHandlers()`; Core never imports plugin classes.

### Statuses

`received`, `posted`, `ignored`, `quarantined`, `rejected`, `failed`, `discarded`. The reason codes and their wording are in `lang/en_GB/admin.php` under `emails.inbound.reasons`. Quarantined and failed mail can be retried or discarded on Admin → Emails → Inbound (audited as `admin.emails.inbound_retried` and `admin.emails.inbound_discarded`); switching a service is audited as `admin.emails.inbound_service_changed`. A quarantined reply can be posted with **Attach to ticket or case**, as the sender's account or one staff choose (never as an unknown person), on an open record; it is audited as `admin.emails.inbound_attached` without the message body. Reply mailbox saves, connection tests and probes on Admin → Emails → Import are audited as `admin.settings.mailbox_saved`, `admin.emails.import_tested` (result type and step) and `admin.settings.mailbox_probe_sent`, with field names only.

### Retention

`inbound-mail:prune` runs daily. After `INBOUND_MAIL_RETENTION_DAYS` it deletes the raw message and clears the subject, address, name, notes and Message-ID, keeping status, reason, record, sender account id, hashed address and domain. Message-ID threads are deleted after 180 days, and connection log entries after 30 days. `inbound-mail:empty-folders` also runs daily and deletes mail older than 30 days from the mailbox's `Processed` and `Failed` folders. The privacy notice has a "Replies by email" section.

### Cloudron mailbox runbook

Never put a password, token or key in an issue, a commit, chat or a log. Record results on [#754](https://github.com/APESCIC/MyAPES.Account/issues/754) (values only).

1. **Mail server.** In the Cloudron dashboard (Email → Settings, and the mailbox info) confirm IMAP is `mail.cloudron.apes.org.uk`, port 993, SSL/TLS. Cloudron offers IMAP on 993 with SSL/TLS only: there is no IMAP on 143, and STARTTLS on 993 never works. The Cloudron dashboard host is not the IMAP server.
2. **Mailbox and owner.** In the Cloudron dashboard (Email → myapes.me.uk → Mailboxes; the `cloudron` CLI cannot create mailboxes) the `communications@myapes.me.uk` mailbox is owned by a dedicated service user, not a person and not the `myapesaccount.staff` group: a group-owned mailbox only accepts a member's own password. Signed in as that service user, create an app password of type **Mail Client** (Profile → App passwords) and store it only in APES Vault, collection 'API Keys & Tokens'.
3. **Reachability.** In the app's Cloudron Web terminal run `getent hosts mail.cloudron.apes.org.uk` and `openssl s_client -connect mail.cloudron.apes.org.uk:993 -servername mail.cloudron.apes.org.uk -crlf -brief </dev/null`. Expect an address, a certificate naming `mail.cloudron.apes.org.uk` and a `* OK` greeting. No proxy (such as Cloudflare) may sit in front of the mail host.
4. **Plus-addressing.** From a mailbox outside Cloudron, send one email to `communications+v060test@myapes.me.uk` (check the spelling). If it arrives, plus-addressing is delivered: tick **Plus-addressing delivered** on Admin → Emails → Import (or set `INBOUND_MAIL_PLUS_ADDRESSING=true`). If it bounces, leave it off (`false`): Reply-To becomes the plain address and replies are matched by their stored Message-ID and the `[MYAPES-T-1234]` subject tag. Once the mailbox is set up, **Send a plus-address probe** on the same tab repeats this check from inside the app. Result on 12 Nov 2026: the test email arrived, so plus-addressing is delivered and **Plus-addressing delivered** is on by default (`INBOUND_MAIL_PLUS_ADDRESSING=true`).
5. **Spam folder.** The poller reads INBOX only. Look in the mailbox's Spam folder for the test emails; if they land there, fix the spam rule before switching replies on.
6. **Authentication-Results.** Open a received test email's raw headers and note the server name at the start of its `Authentication-Results` header (name only). Set `INBOUND_MAIL_AUTHSERV_ID` to it, so only that server's results are trusted.
7. **Token key.** Set `INBOUND_MAIL_TOKEN_KEY` (`openssl rand -hex 32`) and `INBOUND_MAIL_AUTHSERV_ID` in the app's env file, `/app/data/shared/.env`, remove any old `INBOUND_MAIL_IMAP_*` values there that point at the dashboard host instead of `mail.cloudron.apes.org.uk`, then restart the app. The token key stays in the env file; it is not on the Import page.
8. **Mailbox details.** On Admin → Emails → Import check the prefilled server (`mail.cloudron.apes.org.uk`, port 993, **SSL/TLS (port 993)**), enter the username `communications@myapes.me.uk` and the Mail Client app password from APES Vault, keep the folder `INBOX`, and press **Test connection** before saving: every step should pass. Then tick **Turn on replies by email** and save. The password is encrypted in the database and never shown again. Values in `/app/data/shared/.env` still win and show as **Set by .env**; remove them there if you want to manage them in Admin.
9. **Folders.** The first poll creates `Processed` and `Failed` in the mailbox if they are missing; you can also create them in the mail client.
10. **Check.** The readiness checklist on the Import page shows every tick. Reply to a ticket email from a client account and check that it appears on Admin → Emails → Inbound and on the ticket, and that the email moved to `Processed`.

### Local testing

See [Laragon: Replies by email](laragon.md#replies-by-email). With `INBOUND_MAIL_DRIVER=mailpit` the poller reads Laragon's Mailpit through `Mailpit\MailpitMailbox` (local or testing, loopback URL only), `tickets:inbound-reply-as` and the **Local test replies** panel on Admin → Emails → Inbound reply as a client through `Local\ClientReplyBuilder`, and `tickets:inbound-sample` with `tickets:inbound-ingest` still work from files.

## Tests

| Area | Test |
| --- | --- |
| Registry, labels, sample render | `tests/Feature/Mail/MailTemplateRegistryTest.php` |
| Every template branded, queued, secret-free, logged, preference-gated; Admin persona gates | `tests/Feature/Mail/MailTemplateMatrixTest.php` |
| Queueing and after-commit | `tests/Feature/Mail/OutboundMailQueueTest.php` |
| Auth brand kit | `tests/Feature/Auth/AuthEmailBrandKitTest.php` |
| Domain and reminder branding | `tests/Feature/Mail/BrandedDomainMailTest.php` |
| Recruitment lifecycle | `tests/Feature/RecruitmentLifecycleMailTest.php` |
| Admin Emails list and preview | `tests/Feature/AdminEmailsTest.php` |
| Send test and resend verification | `tests/Feature/AdminEmailSendTestAndResendTest.php` |
| Outbound log and retention | `tests/Feature/Mail/OutboundMailLogTest.php` |
| Email preference | `tests/Feature/Mail/OptionalMailPreferenceTest.php` |
| Wording overrides | `tests/Feature/Mail/MailCopyOverridesTest.php` |
| Replies by email: outgoing headers, poller, matching and sender checks, text extraction, loop and rate limits, posting, attachments, Admin Inbound, retention, local harness, Mailpit driver, reply builder, local panel, triage, end-to-end routing with plus-addressing on and off | `tests/Feature/Mail/Inbound/*Test.php` with fixtures in `tests/Fixtures/InboundMail/` and from `ClientReplyBuilder` |
| Admin → Emails → Import: store, form, defaults and port guard, resolve, test connection, probe, audit, page and menu, readiness | `tests/Feature/Settings/AppSettingsTest.php`, `tests/Feature/Admin/*Settings*Test.php`, `tests/Feature/Admin/ReplyMailbox*Test.php`, `tests/Feature/Admin/EmailImportPageTest.php`, `tests/Feature/Admin/ImportReadinessTest.php`, `tests/Feature/Mail/Inbound/InboundMailSettingsResolveTest.php` |
| Email import (v0.63.0): five-step diagnostics with a fake IMAP server, error types, IMAP session and socket transport, folders, UIDs, lock and backoff with a hanging server, alerts and healthz, connection log and redaction | `tests/Feature/Mail/Inbound/MailboxDiagnosticsTest.php`, `MailboxFailureTypesTest.php`, `ImapTransportTest.php`, `InboundMailFoldersTest.php`, `InboundMailUidTrackingTest.php`, `InboundMailBackoffTest.php`, `InboundImportAlertsTest.php`, `ConnectionLogTest.php`, with `tests/Fakes/FakeImapServer.php` |
