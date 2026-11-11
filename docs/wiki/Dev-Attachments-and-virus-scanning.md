> This page is synced from `docs/wiki` in the repository. Edits made here are overwritten on the next sync; suggest changes through a pull request or in [Discussions (Docs and guides)](https://github.com/APESCIC/MyAPES.Account/discussions/categories/docs-and-guides).

# Attachments and virus scanning

**Audience:** developers and admins  
**Purpose:** how ticket and case uploads work, their limits, and what each scan status means. Introduced in v0.58.0.

## What people can upload

Tickets, ticket replies, cases and case updates in every module take three kinds of file. The limits live in `App\Core\Attachments\AttachmentLimits`.

| Kind | Types | Limit |
| --- | --- | --- |
| Screenshots | JPEG, PNG, WebP | 10 files, 5 MB each |
| Screencasts | MP4, WebM | 3 files, 50 MB each |
| Documents | PDF, DOCX, XLSX, ODT, ODS, TXT, CSV | 10 files, 10 MB each |

One submission can be at most 100 MB in total. Macro-enabled Office files (`.docm`, `.xlsm` and so on), legacy `.doc` and `.xls`, and executables are refused. Screenshots are resized to at most 2560 px and re-encoded, which removes EXIF data such as GPS location; the original is not kept.

Files are stored on the private disk under a random name. Downloads go through `support.attachments.download`, which checks the record's permissions, staff-only visibility and the scan status.

The PHP upload limits for these sizes are set in `public/.htaccess` (`upload_max_filesize 52M`, `post_max_size 110M`, `max_file_uploads 30`). Values set in `.htaccess` win over `/app/data/php.ini` for web requests. Use `attachments:diagnose` (below) to check them.

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

No virus scanner is set up for MyAPES Account. `ATTACHMENT_SCANNER` stays at its default, `disabled`, so new files are marked `not_scanned` and can be downloaded. The test suite uses `fake`, which flags `FakeScanner::TEST_SIGNATURE`.

Files left `pending` (for example from a scan that could not finish) cannot be downloaded. Queue them again with:

```bash
php artisan attachments:scan-pending
```

## Checking the set-up

`attachments:diagnose` checks the scanner setting and the PHP upload limits, and exits with an error when an upload limit is short. With no scanner, it reports scanning as off rather than as an error.

```bash
php artisan attachments:diagnose
```

On Cloudron, run it as the web user from the current release:

```bash
cloudron exec --app myaccount.myapes.me.uk -- sudo -u www-data /usr/bin/php8.4 /app/data/current/artisan attachments:diagnose
```

Commands run under the PHP CLI, where `public/.htaccess` does not apply, so the command reports the `.htaccess` value (used by Apache's `mod_php` for web requests) next to the `php.ini` value. The LAMP app runs PHP as `mod_php`, so the `.htaccess` limits are the ones that count for uploads. A real upload of about 45 MB to a test ticket is the final check.
