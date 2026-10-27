# Product terminology glossary

Single source for **UI wording** in MyAPES Account. Architecture layer names stay in [architecture.md](architecture.md) and [ADR 0001](adr/0001-core-modules-plugins.md); keep the same words in both places.

Locale and key conventions: [localisation.md](localisation.md). Canonical labels live in `lang/en_GB/terms.php`. Deprecated synonyms for CI lint ([#276](https://github.com/APESCIC/MyAPES-Account/issues/276)) live in `config/glossary.php`.

**Rules**

- Display labels only — do not rename stored permission strings or live URLs for wording.
- Prefer `__('terms.*')` / `trans_choice('terms.*', $n)` once extraction waves wire screens.
- Bambie’s approval of the Language milestone plan is glossary sign-off for Wave 0b.

## Product and layers

| Canonical | Meaning / audience | Use in UI | Avoid | Lang key |
| --- | --- | --- | --- | --- |
| MyAPES Account | This application — user-facing product name (chrome, emails, page titles, public notices). | Brand, titles, emails, footer when referring to the product people use. | MyAPES Core (in public/user-facing chrome); “the Core app” as a display name. | `terms.app_name` |
| MyAPES Core | Platform / internal naming (repo history, some deploy notes, Change Log Hub provenance). Not the public product label. | Docs and internal notes only when distinguishing platform from product. Prefer “MyAPES Account” on screen. | Using Core as the default `<title>`, OG site name, or welcome headline. | `terms.platform_name` |
| Core | Always-on platform layer (auth, accounts, profiles, access, Admin shell, …). | Rarely as a standalone nav label; say Admin, Access, etc. for screens. | Calling an organisation area or plugin “Core”. | `terms.core` |
| Module | Organisation area package (`modules/<slug>`). | Admin → **Modules** (enable areas). Area hubs use the area’s public name. | sub-core; service area / Service (when it means the area); calling a feature a module in UI. | `terms.module` / `terms.modules` |
| Plugin | Reusable feature package (`plugins/<slug>`), enabled per module. | Admin → **Plugins**; empty states (“Plugins will appear here…”). | module / module type (when it means a feature); “add-on” as the primary label. | `terms.plugin` / `terms.plugins` |
| Plugin enablement | One module with one plugin switched on (e.g. `apes-cic:recruitment`). | Settings and enablement copy: “enabled for this module”. | module instance (in UI). | `terms.plugin_enablement` |
| Plugin settings | Per-enablement settings edited from Admin → Plugins. | **Settings** link / **Plugin settings** page titles. | module settings (in UI). | `terms.plugin_settings` |
| Admin | Unified staff administration shell after v0.35. | Primary nav **Admin**; page titles under `/admin`. | Super Admin as a nav door or page-family label. | `terms.admin` |
| Super Admin | Access tier / permission (`superadmin.access`) and protected role — not a separate shell. | Permission and role catalogue wording only when describing that tier. | Super Admin as primary nav or as the name of the Admin area. | `terms.super_admin` |

## Account types

| Canonical | Meaning / audience | Use in UI | Avoid | Lang key |
| --- | --- | --- | --- | --- |
| Public user | Local public account (service user). | Admin → **Public users**; filters `account_type=public`. | Service user (as the main label); citizen; customer. | `terms.public_user` / `terms.public_users` |
| Staff | Directory-backed staff, volunteer, or student accounts. | Admin → **Staff**; staff sign-in copy. | Employee-only wording that excludes volunteers/students when the screen covers all staff accounts. | `terms.staff` |

## Access (RBAC) vs Recruitment

| Canonical | Meaning / audience | Use in UI | Avoid | Lang key |
| --- | --- | --- | --- | --- |
| Job role | Access capability pack under Admin → Access (not a vacancy). | Access → **Job roles**. | Roles (alone) on Access screens when it could mean Recruitment; Recruitment role. | `terms.job_role` / `terms.job_roles` |
| Human Resources | Seeded job role (`human-resources`) that grants Recruitment manage (Roles and Applications) on top of staff. Not a vacancy and not a protected role. | Access → **Job roles**; Recruitment access matrix. | HR as a protected tier; Recruitment role. | — (job role title) |
| Access group | Managed Cloudron / directory group for authorisation. | Access → **Groups**. | Calling groups “roles”. | `terms.access_group` / `terms.access_groups` |
| Permission | Catalogue ability string (stored keys unchanged). | Access → **Permission catalogue**; display labels only. | Renaming stored keys for wording. | `terms.permission` / `terms.permissions` |
| Spatie Role | Framework role model used for Access tiers — not a vacancy. | Rarely named in UI; prefer Job role / protected role. | Role on public Recruitment pages. | — (code only) |
| Recruitment role | Vacancy / opening; model `RecruitmentRole`. | Public: **Open roles**; staff manage: **Roles** under Recruit manage. | Job role; Spatie Role; Access “Roles”. | `terms.recruitment_role` / `terms.recruitment_roles` |
| Open roles | Public board of open vacancies. | Public Recruitment submenu and board title. | Jobs board; Careers (as the product label). | `terms.open_roles` |
| My applications | Signed-in public user’s own applications. | Public Recruitment submenu. | My roles; Applications (alone when ambiguous with staff review). | `terms.my_applications` |
| Recruit manage | Staff primary nav for APES CIC recruitment manage (product-shortened). | Staff primary **Recruit manage**; Roles \| Applications submenu. | Mixing with public Recruitment nav; Access job roles. | `terms.recruit_manage` |
| Recruitment | Plugin and public primary for openings. | Public primary **Recruitment**; Admin Plugins name. | Roles as the public primary label. | `terms.recruitment` |

## Organisation areas (modules)

| Canonical | Meaning / audience | Use in UI | Avoid | Lang key |
| --- | --- | --- | --- | --- |
| APES CIC | Module `apes-cic` (prefix `/apes-cic`). | Hubs, staff chrome, enablement lists. | CIC alone when ambiguous; renaming the live prefix. | `terms.apes_cic` |
| Pet Care Clinic | Module `pet-care-clinic` (prefix `/petcare`). Full public name: APES Pet Care Clinic. | Hubs and enablement; short **Pet Care Clinic** is fine in tight chrome. | Petcare as one word in prose; renaming `/petcare`. | `terms.pet_care_clinic` |
| Shelter and Rescue | Module `shelter-rescue` (prefix `/shelter`). Full public name: APES Shelter and Rescue. | Hubs and enablement; short **Shelter** only when context is clear. | Shelter Rescue without “and”; renaming `/shelter`. | `terms.shelter_and_rescue` |

## Plugins (feature names)

| Canonical | Meaning / audience | Use in UI | Avoid | Lang key |
| --- | --- | --- | --- | --- |
| Tickets | Helpdesk / support tickets plugin. | Plugins index, hubs, nav. | Helpdesk as the product name (synonym for search only). | `terms.tickets` |
| Cases | Casework plugin. | Plugins index, hubs, nav. | Tickets (when it means cases). | `terms.cases` |
| Recruitment | Openings / applications plugin (see above). | Plugins index; public Recruitment. | Jobs as the product name. | `terms.recruitment` |
| Consultations | Clinic consultations plugin. | Plugins index, Pet Care hubs. | Appointments as the product name. | `terms.consultations` |
| Pet Profiles | Shared pet record plugin (URL segment `/pets`). | Plugins index, hubs; **Pets** in some list chrome is OK when space is tight. | Animals as the product name; renaming `/pets`. | `terms.pet_profiles` |

## Shared screens and statuses

| Canonical | Meaning / audience | Use in UI | Avoid | Lang key |
| --- | --- | --- | --- | --- |
| Dashboard | Signed-in home. | Nav **Dashboard**; title Dashboard. | Home (when the screen is the signed-in dashboard). | `terms.dashboard` |
| Profile | Account profile / onboarding. | Nav **Profile**. | Account settings (unless a dedicated settings screen exists). | `terms.profile` |
| Submitted | Application received, awaiting review. | Application status badges and filters. | Pending (unless a distinct status exists). | `terms.status.submitted` |
| Shortlisted | Application shortlisted by staff. | Status badges. | Under review (as a substitute status name). | `terms.status.shortlisted` |
| Accepted | Application accepted. | Status badges. | Approved (prefer Accepted for recruitment applications). | `terms.status.accepted` |
| Rejected | Application rejected. | Status badges. | Declined (prefer Rejected for recruitment applications). | `terms.status.rejected` |
| Draft | Recruitment role not yet published. | Staff role status. | Unpublished (as the primary status word). | `terms.status.draft` |
| Open | Recruitment role accepting applications. | Staff/public role status. | Live / Active (as the primary status word). | `terms.status.open` |
| Closed | Recruitment role no longer accepting applications. | Staff/public role status. | Archived (unless a distinct archive state exists). | `terms.status.closed` |

## en_GB spelling

Use UK English in user-facing copy:

| Prefer | Avoid (US / mixed) |
| --- | --- |
| organisation | organization |
| authorise / authorised | authorize / authorized |
| licence (noun) / license (verb) | license for both |
| programme (scheme of work) | program (except computer program) |
| enquiry | inquiry (except formal legal Inquiry) |
| centre | center |
| colour | color |
| behaviour | behavior |

Framework messages in `lang/en_GB/` already follow this (see Wave 0 / #265).

## Machine-readable deprecated synonyms

`config/glossary.php` → `deprecated_synonyms` lists patterns CI ([#276](https://github.com/APESCIC/MyAPES-Account/issues/276)) may grep in Blade and lang values. Each entry has `canonical`, optional `contexts` (`nav`, `public_ui`, `admin_ui`), and `notes`. Do not treat permission strings or live URLs as violations.
