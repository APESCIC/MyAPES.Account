> This page is synced from `docs/wiki` in the repository. Edits made here are overwritten on the next sync; suggest changes through a pull request or in [Discussions (Docs and guides)](https://github.com/APESCIC/MyAPES.Account/discussions/categories/docs-and-guides).

# Roles and access

**Audience:** admins and developers  
**Purpose:** Admin, Staff, Volunteers, Students, Vet and Vet Nurse roles; Cloudron groups; staff and public accounts.

## Protected roles

Every account has exactly one protected role. Staff-side roles come from Cloudron groups and cannot be changed by hand.

| Role | Cloudron group | Account type |
| --- | --- | --- |
| Admin | `myapesaccount.admin` | Staff side |
| Staff | `myapesaccount.staff` | Staff side |
| Volunteer | `myapesaccount.volunteer` | Staff side |
| Student | `myapesaccount.student` | Staff side |
| Public | none (local sign-up) | Public |

Staff and public accounts are separate (v0.54). A member of staff who is also a client, for example a pet owner, uses a separate public account for their own records.

## Job roles

Job roles add abilities on top of a staff-side protected role. Admins assign them in **Admin > Users > (user) > Roles**, or map a Cloudron group to them in **Admin > Access > Groups**.

| Job role | Default Cloudron group | Grants |
| --- | --- | --- |
| Human Resources | mapped by an admin (for example `myapesaccount.hr`) | Manage recruitment vacancies and applications |
| Vet | `myapesaccount.vet` | Create and edit consultations |
| Vet Nurse | `myapesaccount.vetnurse` | Create and edit consultations (same rights as Vet) |
| Board Of Directors, Management, Client Services Advisor, Receptionist | mapped by an admin | Analytics and user views |

The Vet and Vet Nurse mappings are created once, when the roles are first added. An admin can remove or change them afterwards. A job role only applies to someone who is also in a staff-side group, normally `myapesaccount.staff`.

## Consultations (v0.57)

| Who | Can do |
| --- | --- |
| Vet, Vet Nurse, Admin | Create and edit consultations, and clear the "Needs vet review" flag |
| Staff, volunteers, students | View consultations; assign and close when their group allows it |
| Public users | View their own pets' consultations, and ask for one by opening a Pet Care Clinic ticket |

Consultations created by public users before v0.57 are kept. An admin can flag them for vet review with `php artisan consultations:flag-public-created --dry-run`, then run it again without `--dry-run` to confirm.

## Per-group access

Admins can turn off a plugin's default abilities for Staff, Volunteers or Students in **Admin > Access > Matrix**. The matrix can only take abilities away, and never affects admins.

Related: [README – route and permission contracts](https://github.com/APESCIC/MyAPES.Account/blob/main/README.md#core-architecture)
