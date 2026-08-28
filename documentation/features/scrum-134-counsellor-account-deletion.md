# SCRUM-134: Counsellor account deletion

The counsellor account-delete button in `resources/js/Pages/Profile/Counsellor/Show.vue` called a
`counsellor.delete` route that had never been registered — the button was entirely dead. The
backend chain behind it (`CounsellorController::deleteCounsellor` →
`CounsellorService::deleteCounsellor` → `EnsureCounsellorExistsAction` →
`EnsureCanDeleteCounsellorAction` → `DeleteCounsellorAction`) already existed but was itself
incomplete: the eligibility gate only checked for pending sessions, and `DeleteCounsellorAction`
soft-deleted the `Counsellor` row behind a `// TODO clean up before deletion` comment. Rather than
just wiring up the missing route, this ticket completes the feature end to end.

## What was built

### Eligibility gate (`EnsureCanDeleteCounsellorAction`)

Restructured into two phases:

1. **Authorization, checked alone first**, with a deliberately generic message. The requester must
   either own the counsellor account or be a **super admin** (`isSuperAdmin()`, not just
   `isAdmin()` — matching `UserService::deleteUserByAdmin()`'s equivalent destructive-action gate).
2. **State checks, only once authorized**, each with its own specific message:
   - no pending sessions (pre-existing check, kept)
   - no `Therapy` currently `in_session`
   - no active `GroupTherapy` affiliation (pivot `state = ACTIVE`)
   - no pending request awaiting the counsellor's *own* decision (`receivedRequests()`)
   - no active `OrganizationCounsellor` affiliation

Splitting authorization from the state checks matters: without it, an unauthorized caller could
learn a counsellor's internal state (an in-session therapy? a pending affiliation?) just from
which error message comes back for someone else's `counsellorId`.

Only requests **awaiting the counsellor's own decision** block deletion (`receivedRequests()`).
Requests the counsellor themselves **sent** (a verification request, an organization application,
a discussion invite they initiated) don't block deletion — they become moot and are auto-declined
by the cleanup step below instead.

### Cleanup on deletion (`DeleteCounsellorAction`)

Everything below runs in one `DB::transaction`, mirroring `ProfileController::destroy`'s wrap:

- `counsellor_group_therapy` pivot rows are flipped to `state = INACTIVE` (not detached — the
  pivot's `state` column already models "no longer active" everywhere else it's read, e.g.
  `GroupTherapy::isCounsellor()`, and detaching would throw away that history).
- `counsellor_discussion` pivot rows are detached (no state column, no history to lose — mirrors
  `RemoveCounsellorFromDiscussionAction`'s existing hard-detach).
- Active `OrganizationCounsellor` affiliations are set to `status = ENDED`.
- The counsellor's own pending **sent** requests are marked `INCONSEQUENCIAL`.
- The `Counsellor` row is soft-deleted.
- Every former client (the `addedby` of each of the counsellor's therapies, plus every participant
  of each group therapy they were ever attached to) is sent a new
  `CounsellorAccountDeletedNotification` (mail, queued) so they aren't left silently wondering why
  the counsellor disappeared.

### Grace period before permanent removal

A `Counsellor` is only **soft**-deleted at deletion time. `AppService::purgeExpiredSoftDeletedCounsellors()`,
scheduled daily at 01:00 (`routes/console.php`), permanently force-deletes any `Counsellor` row
whose `deleted_at` is older than `config('counsellor.deletion_grace_period_days')` (env
`COUNSELLOR_DELETION_GRACE_PERIOD_DAYS`, default **60**). This gives a window to notice and undo an
accidental or malicious deletion before it's irreversible. Only the `Counsellor` row itself is
force-deleted — related historical records (therapies, sessions, licenses, testimonials) are left
untouched, same as they already are for a merely-soft-deleted counsellor.

### Self-service deletion (`CounsellorController::deleteCounsellor`)

Now requires `current_password` re-confirmation, mirroring `ProfileController::destroy`'s
equivalent full-account-deletion flow. The frontend delete modal
(`Show.vue`) gained a password field, following `DeleteUserForm.vue`'s existing pattern.

### Admin-triggered deletion

A new route (`admin.counsellors.delete`, `AdministratorController::deleteCounsellor`) lets a super
admin trigger the same deletion flow — e.g. revoking a counsellor found practicing without a valid
license. It reuses `CounsellorService::deleteCounsellor()` as-is: **there is no admin-only bypass**
of the eligibility checks above. The admin counsellors list (`Admin.vue` → `CounsellorComponent.vue`,
gated behind a new `canDelete` prop only that listing passes) gained a delete button and
confirmation modal, mirroring `AdminUserComponent.vue`'s existing delete-user pattern.

## How to try it

**Self-service (happy path)**: log in as `deletable_counsellor` / `password`, visit their own
counsellor profile page, click "delete account", enter the password, confirm. Redirects to
`/profile` with the counsellor account gone.

**Self-service (blocked)**: log in as `blocked_counsellor` / `password` instead — they have an
in-session therapy — and the same flow should fail with "You have a therapy that is currently in
session...".

**Admin-triggered**: log in as `mr_robertamoah` (super admin, see `documentation/seeded-data.md`),
visit `/administrator` → counsellors, and use the delete button on either seeded counsellor above.

### Test data

See "Counsellor account deletion (SCRUM-134)" in `documentation/seeded-data.md` for the two
dedicated seeded accounts (`deletable_counsellor`, `blocked_counsellor`) and their exact setup.

## Testing performed

- New unit tests: `EnsureCanDeleteCounsellorActionTest` (14 tests — every authorization/state
  branch, plus a test proving an unauthorized caller gets the same generic message regardless of
  the counsellor's actual state), `DeleteCounsellorActionTest` (7 tests — pivot state-flip,
  discussion detach, org-affiliation ending, sent-request cleanup, former-client notification,
  soft-delete), `AppServicePurgeExpiredSoftDeletedCounsellorsTest` (5 tests — grace-period
  boundary, config override).
- New feature tests: `CounsellorDeleteRouteTest` (4 tests — password requirement, cross-user
  rejection, unauthenticated), `AdminCounsellorDeleteTest` (4 tests — super-admin-only,
  unauthenticated).
- Fixed a pre-existing test (`ProfileTest.php`) whose fixture created an `in_session` therapy
  without expecting the new eligibility check to now (correctly) block deletion — the fix was to
  end the therapy first, since that test is about post-deletion rendering, not eligibility.
- Verified empirically: reverted `EnsureCanDeleteCounsellorAction`/`DeleteCounsellorAction` to
  their pre-fix state and confirmed 10 of the 22 new unit tests fail (exactly the ones exercising
  new behavior); restored and confirmed all pass again.
- Full suite: 552 passed. Pint: clean (also fixed several pre-existing unused imports in
  `routes/console.php` while touching that file).
- Frontend: `npm run build` succeeds with no errors.
- **Not done**: a live Playwright browser smoke-check, normally expected for full-ceremony
  feature work with a UI component. Port 8000 (the `web`/nginx service) was occupied by an
  unrelated, pre-existing container from a different project on this machine; rather than
  stopping infrastructure I have no context on, this was skipped. The `qa-engineer` subagent
  should attempt it in an environment where the port is free, or this should be manually
  browser-verified before merge.

## Files changed

- `app/Actions/Counsellor/EnsureCanDeleteCounsellorAction.php` — authorize-first-then-validate
  restructure, four new state checks
- `app/Actions/Counsellor/DeleteCounsellorAction.php` — cleanup-on-delete logic, former-client
  notifications
- `app/Models/GroupTherapy.php` — unrelated fix carried over from SCRUM-108, not part of this
  ticket
- `app/Notifications/CounsellorAccountDeletedNotification.php` (new)
- `app/Services/AppService.php` — `purgeExpiredSoftDeletedCounsellors()`
- `app/Http/Controllers/CounsellorController.php` — `current_password` validation on
  `deleteCounsellor()`
- `app/Http/Controllers/AdministratorController.php` — new `deleteCounsellor()` admin endpoint
- `config/counsellor.php` (new) — `deletion_grace_period_days`
- `routes/web.php` — `counsellor.delete`
- `routes/api.php` — `admin.counsellors.delete`
- `routes/console.php` — daily purge job schedule
- `resources/js/Pages/Profile/Counsellor/Show.vue` — password field on the delete modal
- `resources/js/Components/CounsellorComponent.vue` — new `canDelete` prop, delete button +
  confirmation modal
- `resources/js/Pages/Admin.vue` — wires `canDelete` into the admin counsellors listing
- `database/seeders/DatabaseSeeder.php` — `deletable_counsellor` / `blocked_counsellor` fixtures
- `documentation/seeded-data.md` — documents the new fixtures
- `tests/Unit/EnsureCanDeleteCounsellorActionTest.php`,
  `tests/Unit/DeleteCounsellorActionTest.php`,
  `tests/Unit/AppServicePurgeExpiredSoftDeletedCounsellorsTest.php`,
  `tests/Feature/CounsellorDeleteRouteTest.php`,
  `tests/Feature/AdminCounsellorDeleteTest.php` (all new)
- `tests/Feature/ProfileTest.php` — fixture fix for the new eligibility check
