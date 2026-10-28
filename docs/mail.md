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
| `admin.emails.view` | Administrator, Super Admin | List templates, open a template, preview it, read the outbound log |
| `admin.emails.manage` | Super Admin only | Send a test, edit and reset wording |

- **Preview** renders the sample with the viewing admin's name inside a sandboxed iframe (`/admin/emails/{key}/preview`, served with its own `sandbox` Content Security Policy). Nothing is sent.
- **Send test** sends the sample to the acting admin's own address only, immediately and on the mail channel only, so no in-app notification is written. Limited to 5 tests per admin every 10 minutes and audited as `admin.emails.test_sent`.
- **Resend verification** is on the account page (Admin → Public users → account) for Public Login accounts whose email is not verified, gated by `admin.users.manage`. Each resend builds a brand-new signed link; older links are never reused. Limited to 3 per account per hour and audited as `auth.verification_resent`.

## Wording overrides

For templates with `subjectKey` or `introKey`, a Super Admin can change the subject and opening line on the template page.

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
- Mail is queued. `scripts/deploy/cloudron-run.sh` starts the Redis `queue:work` worker and `schedule:work`, which runs `mail:prune-log` and `audit:prune`.
- Locally, `.env.example` sends SMTP to `127.0.0.1`, where Laragon's Mailpit catches it. With no `MAIL_MAILER` set, the default `log` mailer writes messages to `storage/logs`.

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
