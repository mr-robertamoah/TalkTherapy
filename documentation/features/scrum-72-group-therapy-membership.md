# SCRUM-72: Group-therapy membership/join flow + per-member anonymity opt-in

Before this ticket, `GroupTherapy` had a `group_therapy_user` pivot table wired into the model
(`GroupTherapy::users()`) and a `background_story`/`anonymous` pivot payload, but nothing in
application code ever wrote to it — there was no join flow at all, and worse,
`GroupTherapy::isParticipant()` / `isUser()` / `getUsers()` / `getOtherUsers()` /
`scopeWhereIsParticipant()` only recognized the group's creator (`addedby`) or an assigned
counsellor as a "participant", meaning a pivot-attached member (however they got there) wasn't
recognized as a real participant anywhere in the app (this was SCRUM-69). This ticket builds the
actual join flow and closes that gap at the same time.

## What was built

### Part 1 — `GroupTherapy` participant methods now recognize pivot members (closes SCRUM-69)

`isParticipant()`, `getUsers()`, `getOtherUsers()`, and `scopeWhereIsParticipant()` all now also
check/include users attached via the `users()` pivot relation, not just `addedby` and assigned
counsellors. `isUser()` is unchanged by design — it specifically means "is this the group's
creator", and a plain member must not satisfy it.

### Part 2 — a new request type for joining as a member

`RequestTypeEnum::groupTherapyMembership` (`GROUP_THERAPY_MEMBERSHIP_REQUEST`) is a new, distinct
request type from the existing `groupTherapy` case (`GROUP_THERAPY_ASSISTANCE_REQUEST`, which means
"a counsellor requesting to help run this therapy"). This one means "a user requesting to join as a
member" — a different relationship entirely.

