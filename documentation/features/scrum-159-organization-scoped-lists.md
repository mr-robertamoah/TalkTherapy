# SCRUM-159 (TT-6.6a): Organization-scoped member/counsellor lists

First of the TT-6.6 backend-enablement sub-tickets (see `documentation/decision-log.md`'s
2026-08-29 TT-6.5/TT-6.6 restructuring entry for the full history). Backend-only, admin-only API
endpoints — no frontend UI yet; TT-6.5a (a later sub-ticket) builds the admin dashboard that
consumes these.

## What was built

- **`GET /organizations/{organizationId}/members`** — paginated list of an organization's own
  `OrganizationMember` affiliations, each with a trimmed `user` projection
  (`id`/`fullName`/`username` — deliberately not the full `UserMiniResource`, see below) and its
  current billing config (`mode`/`per`/`includeGroupTherapies`/`effectiveFrom`).
- **`GET /organizations/{organizationId}/counsellors`** — paginated list of an organization's
  affiliated `OrganizationCounsellor`s, each with a `CounsellorMiniResource` and its current
  compensation (`type`/`amount`/`currency`/`percentage`/`basis`/`effectiveFrom`/who set it).
- Both gated by the existing `EnsureUserIsOrganizationAdminAction` (via
  `OrganizationService::getOrganizationMembers`/`getOrganizationCounsellors`) — same guard already
  used by `OrganizationController::show`/`update`.
- **`OrganizationCounsellor::currentCompensation()`/`OrganizationMember::currentBillingConfig()`**
  converted from a naive `hasMany()->orderByDesc()->first()` query to delegate to new
  `latestCompensation()`/`latestBillingConfig()` `hasOne()->ofMany()` relations — eager-loadable,
  so the two new paginated lists don't N+1 on every row's current compensation/billing config. The
  old method names are kept (as thin wrappers) for backward compatibility with existing callers.

## Not yet built

The frontend consuming this data (TT-6.5a, a separate sub-ticket) and the org-scoped request queue
(TT-6.6d, separate ticket).

## How to try it

No seeded data yet (this ticket has no UI to browse to — seed data will land with TT-6.5a). Via
`php artisan tinker` or a REST client against a logged-in admin session:

```php
$org = \App\Models\Organization::factory()->create(['is_provider' => true, 'is_consumer' => true, 'verified_at' => now()]);
$owner = \App\Models\User::factory()->create();
$org->admins()->attach($owner->id, ['role' => \App\Enums\OrganizationAdminRoleEnum::owner->value]);
// log in as $owner, then:
// GET /organizations/{$org->id}/members
// GET /organizations/{$org->id}/counsellors
```

A successful result: `200 OK`, a Laravel paginated-resource envelope (`data`/`links`/`meta`), with
each row carrying its current billing config / compensation inline (no follow-up request needed).
A non-admin gets `403`; a guest gets `401`.

## Testing performed

- Full backend suite: 686 passed (up from 672 before this branch). Whole-file Pint clean on every
  touched file.
- `reviewer` subagent: approved, no blocking findings. One suggestion applied (a code comment
  warning that `currentCompensation()`/`currentBillingConfig()` are now cached per-instance, unlike
  the old always-re-queried form).
- `security-engineer` subagent: one Medium finding fixed before merge — `OrganizationMemberResource`
  was reusing the full `UserMiniResource` (gender/country/dob) for a member's `user` field, which
  contradicts a data-minimization decision already made and documented for this same controller's
  `invite()` method (SCRUM-124). Trimmed to `id`/`fullName`/`username`; regression test added.
  One Low finding deferred with a follow-up ticket (SCRUM-170) — see `documentation/decision-log.md`
  for the full reasoning: a pre-existing 404-vs-403 organization-existence enumeration gap, shared
  with the pre-existing `organizations.show`/`update` routes, not newly introduced by this ticket.
- New tests: `tests/Unit/OrganizationScopedListsTest.php` (service-level authorization + the
  `ofMany()` conversion's correctness), `tests/Feature/OrganizationScopedListsControllerTest.php`
  (real-route listing, admin/outsider/guest authorization, PII-minimization regression, an N+1
  regression guard, and the known-gap enumeration baseline).

## Files changed

- `app/Models/OrganizationCounsellor.php`, `app/Models/OrganizationMember.php` —
  `latestCompensation()`/`latestBillingConfig()` relations
- `app/Actions/Organization/GetOrganizationMembersAction.php`,
  `GetOrganizationCounsellorsAction.php` (new)
- `app/Http/Resources/OrganizationMemberResource.php`, `OrganizationCounsellorResource.php` (new)
- `app/Services/OrganizationService.php` — `getOrganizationMembers`/`getOrganizationCounsellors`
- `app/Http/Controllers/OrganizationMemberController.php`,
  `OrganizationCounsellorController.php` — new `index()` methods
- `routes/web.php` — two new routes
