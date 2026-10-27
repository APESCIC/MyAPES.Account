# Recruitment plugin

Public board + staff manage for APES CIC (`Plugins\\Recruitment`).

Vacancy model is `RecruitmentRole` (never Spatie `Role`). Live URLs stay `/recruitment*` (public) and `/apes-cic/recruitment*` (staff).

## Access matrix

One **Recruitment** primary in the app chrome, with a page submenu gated by ability (epic #342). Ability strings live in `Plugins\Recruitment\RecruitmentAbilities`:

- **Browse** (`BROWSE`): `apes-cic.recruitment.view-own` — Open roles, apply, My applications.
- **Manage** (`MANAGE`): `view-all`, `create`, `update`, `delete`, `review-applications` — Roles and Applications under `/apes-cic/recruitment*`.

| Persona | Primary lands on | Submenu | `/recruitment*` | `/apes-cic/recruitment*` |
| --- | --- | --- | --- | --- |
| Guest | Open roles (board on) | Open roles | Board 200; apply/My applications redirect to sign-in | Redirect to sign-in |
| Service user | Open roles | Open roles, My applications | 200 | 403 |
| Student, volunteer | No primary | None | 403 | 403 |
| Staff (no HR grant) | Open roles | Open roles, My applications | 200 | 403 |
| HR (staff + Human Resources job role) | Roles | Open roles, My applications, Roles, Applications | 200 | 200 |
| Administrator, super-admin | Roles | Open roles, My applications, Roles, Applications | 200 | 200 |
| Review-only (`review-applications` only) | Applications | Open roles, My applications, Applications | 200 | Applications 200; Roles and Roles mutations 403 |

Toggles from 0.35.1 still apply on top of the matrix: when the public board is off, Open roles is hidden and the board returns 404; when public apply is off, the apply button is hidden and apply returns 404.

`tests/Feature/RecruitmentUnifiedMenuAccessMatrixTest.php` encodes this table; change both together.

## HR grant

Recruitment manage is not part of the plain staff role. It comes from:

- the seeded **Human Resources** job role (`human-resources`), assigned in Admin → Access, or mapped from the managed directory group `myapesaccount.hr` by a super-admin; job-role permissions apply on top of the protected staff role;
- the protected **administrator** and **super-admin** roles.

Local QA: `qa.hr@myapes.local` is staff with the Human Resources job role.
