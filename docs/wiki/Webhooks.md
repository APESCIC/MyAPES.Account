> This page is synced from `docs/wiki` in the repository. Edits made here are overwritten on the next sync; suggest changes through a pull request or in [Discussions (Docs and guides)](https://github.com/APESCIC/MyAPES.Account/discussions/categories/docs-and-guides).

# Webhooks

**Audience:** admins and developers of APES apps  
**Purpose:** how MyAPES Account tells other APES apps when something changes, and how to check the messages are real. Outgoing only; incoming webhooks come later. Introduced in v0.62.0.

## For admins
Open **Admin → Settings → Webhooks**. Only administrators can see or change it.

1. **Add endpoint:** a name, the app's **https://** address, the **organisation** and the **events** it wants. Plain `http`, `localhost` and private network addresses are refused.
2. **The signing secret is shown once.** Copy it into **APES Vault** (**API Keys & Tokens**) and into the receiving app. If it is lost, press **New signing secret** (the old one stops at once, so update the app at the same time).
3. The **delivery log** shows the latest 50 deliveries, each try with its HTTP status and time. **Replay** sends a delivery again with the same event id.
4. A failed delivery is retried up to **8 times over about a day** (after 1, 5 and 30 minutes, then 1, 2, 6 and 12 hours). After **5 failed deliveries in a row** the endpoint is turned off; fix the app, then **Turn on**.

Adding, changing, turning on or off, deleting and replaying are in the audit log; secrets never are.

## Events

| Event | For |
| --- | --- |
| `user.created`, `user.updated`, `user.deleted` | APES Newsroom (public accounts) |
| `consent.changed` | APES Newsroom (contact consent; `email` is the mailing-list channel) |
| `staff.updated` | APES Newsroom (staff authors) |
| `pet.created`, `pet.updated` | EASM |
| `ticket.created`, `ticket.updated` | EASM |
| `case.created`, `case.updated` | EASM |

Each endpoint gets only events for its own organisation.

## For developers
Each event is a JSON `POST`:

```json
{
  "id": "6f1c…",
  "type": "ticket.updated",
  "created_at": "2026-11-12T12:00:00+00:00",
  "organisation": "shelter-rescue",
  "data": { "ticket_id": 123, "changes": ["status"] }
}
```

The payload holds only ids, the organisation and the names of changed fields, never personal data. Read the details through the [[API|API-clients]] with your own token.

Headers:
- `X-MyAPES-Event`: the event type.
- `X-MyAPES-Event-Id`: the event id. It stays the same on retries and replays, so use it to ignore duplicates.
- `X-MyAPES-Signature: t=<unix time>,v1=<hex HMAC-SHA256>`, where the HMAC is over `<unix time>.<raw body>` with your signing secret.

**Check every request** before trusting it, and answer with any `2xx` within 10 seconds:

```php
function myapes_webhook_is_valid(string $body, string $header, string $secret, int $tolerance = 300): bool
{
    $parts = [];
    foreach (explode(',', $header) as $part) {
        [$key, $value] = array_pad(explode('=', trim($part), 2), 2, '');
        $parts[$key] = $value;
    }
    if (! isset($parts['t'], $parts['v1']) || ! ctype_digit($parts['t'])) {
        return false;
    }
    if (abs(time() - (int) $parts['t']) > $tolerance) {
        return false; // older than five minutes: a replayed request
    }

    return hash_equals(hash_hmac('sha256', $parts['t'].'.'.$body, $secret), $parts['v1']);
}
```

Use the raw request body exactly as received; decoding and re-encoding it changes the signature.

## Related
- [[API clients|API-clients]]
- [[Release and Cloudron deploy|Dev-Release-and-Cloudron-deploy]]: the queue worker that sends webhooks and the `/healthz` queue check.
