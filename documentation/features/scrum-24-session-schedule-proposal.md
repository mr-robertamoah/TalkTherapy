# TT-2.5 (SCRUM-24): Session schedule proposals

Either participant of an individual `Therapy` (the client, or the assigned counsellor) can propose
a day/time for the next session, instead of only the counsellor directly creating one. The other
party can accept (creating a real `Session`), reject (optionally with a reason), or counter-propose
a different time — a negotiation with a round counter, mirroring the existing organization
counsellor compensation-change negotiation pattern. Individual `Therapy` only — no `GroupTherapy`
support (not in this epic's scope).

Split into three sub-tickets: SCRUM-206 (TT-2.5a, propose), SCRUM-207 (TT-2.5b, accept/reject/
counter-offer + stale-time handling), SCRUM-208 (TT-2.5c, resource exposure + UI).

## What was built

- A new `Request` type (`sessionScheduleProposal`), reusing the existing generic `Request`
  negotiation model (`for`/`from`/`to`/`round`/`status`/`data`/`expires_at`) rather than a
  dedicated table.
- **Propose**: either the client or the assigned counsellor can propose a startTime/endTime/name/
  about/type/paymentType. `type`/`paymentType` default sensibly when omitted (`ONLINE`/`FREE`) for
  a therapy that doesn't need them chosen explicitly; a PAID therapy requires the proposer to state
  `paymentType: PAID` explicitly (a client can never propose a PAID therapy's session as FREE, or
  vice versa for a FREE therapy — enforced server-side, not just in the UI).
- **Accept**: re-runs the real session-creation validation (`EnsureCanCreateSessionAction`/
  `EnsureSessionDataIsValidAction`) against *current* data — a slot valid when proposed may no
  longer be by the time it's accepted (a conflicting session created since, or the therapy's
  counsellor reassigned). If it's still valid, a real `Session` is created, with the counsellor
  always as its actor regardless of who clicked accept.
- **"Option C" stale-time handling** (explicit user decision): if accept-time re-validation fails,
  the proposal is **not** auto-rejected and **not** surfaced as a raw error — it stays pending with
  a `staleReason`, and the counsellor gets three explicit choices: reject outright, counter-propose
  a new time, or reject with a reason (asking the client to propose again).
- **Counter-offer**: supersedes the current proposal (marked rejected) and creates a new one in the
  reverse direction with `round + 1`, capped at a configurable max round count
  (`config('session_schedule_proposal.max_rounds')`).
- **Reject**: either party can reject a pending proposal, optionally with a free-text reason (shown
  to the proposer).
- UI: a `SessionScheduleProposalSection.vue` panel shown on the Therapy page whenever a proposal is
  pending, with accept/reject/counter-offer buttons (or the three-choice stale UI) for whichever
  party the proposal is currently addressed to, and a read-only "pending a response" state for the
  party who sent it. A "propose session time" button in the page's Actions section opens
  `ProposeSessionScheduleModal.vue`; counter-offering opens `SessionScheduleCounterOfferModal.vue`.

## How to try it out

### Golden path — propose and accept (FREE therapy)

1. Log in as `blocked_counsellor_client` / `password`.
2. Visit `/therapies/7` (seeded "Counsellor Deletion Demo Therapy" — FREE, assigned counsellor, no
   active session).
3. Click **propose session time**, fill in a start/end time (at least 30 minutes apart, in the
   future) and a name/about, and send it.
4. A "Proposed Session Time" panel appears showing your own pending proposal.
5. Log out, log in as `blocked_counsellor` / `password` (the assigned counsellor), and revisit the
   same therapy page — the same panel now shows **accept** / **counter-propose** / **reject**.
6. Click **accept** — the panel disappears, and a new session appears in "Most Recent Sessions".

### Counter-offer, flipping the direction

1. From either account above, propose a new session time.
2. Log in as the other party and click **counter-propose** — fill in a new time and send it.
3. The panel now shows **round 2**, with the direction flipped (whoever countered is now the one
   waiting for a response).

### Stale-time handling ("Option C")

1. Propose a session time, then (as the other party, or via `tinker`) create a conflicting `Session`
   for the same therapy overlapping that time.
2. Accept the stale proposal — it stays pending with a shown reason, and reject / counter-propose /
   reject-with-reason are all still available instead of accept.

### PAID therapy — payment type is enforced, not just chosen

1. Log in as `payment_demo_client` / `password`, visit `/therapies/9` ("Payment Demo Therapy (Per
   Session)" — PAID, no active session).
2. Propose a session time — the Payment Type selector is shown and required; only `paid` is a
   legitimate choice for this therapy (submitting `free` for a PAID therapy, or vice versa, is
   rejected server-side, not just hidden in the UI).

## Test data

No new seed data was needed — the existing `blocked_counsellor`/`blocked_counsellor_client` (FREE,
no active session) and `payment_demo_client`/`payment_demo_counsellor` (PAID, no active session)
fixtures already cover both payment-type scenarios this feature needs. See
`documentation/seeded-data.md`.

## Follow-ups filed, deliberately out of scope

- **SCRUM-211** — a pre-existing gap in `EnsureSessionDataIsValidAction`'s double-booking check
  (misses a new session range that fully contains an existing shorter one), found while testing the
  stale-time accept path. Affects the pre-existing direct session-create flow too, not introduced by
  this epic.
- A `public` FREE therapy's pending proposal (including its free-text name/about) is visible to
  unauthenticated visitors of the therapy page, matching the page's existing exposure of
  `pendingRequest`/`recentSessions`/`recentTopics` for the same audience — not a new gap, but
  flagged for explicit product sign-off on whether that's intended (see `documentation/decision-log.md`).
