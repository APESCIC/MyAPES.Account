# Demo data

The demo data fills every main area of MyAPES Account with clearly fake records, so you can click through, test and file bug reports against the same data. It only runs on a local Herd install (`APP_ENV=local`).

```powershell
composer demo:seed    # add or refresh the demo data
composer demo:reset   # wipe myapes_account_local, migrate and reload it
```

Both run `php artisan myapes:demo-data` (with `--reset` for the reset). The data comes from [`database/seeders/DemoDataSeeder.php`](../../database/seeders/DemoDataSeeder.php).

## Test logins

Every demo account uses the same fake, local-only password:

```text
Local-Demo-Only-2026!
```

It is not a real password, works only against your local database, and must never be used anywhere else.

| Email | Role | Sign in at |
| --- | --- | --- |
| `admin@example.test` | Administrator | `/staff/login` |
| `staff@example.test` | Staff | `/staff/login` |
| `hr@example.test` | Staff + Human resources (recruitment) | `/staff/login` |
| `vet@example.test` | Staff + Vet (consultations) | `/staff/login` |
| `vetnurse@example.test` | Staff + Vet nurse (consultations) | `/staff/login` |
| `reception@example.test` | Staff + Receptionist | `/staff/login` |
| `volunteer@example.test` | Volunteer | `/staff/login` |
| `student@example.test` | Student | `/staff/login` |
| `member@example.test` | Service user (public) | `/login` |

In local, `/staff/login` shows a direct email and password form for these accounts.

### The QA fixtures and `/login`

By default the demo data also loads the existing local QA fixtures (`database/seeders/LocalQaSeeder.php`), because the in-app QA role switcher uses them. While they exist, opening `/login` signs straight in as the QA service user. To try the public sign-in form with `member@example.test`, load the demo data without them:

```powershell
composer demo:reset -- --without-qa
```

The QA role switcher needs the QA fixtures, so it does not work in that mode.

## What is included

| Area | Records |
| --- | --- |
| Accounts | The 9 logins above, plus 40 service users on `example.org` and `example.com` with profiles, contact preferences and service selections |
| APES CIC tickets | 25 across IT, web, governance, HR and other topics, each with 2 to 4 messages |
| APES Shelter and Rescue tickets | 25 across adoption, surrender, rescue, fostering and welfare |
| APES Pet Care Clinic tickets | 25 across appointments, consultations, prescriptions, billing and follow-up |
| APES CIC cases | 22 across membership, welfare, complaints, subject access and privacy requests, with public and internal updates |
| Shelter pets and cases | 22 pet profiles and 22 adoption, surrender, rescue and fostering cases, with updates |
| Pet Care Clinic | 22 pet profiles and 25 consultations, assigned to the vet and vet nurse |
| Recruitment | 24 roles (22 open, 1 draft, 1 closed) and 30 applications in every status |
| Call logs | 20 inbound and outbound calls |
| Attachments | 10 placeholder PNG screenshots on APES CIC tickets, written to `storage/app/public/demo/` (git-ignored) |

Every paginated list has more than one page.

## How the data is kept fake and repeatable

- **Fake people:** names come from Faker's `en_GB` locale. Emails use only `example.test`, `example.org` and `example.com`.
- **Fake contact details:** phone numbers come from Ofcom's drama ranges (`07700 900xxx` and `020 7946 0xxx`). Towns are made up, and postcodes use the non-geographic `ZZ99` prefix.
- **Fake animals and records:** pet names, species and every ticket, case and consultation are invented. Titles start with `Demo:`.
- **No real data:** nothing is copied from production or from real APES records.
- **Repeatable:** Faker uses a fixed seed (`DemoDataSeeder::SEED`), timestamps come from a fixed base time (1 October 2026, 09:00 UK time), and every record is upserted on a stable key. Running `composer demo:reset` twice gives the same data, and `composer demo:seed` again adds no duplicates.
- **Local only:** the command and the seeder refuse to run unless `APP_ENV=local`, when a `CLOUDRON_*` variable is set, or when the MySQL database name does not end in `_local`. `DatabaseSeeder`, deploys and Cloudron scripts never call it.
