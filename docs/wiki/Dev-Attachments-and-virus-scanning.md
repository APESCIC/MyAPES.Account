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

The scanner is chosen with environment variables:

| Variable | Default | Notes |
| --- | --- | --- |
| `ATTACHMENT_SCANNER` | `disabled` | `disabled`, `clamd` or `fake` (tests only; flags `FakeScanner::TEST_SIGNATURE`) |
| `CLAMD_HOST` | empty | Host name or IP of the clamd daemon |
| `CLAMD_PORT` | `3310` | clamd `TCPSocket` |
| `CLAMD_TIMEOUT` | `30` | Seconds per scan |

With `disabled`, files are marked `not_scanned` and can be downloaded.

## Checking the set-up

`attachments:diagnose` checks the scanner and the PHP upload limits, and exits with an error when something is short:

```bash
php artisan attachments:diagnose                     # scanner off is reported, not an error
php artisan attachments:diagnose --require-scanner   # scanner off is an error
```

On Cloudron, run it as the web user from the current release:

```bash
cloudron exec --app myaccount.myapes.me.uk -- sudo -u www-data /usr/bin/php8.4 /app/data/current/artisan attachments:diagnose
```

Commands run under the PHP CLI, where `public/.htaccess` does not apply, so the command reports the `.htaccess` value (used by Apache's `mod_php` for web requests) next to the `php.ini` value. The LAMP app runs PHP as `mod_php`, so the `.htaccess` limits are the ones that count for uploads. A real upload of about 45 MB to a test ticket is the final check.

## ClamAV on the Cloudron host

Cloudron has no ClamAV add-on, so clamd runs in the official `clamav/clamav` container on the Cloudron host, on Cloudron's private Docker network. The app reaches it at a fixed address on that network. No port is published, so clamd is not reachable from outside the host.

Cloudron does not manage this container. The `--restart unless-stopped` policy brings it back after a host restart; after Cloudron updates, check it is still running (step 6).

These steps need root SSH on the Cloudron host. Run them in order.

1. **Check memory and the network.** clamd needs about 1.5 GB, and briefly about twice that while it reloads signatures. Note the subnet of the `cloudron` network; it is normally `172.18.0.0/16`.
   ```bash
   free -m
   docker network inspect cloudron --format '{{range .IPAM.Config}}{{.Subnet}}{{end}}'
   docker ps --filter name=myapes-clamav
   ```
   If the subnet is not `172.18.0.0/16`, pick a free address near the top of that subnet and use it in place of `172.18.255.250` below.

2. **Write the clamd configuration.** Start from the image's own `clamd.conf` and allow streams large enough for a 50 MB screencast:
   ```bash
   mkdir -p /root/myapes-clamav
   docker run --rm clamav/clamav:stable cat /etc/clamav/clamd.conf > /root/myapes-clamav/clamd.conf
   sed -i -E 's/^#?\s*StreamMaxLength.*/StreamMaxLength 55M/' /root/myapes-clamav/clamd.conf
   grep -q '^StreamMaxLength 55M' /root/myapes-clamav/clamd.conf || echo 'StreamMaxLength 55M' >> /root/myapes-clamav/clamd.conf
   grep -E '^(TCPSocket|TCPAddr|StreamMaxLength)' /root/myapes-clamav/clamd.conf
   ```
   The last command should show `TCPSocket 3310` and `StreamMaxLength 55M`.

3. **Start the container.** No `-p` option, so nothing is published on the host's public interfaces:
   ```bash
   docker run -d --name myapes-clamav \
     --restart unless-stopped \
     --network cloudron --ip 172.18.255.250 \
     --memory 3g \
     -v /root/myapes-clamav/clamd.conf:/etc/clamav/clamd.conf:ro \
     -v myapes-clamav-db:/var/lib/clamav \
     clamav/clamav:stable
   ```
   The first start downloads the signature database, which takes a few minutes. The `myapes-clamav-db` volume keeps it across restarts and updates.

4. **Wait until it is healthy, and confirm freshclam is updating:**
   ```bash
   docker inspect --format '{{.State.Health.Status}}' myapes-clamav   # repeat until "healthy"
   docker logs --tail 40 myapes-clamav                                # look for freshclam "Database updated" or "up-to-date"
   ```

5. **Confirm it is private:**
   ```bash
   docker port myapes-clamav          # prints nothing: no published ports
   ss -tlnp | grep ':3310' || true    # nothing listening on the host's own interfaces
   ```

6. **Fleet check.** After a host restart or a Cloudron update, `docker ps --filter name=myapes-clamav` should show the container `Up (healthy)`.

**Update the image** (signatures update themselves; this is only for new ClamAV releases):

```bash
docker pull clamav/clamav:stable
docker rm -f myapes-clamav
# then run step 3 again
```

**Remove it** (turn scanning off in the app first, see below):

```bash
docker rm -f myapes-clamav
docker volume rm myapes-clamav-db
```

## Turning scanning on in the app

1. Back up the app: `cloudron backup create --app myaccount.myapes.me.uk`.
2. Add these lines to `/app/data/shared/.env` (Cloudron File manager or `cloudron exec`), keeping the file's `root:www-data` owner, then restart the app so the configuration cache is rebuilt:
   ```
   ATTACHMENT_SCANNER=clamd
   CLAMD_HOST=172.18.255.250
   CLAMD_PORT=3310
   ```
3. Run `attachments:diagnose --require-scanner`. The virus scanner row should say `OK`.
4. Upload a test file containing the [EICAR test string](https://www.eicar.org/download-anti-malware-testfile/) to a test ticket. It should show as blocked, and the audit log should have a `support_attachment.infected` entry. Delete the test ticket afterwards.
5. Scan the files uploaded while scanning was off (run artisan as the web user, as in [Checking the set-up](#checking-the-set-up)):
   ```bash
   php artisan attachments:scan-pending --include-not-scanned
   ```

To turn scanning off again, set `ATTACHMENT_SCANNER=disabled` and restart. New files are then marked `not_scanned` and can be downloaded.

If clamd is down, scans retry for about an hour, then the file stays `pending` and cannot be downloaded. Once clamd is back, queue the pending files again:

```bash
php artisan attachments:scan-pending
```
