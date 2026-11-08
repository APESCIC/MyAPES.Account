> This page is synced from `docs/wiki` in the repository. Edits made here are overwritten on the next sync; suggest changes through a pull request or in [Discussions (Docs and guides)](https://github.com/APESCIC/MyAPES.Account/discussions/categories/docs-and-guides).

# Attachments and virus scanning

**Audience:** developers and admins  
**Purpose:** how ticket and case uploads work, and how to turn on ClamAV scanning on Cloudron. Introduced in v0.58.0.

## What people can upload

Tickets, ticket replies, cases and case updates in every module take three kinds of file. The limits live in `App\Core\Attachments\AttachmentLimits`.

| Kind | Types | Limit |
| --- | --- | --- |
| Screenshots | JPEG, PNG, WebP | 10 files, 5 MB each |
| Screencasts | MP4, WebM | 3 files, 50 MB each |
| Documents | PDF, DOCX, XLSX, ODT, ODS, TXT, CSV | 10 files, 10 MB each |

One submission can be at most 100 MB in total. Macro-enabled Office files (`.docm`, `.xlsm` and so on), legacy `.doc` and `.xls`, and executables are refused. Screenshots are resized to at most 2560 px and re-encoded, which removes EXIF data such as GPS location; the original is not kept.

Files are stored on the private disk under a random name. Downloads go through `support.attachments.download`, which checks the record's permissions, staff-only visibility and the scan status.

The PHP upload limits for these sizes are set in `public/.htaccess` (`upload_max_filesize 52M`, `post_max_size 110M`, `max_file_uploads 30`). If `/app/data/php.ini` on Cloudron sets lower values, raise them there too.

## Turning uploads off for a module

Admin → Modules has a **Turn uploads off** button for each module (APES CIC, Shelter and Rescue, Pet Care Clinic). With uploads off, the upload fields are hidden and any files sent are refused. Existing attachments stay available. APES CIC subcategories still have their own "allows attachments" setting on Admin → Plugins.

## Who can do what

- The record's owner and staff who can see all records in that module can download.
- Attachments added with a staff-only note or internal case update are hidden from clients.
- Staff-side accounts upload as staff, never as a client.
- Only the person who uploaded a file, or an admin, can delete it. Deletion is soft: the row and file stay, and the attachment disappears from the record.
- Uploads, downloads, deletions and quarantines are written to the audit log.

## Virus scanning

Each new upload is queued for a scan. The scan status is one of:

| Status | Meaning | Downloadable |
| --- | --- | --- |
| `pending` | Waiting for the scanner | No |
| `clean` | Scanned, nothing found | Yes |
| `infected` | Moved to `quarantine/` on the private disk; the uploader is emailed | No |
| `not_scanned` | No scanner configured, or uploaded before v0.58.0 | Yes |

The scanner is chosen with environment variables:

| Variable | Default | Notes |
| --- | --- | --- |
| `ATTACHMENT_SCANNER` | `disabled` | `disabled`, `clamd` or `fake` (tests only; flags `FakeScanner::TEST_SIGNATURE`) |
| `CLAMD_HOST` | empty | Host name or IP of the clamd daemon |
| `CLAMD_PORT` | `3310` | clamd `TCPSocket` |
| `CLAMD_TIMEOUT` | `30` | Seconds per scan |

With `disabled`, files are marked `not_scanned` and can be downloaded. This is the production setting until a clamd host exists.

### Setting up ClamAV for Cloudron

Cloudron has no ClamAV add-on, so clamd runs outside the MyAPES Account app and the app reaches it over TCP.

1. Run ClamAV's daemon on a server the Cloudron app can reach on a private network, for example the official `clamav/clamav` container on the Cloudron host or a small VM.
2. In `clamd.conf`, listen on TCP and allow streams large enough for a screencast:
   ```
   TCPSocket 3310
   TCPAddr 0.0.0.0
   StreamMaxLength 55M
   ```
3. Allow port 3310 only from the Cloudron server. Never expose clamd to the internet.
4. Check freshclam is updating signatures.
5. On the MyAPES Account app, add these lines to `/app/data/.env` (Cloudron File manager or `cloudron exec`), then restart the app:
   ```
   ATTACHMENT_SCANNER=clamd
   CLAMD_HOST=<clamd host>
   CLAMD_PORT=3310
   ```
6. Upload a test file containing the [EICAR test string](https://www.eicar.org/download-anti-malware-testfile/) to a test ticket. It should show as blocked, and the audit log should have a `support_attachment.infected` entry.

If clamd is down, scans retry for about an hour, then the file stays `pending`. Once clamd is back, queue the pending files again:

```bash
php artisan attachments:scan-pending
```

Files uploaded while scanning was `disabled` stay `not_scanned`; run `php artisan attachments:scan-pending --include-not-scanned` to scan them too.
