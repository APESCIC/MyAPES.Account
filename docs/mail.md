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
- Mail is queued. `scripts/deploy/cloudron-run.sh` starts the Redis `queue:work` worker and `schedule:work`, which runs `mail:prune-log`, `audit:prune`, `inbound-mail:prune` and, every minute, `tickets:inbound-poll`.
- Locally, `.env.example` (the Laragon profile) sends SMTP to Laragon's Mailpit on `127.0.0.1:1025`; read the messages at `http://127.0.0.1:8025`. Nothing is delivered. The agent-only profile (`.env.local.example`) uses the `log` mailer, which writes messages to `storage/logs`, and so does the default when no `MAIL_MAILER` is set.

## Replies by email

Ticket and case owners and staff can answer a ticket or case email by replying to it ([#589](https://github.com/APESCIC/MyAPES-Account/issues/589), v0.59.0). Replies go to one mailbox, `communications@myapes.me.uk`, which the app polls over IMAPS. User-facing guide: wiki [Replies by email](https://github.com/APESCIC/MyAPES.Account/wiki/Replies-by-email).

### Settings

Everything comes from the environment (`config/inbound-mail.php`); nothing secret is committed. With `INBOUND_MAIL_ENABLED=false` or no token key, outgoing mail keeps the no-reply Reply-To and the poller does nothing.

| Variable | Meaning |
| --- | --- |
| `INBOUND_MAIL_ENABLED` | Turns replies by email on |
| `INBOUND_MAIL_ADDRESS` | The mailbox, default `communications@myapes.me.uk` |
| `INBOUND_MAIL_PLUS_ADDRESSING` | `true` when the mail server delivers `communications+token@` to the mailbox. When `false`, Reply-To is the plain address and matching uses the subject tag and In-Reply-To |
| `INBOUND_MAIL_TOKEN_KEY` | Dedicated HMAC key for reply tokens. Never `APP_KEY`. Locally: `php artisan inbound-mail:key --enable` |
| `INBOUND_MAIL_AUTHSERV_ID` | The receiving server's name in `Authentication-Results`. Only that header is trusted; empty trusts the topmost header |
| `INBOUND_MAIL_REQUIRE_AUTH` | Require SPF or DKIM to pass with DMARC not failing (default `true`) |
| `INBOUND_MAIL_IMAP_HOST`, `_PORT`, `_USERNAME`, `_PASSWORD` | The mailbox over IMAPS (port 993). Empty means no polling |
| `INBOUND_MAIL_RETENTION_DAYS` | Days raw messages are kept (default 30) |

### Outgoing ticket and case emails

`TicketUpdatedNotification`, `ApesCicCaseUpdatedNotification` and `ShelterCaseUpdatedNotification` use `App\Core\Mail\Concerns\OffersEmailReply`. When replies are on for that service, each message gets:

- `Reply-To: communications+t1234.<token>@myapes.me.uk`. The token is the first 20 hex characters of an HMAC-SHA256 of the record prefix, record id and recipient's user id under `INBOUND_MAIL_TOKEN_KEY`, so it only works for that person and that record (`App\Core\Mail\Inbound\ReplyToken`).
- A `[MYAPES-T-1234]` subject tag (`C` for cases).
- A stored `Message-ID` (`mail_reply_threads`), so In-Reply-To and References can be matched.
- `Auto-Submitted: auto-generated` and `X-Auto-Response-Suppress: All` (these two are always set).
- A "Reply above this line" marker at the top and footer wording "Reply to this email, or: Log in to reply".

### How a reply is handled

1. `tickets:inbound-poll` (every minute, no overlap) fetches up to 50 messages, stores each raw message on the private disk with an `inbound_emails` row keyed by a hash of its Message-ID, queues `ProcessInboundEmail`, and only then deletes it from the mailbox. A duplicate Message-ID is never stored twice; an IMAP outage or a failed store leaves mail in the mailbox for the next poll.
2. `App\Core\Mail\Inbound\InboundMailProcessor`:
   1. **Ignore** machine mail (`Protection\LoopGuard`): `Auto-Submitted` other than `no`, `X-Autoreply` and similar, `Precedence: bulk/list/junk`, `List-Id`, bounces (`Return-Path: <>`, `MAILER-DAEMON`, `multipart/report`), out-of-office subjects, and our own addresses. Ignored mail is never answered.
   2. **Match** the record (`Matching\ReplyMatcher`): token in To/Cc/Delivered-To, then subject tag, then In-Reply-To/References.
   3. **Service switch**: off means quarantined (`service_off`).
   4. **Check the sender** (`Verification\SenderVerifier`): SPF or DKIM pass and DMARC not `fail`; an account with the From address; not suspended; the token's or thread's recipient; and owner or eligible staff (`Verification\InboundReplyEligibility`, which checks roles and service choices directly because the queue worker has no sign-in session).
   5. **Closed** record: rejected, with one `EmailReplyNotPostedNotification` (marked `Auto-Submitted: auto-replied`) at most once an hour per sender.
   6. **Hourly limits** (`Protection\InboundRateLimits`): 20 posts per sender, 30 per record (`INBOUND_MAIL_SENDER_LIMIT`, `INBOUND_MAIL_RECORD_LIMIT`).
   7. **Extract** the text (`Parsing\ReplyTextExtractor`): cut at the marker, drop quoted text and signatures with `willdurand/email-reply-parser`, and convert HTML-only mail to text. Empty replies are rejected. The text is stored escaped; no sender HTML is kept.
   8. **Attachments** (`InboundAttachmentSorter`): the v0.58 types, sizes, counts and 100 MB total, private disk, normal scan status and download rules. Inline signature images are skipped; others are turned away with a reason in `detail`.
   9. **Post** through the plugin's `InboundReplyHandler` (`TicketEmailReplyHandler`, `CaseEmailReplyHandler`): always a public ticket message or case update with `via_email = true`, shown with a **by email** badge, and the usual notifications.

Plugins register handlers with `PluginServiceProvider::inboundReplyHandlers()`; Core never imports plugin classes.

### Statuses

`received`, `posted`, `ignored`, `quarantined`, `rejected`, `failed`, `discarded`. The reason codes and their wording are in `lang/en_GB/admin.php` under `emails.inbound.reasons`. Quarantined and failed mail can be retried or discarded on Admin → Emails → Inbound (audited as `admin.emails.inbound_retried` and `admin.emails.inbound_discarded`); switching a service is audited as `admin.emails.inbound_service_changed`.

### Retention

`inbound-mail:prune` runs daily. After `INBOUND_MAIL_RETENTION_DAYS` it deletes the raw message and clears the subject, address, name, notes and Message-ID, keeping status, reason, record, sender account id, hashed address and domain. Message-ID threads are deleted after 180 days. The privacy notice has a "Replies by email" section.

### Cloudron setup

1. Create the `communications@myapes.me.uk` mailbox in the Cloudron dashboard (Email → myapes.me.uk → Mailboxes; the `cloudron` CLI cannot create mailboxes), with a Mail app password for IMAP. Check that mail to `communications+test@myapes.me.uk` arrives (plus-addressing). Record the result on the ticket.
2. Set the `INBOUND_MAIL_*` variables in the app's env file, `/app/data/shared/.env` (never in the repository or in chat), including a new `INBOUND_MAIL_TOKEN_KEY` (`openssl rand -hex 32`) and `INBOUND_MAIL_AUTHSERV_ID` set to the name the Cloudron mail server writes in `Authentication-Results`.
3. Restart the app, send a real reply, and check it on Admin → Emails → Inbound.

### Local testing

See [Laragon: Replies by email](laragon.md#replies-by-email): `inbound-mail:key --enable`, `tickets:inbound-sample` and `tickets:inbound-ingest` run the whole flow without a mailbox.

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
| Replies by email: outgoing headers, poller, matching and sender checks, text extraction, loop and rate limits, posting, attachments, Admin Inbound, retention, local harness | `tests/Feature/Mail/Inbound/*Test.php` with fixtures in `tests/Fixtures/InboundMail/` |
