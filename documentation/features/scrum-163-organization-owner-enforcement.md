# SCRUM-163 (TT-6.6e): Co-admin management + real owner-vs-admin enforcement

Part of the TT-6.6 backend-enablement sub-tickets (see `documentation/decision-log.md`'s
2026-08-29 TT-6.5/TT-6.6 restructuring entry). Backend-only — no frontend UI yet; TT-6.5a2 (a
later sub-ticket) builds the admin-management UI that consumes this.

## What was built

- Real behavioral enforcement of the `organization_admins` pivot's existing `role` column
  (`OrganizationAdminRoleEnum::owner`/`admin`), previously present in the schema but never read
  back — any admin could do everything. A new `EnsureUserIsOrganizationOwnerAction` now gates
  admin-roster management specifically; the existing `EnsureUserIsOrganizationAdminAction` remains
  unchanged for actions any admin may still do (profile edits, counsellor/member invites).
- Three new owner-only endpoints, implemented as direct actions (not the `Request`/respond
  negotiation flow used elsewhere in this domain, since there's no second party's consent being
  negotiated):
  - `POST /organizations/{organizationId}/admins` — add a new admin (defaults to `admin` role;
    an owner can pass `role: OWNER` to add a co-owner directly).
  - `PATCH /organizations/{organizationId}/admins/{userId}` — change an existing admin's role.
  - `DELETE /organizations/{organizationId}/admins/{userId}` — remove an admin.
- `EnsureOrganizationRetainsAnOwnerAction`: an organization must always keep at least one owner —
  removing or demoting the last remaining owner is rejected. Wrapped in `DB::transaction()` with
  `Organization::query()->lockForUpdate()`, mirroring the identical pattern already used in
  `OrganizationCounsellorRequestService`/`OrganizationMemberRequestService`, after both reviewer
  and security-engineer independently caught a TOCTOU race in the original unlocked version.

## Not yet built

The admin-management UI (TT-6.5a2, separate sub-ticket). "Removing the organization" itself is
not implemented — no such capability exists anywhere in this codebase yet, and this ticket's
concrete deliverable (AC2) only lists add/remove/promote/demote-admin; see the decision-log for
the full reasoning.

## How to try it

No seeded data yet. Via `php artisan tinker` or a REST client against a logged-in owner session:
create an organization (the creator becomes its owner automatically), then:
- `POST /organizations/{id}/admins` with `{"userId": <id>}` to add a plain admin.
- `PATCH /organizations/{id}/admins/{userId}` with `{"role": "OWNER"}` to promote them.
- `DELETE /organizations/{id}/admins/{userId}` to remove an admin.
A plain (non-owner) admin gets `403` on all three. Removing/demoting the organization's only
remaining owner gets `422`.

## Testing performed

- Full backend suite: 691 passed. Whole-file Pint clean on every touched file.
- `reviewer` subagent: changes requested, both applied before merge — (1) the transaction/lock
  fix described above, (2) this feature doc/decision-log entry.
- `security-engineer` subagent: one Medium finding (the same TOCTOU race reviewer caught,
  confirmed independently) fixed before merge. Five other checks (route-param spoofing, privilege
  escalation via null-role coercion, direct co-owner addition, PII exposure, enumeration oracle)
  verified safe as implemented, no changes needed.
- New tests: `tests/Unit/OrganizationAdminManagementTest.php` (add/remove/promote/demote across
  every last-owner-invariant scenario: remove/demote one-of-two owners succeeds, remove/demote the
  last owner is rejected, a plain admin can't do any of this), `tests/Feature/
  OrganizationAdminControllerTest.php` (the same scenarios via the real routes, plus guest-401).

## Files changed

- `app/DTOs/OrganizationAdminDTO.php` (new)
- `app/Actions/Organization/EnsureUserIsOrganizationOwnerAction.php`,
  `EnsureOrganizationAdminTargetExistsAction.php`,
  `EnsureTargetIsNotAlreadyOrganizationAdminAction.php`, `EnsureTargetIsOrganizationAdminAction.php`,
  `EnsureOrganizationRetainsAnOwnerAction.php`, `AddOrganizationAdminAction.php`,
  `RemoveOrganizationAdminAction.php`, `UpdateOrganizationAdminRoleAction.php` (all new)
- `app/Actions/Organization/EnsureOrganizationExistsAction.php` — widened its DTO union type
- `app/Services/OrganizationAdminService.php` (new)
- `app/Http/Requests/AddOrganizationAdminRequest.php`, `UpdateOrganizationAdminRoleRequest.php` (new)
- `app/Http/Resources/OrganizationAdminResource.php` (new)
- `app/Http/Controllers/OrganizationAdminController.php` (new)
- `routes/web.php` — three new routes
