# SCRUM-162 (TT-6.6d): Organization-scoped request queue for admins

Part of the TT-6.6 backend-enablement sub-tickets (see `documentation/decision-log.md`'s
2026-08-29 TT-6.5/TT-6.6 restructuring entry). Backend-only — no frontend UI yet; TT-6.5a (a later
sub-ticket) builds the admin dashboard's request queue that consumes this.

## What was built

- `RequestService::getRequests()` now also matches `Request` rows where `to`/`from` is one of the
  authenticated user's `administeredOrganizations()` — additive only, mirroring the existing
  `$counsellor`-matching block's shape. Org-directed requests (counsellor applications, member
  applications, invites) address `to`/`from` as the `Organization` itself, so an org admin
  previously had no way to see "pending for my org" via the existing `GET /api/requests` endpoint.
- Fixed a PII-enumeration oracle this change reopened: `organizationMemberInvite`/
  `organizationMemberApplication` rows' `from`/`to` User party is now projected narrowly
  (`id`/`fullName`/`username`, no gender/country/dob) in `RequestResource`, mirroring the same
  data-minimization decision already made for `OrganizationMemberController::invite()`'s own
  response (SCRUM-124). Every other request type's `from`/`to` rendering is unchanged.

## Not yet built

The frontend consuming this (TT-6.5a, separate sub-ticket). No refactor of the `Request`
dispatch/handler-map (deliberately out of scope per the ticket's architect-mandated guard — that
stays its own future ticket).

## How to try it

No seeded data yet. Via `php artisan tinker` or a REST client against a logged-in org admin
session: create an organization, attach an admin, have a counsellor apply to it
(`OrganizationCounsellorRequestService::applyAsCounsellor`) or invite a member
(`OrganizationMemberRequestService::inviteMember`), then `GET /api/requests` as the admin — the
org-directed request now appears alongside any of the admin's personal/counsellor requests.

## Testing performed

- Full backend suite: 679 passed (up from 677 before the security fix, 672 at develop HEAD).
  Whole-file Pint clean on every touched file.
- `reviewer` subagent: approved, no blocking findings. One suggestion applied (a comment
  clarifying the pre-existing trailing `if ($status)` filter is a no-op safety net, not the actual
  enforcement — each branch already applies its own).
- `security-engineer` subagent: one Critical finding fixed before merge — see decision-log for the
  full writeup. Two regression tests added (narrowed-fields pin, cross-org isolation).
- New tests: `tests/Unit/OrganizationScopedRequestQueueTest.php` (service-level matching for
  counsellor applications, member invites, no-org/no-counsellor isolation, admin-who-is-also-a-
  counsellor isolation), `tests/Feature/OrganizationScopedRequestQueueControllerTest.php`
  (real-route listing, PII-minimization regression, cross-org isolation).

## Files changed

- `app/Services/RequestService.php` — the additive org-matching `orWhere` block
- `app/Http/Resources/RequestResource.php` — narrowed User projection for the two org-member
  request types
