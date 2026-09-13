# TT-4.10: Dob Self-Edit Safeguard (SCRUM-287)

A user's self-editable `dob` field had no upper-boundary protection: once a user already had a
minor-relevant record on file (a `Guardianship`-as-ward row, or a minor-client `Therapy`/
`GroupTherapy`), they could self-edit `dob` past 18 to skip every `isAdult()`-gated safeguard
platform-wide (13 call sites, found during TT-3.1e-d's own security review). Closed via a stable
snapshot + guardian/admin approval flow, across six sub-tickets (TT-4.10a through -f).

## What was built

- **Data model** (TT-4.10a): two nullable-boolean snapshot columns —
  `guardianship.ward_was_minor_at_creation` and `client_was_minor_at_creation` on both `therapies`
  and `group_therapies` — captured once, at the moment each record is created, rather than
  re-derived live from a `dob` the record's own subject can freely edit. `null` means "not
  applicable" (no qualifying relationship existed at creation), mirroring `video_consent_mode`'s
  own precedent.
- **Call-site migration** (TT-4.10b): all 13 places that reason about a ward's/client's minor
  status now prefer the stored snapshot when a qualifying record exists, falling back to live
  `isAdult()` only when none does — video-consent enforcement, guardian alerting, `Therapy`/
  `GroupTherapy` participant lists, and `TherapyResource`'s own video-consent projection. Two call
  sites deliberately stayed on live `isAdult()`: `EnsureCanCreateTherapyAction` (the trigger moment
  that *writes* the snapshot, not a consumer of it) and `EnsureUserCanBeGuardianAction` (gates the
  actor's own current eligibility to become a guardian — an unrelated question).
- **The boundary-crossing gate** (TT-4.10c): `EnsureDobChangeIsAllowedAction`, invoked by both
  `ProfileController::update()` (self-service) and the admin update path, defers *only* the `dob`
  field — every other field submitted in the same request still saves immediately — whenever a
  user with a qualifying relationship submits a `dob` edit that would actually flip their current
  minor/adult status, in *either* direction. A user with no qualifying relationship edits `dob`
  freely, unchanged from before this epic. Reuses the existing generic `Request`/`RequestTypeEnum`
  system (a new `dobChange` case) rather than a bespoke table.
- **Approval/rejection** (TT-4.10d): `RespondToDobChangeRequestAction` — approving writes the new
  `dob` **and** retroactively corrects the "was this person a minor" snapshot on every one of the
  target's currently-existing qualifying records (a deliberate correction of the truth, not a
  prospective-only change — the opposite of TT-3.1e-a's video-consent-mode switch). Rejecting
  leaves `dob` and every snapshot untouched. Any one of a ward's active guardians may respond if
  one exists; falls back to any admin only when none does.
- **Frontend** (TT-4.10e): the deferred-approval state surfaces as a calm, non-alarming banner on
  the profile page (never a silent no-op), and the guardian/admin approve-or-reject affordance
  reuses the existing generic Requests list/modal already used for guardianship/org/refund
  requests — including the no-guardian/any-admin case, added as a small, targeted branch to
  `RequestService::getRequests()` rather than a new dedicated page.
- **Requests UI redesign** (SCRUM-298, prompted by user feedback during this epic, not its own
  numbered sub-ticket): the shared Requests list/modal these approvals appear in was visually
  overhauled in the same window — solid bordered cards, always-visible accept/reject controls, a
  calmer status palette. Presentation-only; described in its own decision-log entry.
- **Closeout** (TT-4.10f, this ticket): an end-to-end regression matrix confirming every prior
  sub-ticket's behavior holds up together, plus one real bug found and fixed — see below.

## Bug found and fixed during closeout

A ward with **more than one** active guardian had their dob-change request's `to` fixed to
whichever ONE guardian happened to be picked at creation time — the ward's *other* guardian got a
flat 422 trying to respond at all, contradicting this feature's own explicit design ("any one
active guardian, no unanimity," mirroring `GrantVideoConsentAction`'s identical precedent).
Fixed in `EnsureUserCanRespondToRequestAction` (shared across every request type) with a
dobChange-specific `isGuardianOf($request->for)` check, so either guardian may now respond. A
second security-review pass on that very fix caught it had initially been layered *alongside* the
generic fixed-`to` identity check rather than replacing it for dobChange — meaning a guardian
whose `Guardianship` had since been revoked could still respond forever, since identity-match alone
never re-verifies the relationship still exists. dobChange now has its own fully separate
authorization branch (admin, or a live `isGuardianOf` re-check — no legacy identity fallback),
matching `GrantVideoConsentAction`'s own precedent exactly.

A follow-up qa-engineer pass then caught the fix was only half-shipped: the *authorization* now
correctly allowed a second guardian to respond, but `RequestService::getRequests()` (the query
behind the Requests list/modal itself) still only matched the specific addressed `to` guardian, so
the second guardian had no way to ever discover the request through the UI — and even once visible
(after fixing the listing query), the frontend's own separate `computedIsTo` check still compared
`request.to.id` directly, so the accept/reject buttons stayed hidden regardless. Both are now
fixed: the listing query gained a matching branch, and the frontend now trusts a single
server-computed `isRespondent` flag (reusing the same extracted `userCanRespond()` check) instead
of maintaining its own, now-fourth, independent copy of this authorization logic. Full detail in
`documentation/decision-log.md`'s 2026-09-13 SCRUM-295 entry.

## How to try it out

### Test data

Three scenarios, using seed data already in `DatabaseSeeder`:

| Scenario | Accounts | Notes |
|---|---|---|
| Guardian-addressed approval | `video_consent_demo_minor` / `video_consent_demo_guardian` (both `password`) | The minor already has an active guardian — a qualifying relationship via `Guardianship`. |
| No-guardian / any-admin approval | `dobchange_demo_no_guardian` (`password`) + the super admin `mr_robertamoah` | The minor has no guardian at all but an already-PENDING dobChange request (`data.newDob` set to 30 years ago) is pre-seeded, reachable immediately without hand-built `tinker` data. |
| Multi-guardian approval | `dobchange_demo_multi_guardian` (ward), `dobchange_demo_first_guardian` (addressed `to`), `dobchange_demo_second_guardian` (all `password`) | The ward has TWO active guardians; the pre-seeded pending request is addressed to the first, but log in as the second to confirm it's still visible and actionable. |

See `documentation/seeded-data.md` for full details on all three.

### Steps (guardian-addressed path)

1. Log in as `video_consent_demo_minor`, go to Profile → Update, change date of birth to cross the
   minor/adult boundary (e.g. 30 years ago), and save. Everything else you changed saves
   immediately; a calm indigo banner explains the dob change itself is pending approval, and the
   displayed dob stays unchanged.
2. Log out, log in as `video_consent_demo_guardian`, open the nav "Requests" link. The pending
   request shows the proposed and current dob. Click **accept** (or **reject**).
3. Log back in as the minor — if accepted, the dob is now updated and the pending banner is gone;
   if rejected, the dob is unchanged and a rejection notification exists.

### Steps (no-guardian / any-admin path)

1. Log in as the super admin (`mr_robertamoah`), open "Requests" — the pending dobChange request
   for `dobchange_demo_no_guardian` is already there (no setup needed).
2. Click **accept** or **reject** — any admin may act on it, since no guardian exists to address it
   to.

### Steps (multi-guardian path)

1. Log in as `dobchange_demo_second_guardian` — NOT the guardian the pre-seeded request is
   addressed to — and open "Requests." The request for `dobchange_demo_multi_guardian` is visible,
   with working accept/reject controls, even though it's addressed (`to`) to
   `dobchange_demo_first_guardian`.
2. Click **accept** or **reject** — either of the ward's guardians may act on it.

### What a successful result looks like

- A user with no qualifying relationship (no `Guardianship`-as-ward row, no minor-client
  `Therapy`/`GroupTherapy`) can still edit `dob` freely across the minor/adult boundary — nothing
  about this feature changes that case.
- A qualifying user's boundary-crossing edit — in *either* direction — is deferred, not silently
  dropped or applied: the rest of the same form submission still saves, and the user sees a clear,
  specific explanation of why their dob specifically didn't change yet.
- Any one of a ward's guardians (not just whichever one the system happened to address the request
  to) can approve or reject; when no guardian exists, any admin can.
- Approval retroactively corrects every one of the target's existing qualifying
  `Guardianship`/`Therapy`/`GroupTherapy` snapshots to match the now-confirmed dob — so every one
  of the 13 migrated call sites immediately reflects the correction, not just the raw `dob` column.
  Rejection leaves every one of those untouched.
- The full backend test suite (1761 tests) covers all of the above, including all 13 call sites
  both with and without a qualifying snapshot present, end-to-end through real HTTP routes
  wherever one exists.

## Known, accepted limitations (not gaps — logged and deliberate)

- No DB-level constraint prevents more than one outstanding dob-change approval request per user
  (MySQL 8 lacks partial/filtered unique indexes, the same accepted limitation as `video_consents`)
  — `EnsureDobChangeIsAllowedAction`'s row-locking find-or-reuse logic is the sole enforcer.
- No backfill migration exists for `client_was_minor_at_creation` on a pre-migration `Therapy`/
  `GroupTherapy` row (`null` on any such row, falling through to live `isAdult()`) — accepted since
  nothing was in production when this epic shipped.
- No age/identity verification of any kind — `dob` remains 100% self-reported at every stage. This
  epic only closes the *self-edit-after-the-fact* bypass; verifying a claimed dob in the first
  place is SCRUM-289's own, separately-scoped future ticket.

## Out of scope, filed separately

- **SCRUM-289**: post-registration age/identity verification — needs its own vendor/compliance/UX
  scoping pass, deliberately not folded into this epic.
- **SCRUM-296**: a pre-existing, unrelated trust-boundary gap in `GroupTherapyController`
  (unvalidated `counsellorId` input can corrupt `client_was_minor_at_creation` for a
  counsellor-attributed group) — found during TT-4.10a's own security review, filed separately
  since fixing it touches files outside this epic's scope. **Already fixed and merged**
  (`acb4681`, PR #225) before TT-4.10b started, confirmed via `git log` during this closeout. The
  same unvalidated-`Counsellor::find($request->counsellorId)` pattern still exists, unfixed, in
  `TherapyController.php` for counsellor *assignment* — a narrower, separate risk than SCRUM-296's
  original client/minor-status-attribution framing, out of this epic's scope either way.
- **SCRUM-299**: `RespondToGuardianshipRequestAction` blocks *reject* (not just accept) for a
  recipient who doesn't currently qualify as a guardian — found incidentally during SCRUM-298's QA
  pass, unrelated to this epic, filed as its own bug.

## Epic complete

This closes out SCRUM-287 (TT-4.10) — see `documentation/decision-log.md` for the full trail of
judgment calls made across all six sub-tickets (TT-4.10a–f), including the original `/start-feature`
scoping pass's six final policy decisions and every review finding applied along the way.