`RespondToGroupTherapyMembershipRequestAction` (new, mirrors
`RespondToTherapyAssistanceRequestAction`'s structure):
- **Accept**: re-checks group capacity at accept-time (a group could have filled up between the
  request being sent and it being responded to) — if it's now full, the request is rejected
  instead, with a `reason` stored in `data` and the requester notified. Otherwise, attaches the
  requester to `group_therapy_user` with the anonymity value resolved via
  `GroupTherapy::resolveMembershipAnonymity()` (see below), notifies the requester
  (`GroupTherapyMembershipRequestAcceptedNotification` — mail/database/broadcast), and alerts the
  requester's guardian if they're a minor (`AlertGuardianAction` +
  `GroupTherapyMembershipRequestAcceptedGuardianNotification`, both no-ops for an adult).
- **Reject**: updates status and notifies the requester
  (`GroupTherapyMembershipRequestRejectedNotification`).

`GroupTherapyMembershipRequestSentNotification` (new) notifies the group's creator when someone
requests to join.

### Part 3 — `JoinGroupTherapyAction` (the actual join flow)

`App\Actions\GroupTherapy\JoinGroupTherapyAction`, driven by a new `JoinGroupTherapyDTO`
(`user`, `groupTherapy`, `anonymous`):
1. Runs `EnsureCanCreateTherapyAction` (the existing guardian/minor eligibility gate, reused as-is
   despite its Therapy-specific namespace — it's generic user eligibility).
2. Rejects (new `CannotJoinGroupTherapyException`) if the user is already a participant, already
   has a pending membership request for this group
   (new `User::hasPendingRequestFor()`/`doesNotHavePendingRequestFor()`, mirroring
   `Counsellor`'s), or the group is at capacity (`users()->count() >= max_users`).
3. Resolves the final anonymity value via `GroupTherapy::resolveMembershipAnonymity(bool
   $requested)` — a single shared helper (see "Anonymity resolution" below) used both here and by
   the accept-time action.
4. If `allow_anyone` is true: attaches the user directly and returns the updated `GroupTherapy`.
5. If `allow_anyone` is false: creates a `Request` (`type = groupTherapyMembership`, `data =
   ['anonymous' => ...]`) addressed to the group's creator (resolved to a `User` even when the
   creator is a `Counsellor`, via their linked `user`), notifies them, and returns the `Request`.

**Anonymity resolution** (`GroupTherapy::resolveMembershipAnonymity(bool $requested): bool`): if
the group itself is `anonymous`, the stored value is always `true` regardless of what was
requested (server-side, so a client can't bypass it) — otherwise the requested value is used as-is.
This one method is the single source of truth, called both at join-time (immediate join) and at
accept-time (request-based join), rather than duplicating the same ternary in two places.

`GroupTherapyController::joinGroupTherapy` / `GroupTherapyService::joinGroupTherapy` follow this
codebase's usual Controller → Service → Action → DTO layering. New route:
`POST /group-therapies/{groupTherapyId}/join` → `api.group.therapies.join`.

### Part 4 — frontend join UI

On the group therapy page (`UnifiedTherapy.vue` → `TherapyInformation.vue`, in the Participants
tab's new "Membership" section):
- **Not a participant, nothing pending**: a "join group" button opens a confirmation modal with an
  anonymity opt-in checkbox — editable by default, but disabled (with explanatory copy: *"This
  group is anonymous — all members are anonymous automatically."*) when the group itself is
  anonymous.
- **A pending join request exists, and it's your own**: "your request to join this group therapy
  is pending approval."
- **A pending join request exists, and you're the creator**: accept/reject buttons, reusing the
  same `requests.respond` endpoint and response-handling shape as the existing counsellor
  assistance-request flow (a new, parallel `pendingMembershipRequest`/`membershipRequest`/
  `clicked-membership-response` prop-and-event chain, kept separate from the existing
  `pendingRequest`/`request`/`clicked-response` chain since that one is specifically for the
  counsellor assistance-request flow and a group can have both a pending assistance request and a
  pending membership request active at once).
- **Already a member**: "you are already a member of this group therapy."

`GroupTherapyController::getGroupTherapy` now also passes a `pendingMembershipRequest` Inertia
prop (the latest pending membership request involving the current viewer, whichever side of it
they're on), computed by a new `GroupTherapy::pendingMembershipRequestFor(User $user)`.

`GroupTherapyResource` now also exposes `isParticipant` (server-computed, using the now-fixed
`isParticipant()`), and the frontend's `useTherapyState.js` `computedIsParticipant` for a group
therapy now also honors it — this is the frontend counterpart of the Part 1 backend fix: a plain
joined member wasn't previously recognized as a participant on the frontend either (which,
incidentally, also means a real member now correctly sees the "Actions" panel — e.g. "make a
report" — the same as any other participant already could).

### Part 5 — seed data

`DatabaseSeeder.php` adds a deterministic `Membership Request Demo Group Therapy`
(`allow_anyone = false`, created by `maria_garcia`) with a pre-existing **PENDING** membership
request from `amy_taylor`, so the accept/reject UI is manually verifiable without first driving the
join flow through the UI. See "Test data" below.

## How to try it

1. `docker compose exec php php artisan migrate:fresh --seed`.
2. **Immediate join** (`allow_anyone = true`): log in as any seeded user who isn't already a member
   of one of the randomly-generated group therapies (or `Chat Demo Group Therapy`, which is
   `allow_anyone: true`), open that group therapy's page, go to the Participants tab, and click
   "join group" — you're attached immediately, no approval needed.
3. **Request-based join** (`allow_anyone = false`): log in as `amy_taylor` (password `password`)
   and open `Membership Request Demo Group Therapy` — you should see "your request to join this
   group therapy is pending approval."
4. Log in as `maria_garcia` instead (the creator) and open the same group therapy — you should see
   accept/reject buttons for that pending request. Accepting attaches `amy_taylor` to the group;
   rejecting does not.
5. To see the anonymity opt-in lock: open a group therapy whose `anonymous` flag is `true` (several
   of the randomly-seeded ones will be — check via the tinker query in "Test data" below) as a
   non-member and click "join group" — the "join anonymously" checkbox is pre-checked and disabled,
   with the explanatory copy shown.

## Test data

- **`Membership Request Demo Group Therapy`** — created by `maria_garcia`, `allow_anyone = false`,
  with an existing **PENDING** `GROUP_THERAPY_MEMBERSHIP_REQUEST` from `amy_taylor`. Log in as
  `maria_garcia` (password `password`) to accept/reject it, or as `amy_taylor` to see it still
  pending. Look it up anytime with:
  ```bash
  docker compose exec php php artisan tinker --execute="
  \$gt = App\Models\GroupTherapy::where('name', 'Membership Request Demo Group Therapy')->first();
  echo \$gt->id;
  "
  ```
- For an **immediate-join** (`allow_anyone = true`) scenario, `Chat Demo Group Therapy` (see
  `documentation/seeded-data.md` and `documentation/features/scrum-71-anonymity-masking.md`) works,
  or any of the randomly-seeded group therapies with `allow_anyone = true`.
- See `documentation/seeded-data.md` for the full roster of seeded accounts (all share password
  `password`).

## Deviations / things worth a second look

- **Inferred requirement, needs confirmation**: `GroupTherapyMembershipRequestSentNotification`
  (sent to the creator when someone requests to join) masks the requester's identity if either the
  group-level `anonymous` flag or the requester's own chosen `anonymous` value (captured in
  `request->data` at request-time) is true. This wasn't explicit in the written acceptance
  criteria — it follows from the existing anonymous-therapy PII-safety convention's spirit (don't
  reveal an identity that's about to become anonymous the moment the request is accepted), mirroring
  `TherapyAssistanceRequestAcceptedNotification`/`GroupTherapyAssistanceRequestSentNotification`'s
  precedent. Flagging this explicitly so it can be confirmed rather than assumed correct.
- Similarly, `GroupTherapyMembershipRequestAcceptedNotification` /
  `GroupTherapyMembershipRequestAcceptedGuardianNotification` mask the group **creator's** identity
  (not the group's name) when the group is anonymous — this is the same masking convention
  `GroupTherapyResource` already applies to `addedby`, applied here to protect the creator's
  identity in a notification sent to a newly-accepted member. This reading of "masks the group's
  identity/name appropriately" is an inference and worth a second look too.
- **Fixed during review, not deferred**: `RequestResource::toArray()` originally rendered both
  `from` (the requester) and `to` (the group creator) via an unmasked `UserMiniResource`
  regardless of anonymity — the raw JSON payload (not just the rendered UI) leaked the real
  identity of whichever party should have been anonymous, for a `groupTherapyMembership` request.
  Both are now masked via `getFrom()`/`getTo()` private methods (OR of the group's own `anonymous`
  flag and, for `from` only, the requester's own chosen `data['anonymous']` value), except for
  each person's own view of their own request. Covered by 7 new regression tests.
- **Fixed during review, not deferred**: `User::hasPendingRequestFor()` (the new duplicate-request
  guard used by `JoinGroupTherapyAction`) had `->whereTo($this)->orWhereFrom($this)` ungrouped,
  making `orWhereFrom` a top-level OR unscoped from `wherePending()`/`whereFor($model)` — so ANY
  prior request a user had ever sent (any status, any target) incorrectly counted as "a pending
  request for this group," permanently blocking most real users from joining any group therapy.
  Fixed by grouping the to/from alternation in its own `where(...)` closure. The identical,
  pre-existing bug in `Counsellor::hasPendingRequestFor()` was left alone (out of this ticket's
  scope) and filed as its own follow-up ticket.
- `EnsureTherapyExistsAction` (used to check the group exists before joining) had its DTO union
  type widened to include `JoinGroupTherapyDTO`, reusing this existing 404-style guard rather than
  adding a parallel one.
- `computedIsParticipant` in `useTherapyState.js` was extended for group therapy to also honor the
  new `GroupTherapyResource.isParticipant` field — a deliberate frontend counterpart to the Part 1
  backend fix (see "Part 4" above), which also means a plain joined member now sees the same
  "Actions" panel (e.g. "make a report") any other participant already could. This is a slightly
  broader change than the ticket's literal join-UI ask, but follows directly from actually fixing
  SCRUM-69 end-to-end rather than leaving the frontend half of the same gap in place.

## Files changed

- `app/Models/GroupTherapy.php` — `isParticipant()`, `getUsers()`, `getOtherUsers()`,
  `scopeWhereIsParticipant()` now recognize pivot members; new
  `resolveMembershipAnonymity()`, `pendingMembershipRequestFor()`
- `app/Models/User.php` — `hasPendingRequestFor()` / `doesNotHavePendingRequestFor()`
- `app/Enums/RequestTypeEnum.php` — new `groupTherapyMembership` case
- `app/Actions/Request/GetRequestResourceAction.php`, `RespondToRequestAction.php` — route the new
  type through the existing `RequestResource` / dispatch chain
- `app/Actions/Request/RespondToGroupTherapyMembershipRequestAction.php` (new)
- `app/Actions/GroupTherapy/JoinGroupTherapyAction.php` (new)
- `app/Actions/Therapy/EnsureTherapyExistsAction.php` — widened DTO union type
- `app/DTOs/JoinGroupTherapyDTO.php` (new)
- `app/Exceptions/CannotJoinGroupTherapyException.php` (new)
- `app/Notifications/GroupTherapyMembershipRequestSentNotification.php`,
  `GroupTherapyMembershipRequestAcceptedNotification.php`,
  `GroupTherapyMembershipRequestAcceptedGuardianNotification.php`,
  `GroupTherapyMembershipRequestRejectedNotification.php` (all new)
- `app/Http/Controllers/GroupTherapyController.php` — `joinGroupTherapy()`,
  `pendingMembershipRequest` prop on `getGroupTherapy()`
- `app/Http/Resources/GroupTherapyResource.php` — new `isParticipant` field
- `app/Services/GroupTherapyService.php` — `joinGroupTherapy()`
- `routes/api.php` — `api.group.therapies.join`
- `resources/js/Pages/UnifiedTherapy.vue`, `Components/BaseTherapyLayout.vue`,
  `Components/TherapyInformation.vue` — join UI, pending-request UI, accept/reject
- `resources/js/Composables/useTherapyState.js`, `Composables/useEnums.js`
- `database/seeders/DatabaseSeeder.php` — `Membership Request Demo Group Therapy` fixture
- `documentation/seeded-data.md` — documents the new fixture
- `tests/Unit/GroupTherapyParticipantTest.php` — pivot-member coverage for Part 1
- `tests/Feature/GroupTherapyMembershipTest.php` (new) — join/request/accept/reject, capacity,
  duplicate-request, minor/guardian, and anonymity-forcing coverage
