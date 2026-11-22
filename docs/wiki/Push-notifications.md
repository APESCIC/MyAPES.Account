> This page is synced from `docs/wiki` in the repository. Edits made here are overwritten on the next sync; suggest changes through a pull request or in [Discussions (Docs and guides)](https://github.com/APESCIC/MyAPES.Account/discussions/categories/docs-and-guides).

# Push notifications

**Audience:** everyone, with sections for staff and admins  
**Purpose:** turn web push on, use it safely on iPhone and iPad, unblock a browser, and set quiet hours. Introduced in v0.72.0.

## For everyone

Web push lets MyAPES Account show a short alert on your device when something needs attention — for example a ticket reply, a case update, or an account security change. It is **optional**. Email and in-app notices still work when push is off.

**Where to manage it:** open **Profile** → **Notifications** (the **Notifications** section on your profile page).

### Turn on push on this device

1. Open **Profile** → **Notifications** and scroll to **Notifications on this device**.
2. Choose **Turn on**. The browser asks for permission only after you tap — the app does not request it on its own when you open the dashboard.
3. When the browser allows it, this device appears under **Your devices**.

You can choose which **notification types** may use push or email in the table above. Push for a type only works when at least one device is subscribed.

To stop push on this device, choose **Turn off**. To remove a device from your account, use **Remove** in **Your devices**.

### iPhone and iPad (Safari)

Apple only supports web push when you open MyAPES Account from a **Home Screen icon**, not from a normal Safari tab. You need **iOS or iPadOS 16.4 or later**.

On **Profile** → **Notifications**, when the app detects Safari without a Home Screen install, it shows **Add MyAPES Account to your Home Screen** with step-by-step instructions:

1. In Safari, tap **Share** (square with an arrow).
2. Tap **Add to Home Screen**.
3. Keep **Open as Web App** switched on, then tap **Add**.
4. Open MyAPES Account from the new Home Screen icon.
5. Return to **Profile** → **Notifications** and choose **Turn on**, then allow notifications when asked.

### Unblock notifications

If you previously blocked notifications for this site, the profile page shows browser-specific steps under **Notifications are blocked for this site**. In short:

| Browser | What to do |
| --- | --- |
| Chrome | Address bar padlock or tune icon → Site settings → Notifications → Allow |
| Microsoft Edge | Padlock → Permissions for this site → Notifications → Allow |
| Firefox | Shield or padlock → Connection security → More information → Notifications → Allow |
| Safari | Safari → Settings for This Website → Notifications → Allow |

Then choose **Turn on** again on **Profile** → **Notifications**.

### Quiet hours

Under **Quiet hours**, turn on **Enable quiet hours** and set a **Start** and **End** time. Times use **Europe/London** (GMT and BST). While quiet hours are on, **push alerts pause**. Email and in-app notices are not affected.

### What appears on the lock screen

Alerts use a short title and body built from the **notification type** and a **record reference** (for example a ticket number), not the full message text from a ticket or case. That keeps sensitive detail off the lock screen when possible.

### Privacy

The [privacy notice](https://myaccount.myapes.me.uk/privacy) explains what MyAPES Account stores and what your browser's push service sees. MyAPES Account does not put message bodies on the lock screen by design; browser push services (Google, Mozilla, Microsoft, Apple) carry encrypted messages and see the device address, not the content.

## For staff, volunteers and students

- Use **Profile** → **Notifications** the same way. Available types depend on your role (tickets, cases, consultations, recruitment, staff messages, and similar).
- **New ticket divisions** (staff and volunteers): choose which divisions may send you a **new ticket** push.
- Reply to tickets and cases by email or in the app as usual; push is an extra channel, not a replacement.
- Turn on push on each browser or Home Screen app you use for work.

In-app detail: **Staff guide → Notifications** (when published in the app help tree).

## For admins

**Admin → Settings → Push notifications** (`admin.settings.view`):

| Area | Purpose |
| --- | --- |
| VAPID keys | Public and private key pair browsers need to subscribe. Prefer keys saved here (Keys and secrets); server `.env` is emergency fallback only. Generate or paste from APES Vault — never commit values. |
| Pause all pushes | Stop every web push without changing user preferences. |
| Stats | Count-only subscription and delivery figures — no names, message content or device addresses. |
| Send test | Sends a test push to devices you have subscribed on your own account first. |

Changing or clearing VAPID keys **removes every device subscription**. Everyone must turn push on again on each device. Plan comms before rotation.

Deploy and worker notes: [[Release and Cloudron deploy|Dev-Release-and-Cloudron-deploy#web-push-notifications-from-v0720]].

## For developers

- Core push code: `app/Core/Push`, service worker under `resources/push`, profile UI under `resources/views/components/push`.
- Queue: `push` (with `default` in `cloudron-run.sh`).
- Delivery metadata: `push_deliveries` — type, status and hashed subscription id only; pruned after the retention period. No payloads or keys in the table.
- Local HTTPS is required for subscribe flows on Herd: [[Local setup (Herd)|Dev-Local-setup-Herd#https-and-web-push]].
- Optional browser smoke: [docs/herd.md](https://github.com/APESCIC/MyAPES.Account/blob/main/docs/herd.md#web-push-browser-smoke-local-herd-only) (`npm run test:push-smoke`, owner opt-in).