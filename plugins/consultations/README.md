# Consultations plugin

Pet Care Clinic consultation records (`Plugins\\Consultations`).

Depends on Pet Profiles. Live URL prefix stays `/petcare/consultations`.

## Who can do what (v0.57)

- `create` and `update-all` are clinical abilities: administrators hold them by default, and the `vet` and `vet-nurse` job roles carry them (mapped from the Cloudron groups `myapesaccount.vet` and `myapesaccount.vetnurse`).
- `view-all`, `assign` and `close` stay with staff, volunteers and students, and can be turned off per group.
- Public users hold `view-own` only and request a consultation through a Pet Care Clinic ticket.

## Vet review of older consultations

`php artisan consultations:flag-public-created --dry-run` reports consultations created by non-clinical users and writes a CSV report to `storage/app/private/reports`. Run it without `--dry-run` and confirm (or add `--force`) to flag them "Needs vet review". Nothing is archived or deleted.
