# SCRUM-216/TT-7.5b: Payment-gated access to GroupTherapy content

Implements TT-7.5b, the GroupTherapy half of SCRUM-215/TT-7.5 (see
`documentation/features/scrum-215-payment-gated-access-tt-7-5a.md` for the individual-Therapy
half, and `documentation/decision-log.md`'s 2026-09-11 SCRUM-216 entry for the full scoping/
decision trail this design is built on). TT-7.5b was blocked on TT-7.4d (per-member GroupTherapy
payment, SCRUM-256) until that epic closed out.

Split across seven sub-tickets, all merged into `develop`:

- SCRUM-264 (b0): multi-counsellor payment-gate toggle authorization
- SCRUM-265 (b1): `strictPaymentGate`/`allowFreeHistoricalAccess` settings persistence
- SCRUM-266 (b2): page-load enforcement + two mandatory widening fixes
- SCRUM-267 (b3): session/chat content enforcement + late-joiner temporal logic
- SCRUM-268 (b4): join-time gating confirmation (no code change — a decision, locked in by test)
- SCRUM-269 (b5): frontend — toggle + payment-required banner
- SCRUM-270 (b6): this ticket — regression closeout + feature doc

## What was built

**The settings.** `GroupTherapy.payment_data->strictPaymentGate` (boolean, default `false`) and a
new sibling, `allowFreeHistoricalAccess` (boolean, default `true`) — both counsellor-controlled,
stored in the same `payment_data` JSON column TT-7.5a's individual-Therapy `strictPaymentGate`
already established the pattern for. Unlike individual Therapy (a single client, a single
counsellor), GroupTherapy authorization for *changing* these settings had to be widened from
scratch: **any currently-ACTIVE counsellor** on the group can change either setting, independently,
not just the group's own `addedby` (`EnsureCanSetGroupTherapyPaymentGateAction`, SCRUM-264) — a
group is far more likely to have counsellors join after creation (by accepting an assistance
request) than to be created by a counsellor directly, so a single-addedby-only rule would have left
the setting practically unreachable for most real groups.

**Enforcement — widening, not forking.** The architect's own explicit finding during scoping: this
is mostly a widening job on TT-7.5a's existing shared actions, not new independent logic, *except*
for one genuine new mechanism (the late-joiner exemption, below). Widened:

- `EnsureUserHasAccessToTherapyAction` (page load) and `EnsureUserCanAccessTherapyContentAction`
  (session/topic/reply content) both gained the identical `$isGroupTherapyMember = $therapy
  ->isParticipant($user) && !($user->counsellor && $therapy->isCounsellor($user->counsellor))`
  check — any participant who is *not* also an active counsellor is "the client" subject to the
  gate, mirroring individual Therapy's own `addedby`-only concept but generalized to GroupTherapy's
  multi-member model. Every member is gated **independently** — one member paying never satisfies
  the gate for a different member on the same group.
- `EnsureStrictPaymentGateSatisfiedAction` and `GetRetainerCoveringOrganizationAction` were widened
  to accept `Therapy|GroupTherapy`, resolving retainer-org coverage against *any* of a GroupTherapy's
  currently-active counsellors (`activeCounsellors()`), not a single `$therapy->counsellor` (which
  GroupTherapy doesn't have at all).
- `GrantPaymentAccessDTO` needed the same widening — caught mid-implementation as a real bug (see
  "Bugs found" below).

**Two mandatory fixes landed atomically with the widening (architect finding, not optional
follow-ups)**: `Therapy::getStrictPaymentGateAttribute()` moved into the shared `TherapyTrait` (it
previously lived only on `Therapy`, so reading it off a `GroupTherapy` silently returned `null` —
and `! null` evaluates `true`, meaning the gate would have **silently never triggered** for any
GroupTherapy); and the retainer-coverage query's single-counsellor assumption, above.

**The late-joiner exemption — the one genuinely new mechanism.** When `allowFreeHistoricalAccess`
is on, a member whose relevant content predates their own join date is exempt from the gate
entirely for that content, even while otherwise unpaid. "Relevant content" is resolved per call
site: `getSessionMessages`/`getTherapyTopicMessages` compare a **session's own `start_time`**
(they fetch a whole session's worth of content at once); `getMessageReplies` compares the **parent
message's own `created_at`** (finer-grained — an old, still-open session can have a brand-new
message whose replies are still gated). A member's own join date is their `group_therapy_user`
pivot row's `created_at`, or — for a User-type creator with no pivot row at all (see the membership
fix below) — the group's own `created_at`. This exemption is deliberately **never reachable from
message creation** (`EnsureCanSendMessageToForAction` never passes the comparison timestamp) — a
member sending a brand-new message right now has nothing historical to exempt on; allowing it would
have let anyone in an old-but-still-open session send unlimited free messages forever.

**Join-time stays payment-unaware (a decision, not an oversight).** `JoinGroupTherapyAction` (both
the immediate-attach and request-then-accept paths) was deliberately left untouched — payment gates
*content*, never joining itself, matching TT-7.5a's own precedent that a Therapy is never gated at
creation or assistance-request-acceptance. Locked in with a regression test
(`GroupTherapyJoinPaymentGateRegressionTest.php`) rather than left implicit.

**Membership-ambiguity fix, inherited from TT-7.4d.** A User-type GroupTherapy creator has no
`group_therapy_user` pivot row (matching `GroupTherapy::getUsers()`'s own long-standing
addedby-is-implicit-member convention) — but `GetGroupTherapyPaymentRosterAction` (TT-7.4d-d) only
ever iterated the pivot relation, so a creator who never separately joined was invisible to the
counsellor-facing payment roster. Fixed at the roster itself (synthesizing the addedby as an
implicit entry, deduped against the pivot), **not** by attaching the creator to the pivot at
creation time — a background investigation confirmed that would have silently reduced every future
group's real joinable capacity by one, since `JoinGroupTherapyAction`'s `max_users` check counts
pivot rows.

**Frontend.** `TherapyPaymentDetails.vue`'s existing strict-gate toggle (previously
individual-Therapy-only) now also renders for GroupTherapy, plus a second, GroupTherapy-only
toggle for `allowFreeHistoricalAccess` — each posting to the correct backend endpoint by
`therapyType` (individual → `therapies.strict_payment_gate.update`; group → the dedicated
`group.therapies.payment_gate.update` built in b1, specifically so an active-but-non-addedby
counsellor can reach it). `PaymentRequiredBanner.vue` was widened to be payable-type-parameterized
(`payableId`/`payableType`) rather than forked into a second component — `GroupTherapyController`
still renders its own separate Vue page today, so a fork would have been exactly the "third
parallel pattern" this codebase is trying to move away from. `HomeController::goHome()` gained the
sibling `paymentRequiredGroupTherapyId` flash prop.

## Bugs found and fixed during implementation

1. **Silent gate bypass (b2)**: `getStrictPaymentGateAttribute()` lived only on `Therapy`, not the
   shared `TherapyTrait` — reading it off a `GroupTherapy` returned `null`, and the gate's own
   `! $therapy->strictPaymentGate` guard would have silently always evaluated "gate satisfied."
   Caught by the architect *before* implementation started (not found live), because b2's own
   ticket explicitly called this out as a mandatory prerequisite.
2. **`TypeError`, not `PaymentRequiredException`, on the very first GroupTherapy grant (b2)**:
   `GrantPaymentAccessDTO::$for` was still typed `Therapy|Session|null`. A test asserting
   `->not->toThrow(PaymentRequiredException::class)` passed for the *wrong* reason (a `TypeError`
   was actually being thrown and swallowed by the assertion's narrow exception-class scope) until a
   direct `assertDatabaseHas` check on `payment_access_grants` caught the empty table. Fixed by
   widening the DTO and rewriting the test to call the action directly with no exception-class
   filter, so any exception fails it.
3. **GroupTherapy content fully unprotected despite the page-load gate (b3, security-engineer
   finding on b2's own review)**: `EnsureUserCanAccessTherapyContentAction` only ever checked
   `$therapy instanceof Therapy`, so a strict-gated GroupTherapy's session/topic/reply content
   stayed completely reachable via the messages API regardless of payment status, even after b2
   correctly blocked the group's own page load. This was b3's own already-scoped job, confirmed by
   re-reading both tickets' text side by side — not an omission in b2.
4. **`Session` factory's `start_time` default silently resolves to "now" (b3)**: the factory's
   default (`$this->faker->timezone()`, a junk string like `"America/New_York"`) is not a real
   datetime, and Carbon's cast coerces it to roughly "now" instead of throwing. This only mattered
   once GroupTherapy sessions started being compared against member join dates (individual-Therapy
   tests never triggered the comparison), and caused one new test to pass for the wrong reason
   until every GroupTherapy-parented `Session::factory()->create()` call in the new tests was given
   an explicit `start_time`. A warning comment was added to the factory itself.
5. **`StarredCounsellorComponent.vue`'s "View" pill rendered invisible text (frontend-cleanup pass,
   found during unrelated Playwright QA, not a TT-7.5b ticket itself but fixed in the same window)**:
   `StyledLink.vue` hardcodes its own conflicting `bg-*`/`text-*` classes on its root element: a
   passed-in override class doesn't reliably win (Tailwind's generated stylesheet order decides,
   not attribute order). Fixed by using a plain `Link` instead, matching
   `MiniTherapyComponent.vue`'s own existing pattern for fully custom-styled link buttons — see
   `.claude/skills/frontend-design/SKILL.md` for the general rule this established.

## Follow-ups filed, not part of this epic's scope

- **SCRUM-271**: `routes/channels.php`'s broadcast-channel authorization checks `isParticipant()`
  only, never the strict payment gate — a subscribed-but-unpaid participant still receives *live*
  new-message broadcasts even though historical reads are now correctly gated. Pre-existing,
  unrelated to any TT-7.5b ticket.
- **SCRUM-272**: Paystack transaction initiation hangs to a 502 instead of failing fast when
  Paystack is unreachable (backend resilience, not a payment-gate correctness issue).
- **SCRUM-273**: a plain GroupTherapy member's UI showed counsellor-only update/delete actions —
  unconfirmed whether the backend itself would also incorrectly allow it; needs its own triage.
- **SCRUM-263** (filed during TT-7.4d, still open): whether GroupTherapy's unscoped `paymentStatus`
  should be hidden from non-counsellor viewers.

## Test data

New dedicated seed data (`documentation/seeded-data.md`'s "Group therapy strict payment gate /
late-joiner" section) — `group_strict_gate_demo_counsellor` / `group_strict_gate_demo_member_unpaid`
/ `group_strict_gate_demo_late_joiner` (password `password`), on "Group Strict Payment Gate Demo"
(`PER_THERAPY`, USD 100, `strictPaymentGate: true`, `allowFreeHistoricalAccess: true`), with two
seeded sessions dated either side of the late joiner's own join date. The existing TT-7.4d group
payment demo data (SCRUM-259) is deliberately trust-based and does not exercise this gate.

## How to try it

1. Log in as `group_strict_gate_demo_member_unpaid` → visiting "Group Strict Payment Gate Demo"
   redirects to Home with the payment-required banner instead of the group page.
2. Log in as `group_strict_gate_demo_late_joiner` → open the group's "Group Strict Gate Demo
   Session (Before Late Joiner)" chat (dated before their own join) — content is visible for free.
   Open "...(After Late Joiner)" instead — blocked, same as `member_unpaid`.
3. Log in as `group_strict_gate_demo_counsellor` → the group page/chat is fully accessible
   regardless of payment status, and the "payment details" tab shows both toggles.
4. Toggle `allowFreeHistoricalAccess` off as the counsellor, then re-check the late joiner's
   access to the "before" session — now blocked too, since the exemption no longer applies.
5. Remember to toggle settings back to their seeded defaults afterward for other features' demos.

## Testing performed

- Full Pest suite: 1485 passed (parallel, 8 processes) across the whole epic's final state — no
  regressions to individual Therapy's own strict-gate behavior (explicitly pinned down by a
  dedicated regression test in this closeout ticket).
- Pint clean on every touched file (whole-file, not diff-only) throughout.
- Frontend production build clean.
- Live Playwright golden-path QA on the frontend ticket (b5): toggle → member blocked with the
  banner → "pay now" initiates the correct request → paid member unaffected → counsellor never
  blocked.
- `reviewer` and `security-engineer` subagent review on every code-bearing sub-ticket (b0, b1, b2,
  b3); all findings applied or confirmed out of scope with a filed follow-up. Every new
  authorization/temporal-comparison branch was independently mutation-tested (the check
  temporarily removed, confirmed the relevant test(s) fail, then restored).
- Confirmed the architect's own zero-schema-change assumption held for the whole epic: no new
  migration touched `payment_access_grants` at any point (`git log` shows its last migration
  predates this epic entirely) — the pre-existing `(user_id, for_type, for_id)` shape already
  supported GroupTherapy grants with no schema change at all.

## Files changed

Backend: `app/Actions/GroupTherapy/{EnsureCanSetGroupTherapyPaymentGateAction,GetGroupTherapyMemberJoinDateAction,GetGroupTherapyPaymentRosterAction,CreateGroupTherapyAction,UpdateGroupTherapyAction}.php`,
`app/Actions/Message/EnsureUserCanAccessTherapyContentAction.php`,
`app/Actions/Therapy/EnsureUserHasAccessToTherapyAction.php`,
`app/Actions/Transaction/EnsureStrictPaymentGateSatisfiedAction.php`,
`app/Actions/Organization/GetRetainerCoveringOrganizationAction.php`,
`app/DTOs/{GroupTherapyDTO,GrantPaymentAccessDTO}.php`,
`app/Models/{Therapy,GroupTherapy}.php`, `app/Traits/TherapyTrait.php`,
`app/Http/Controllers/{GroupTherapyController,HomeController}.php`,
`app/Services/{GroupTherapyService,MessageService}.php`, `routes/web.php`,
`database/factories/SessionFactory.php` (comment only), `database/seeders/DatabaseSeeder.php`.

Frontend: `resources/js/Components/{TherapyPaymentDetails,PaymentRequiredBanner,GroupTherapyFormModal}.vue`,
`resources/js/Pages/Home.vue`.

Tests: `tests/Unit/{EnsureCanSetGroupTherapyPaymentGateActionTest,CreateGroupTherapyActionPaymentGateTest,UpdateGroupTherapyActionPaymentGateTest,GetGroupTherapyMemberJoinDateActionTest,GetRetainerCoveringOrganizationActionTest,EnsureUserHasAccessToTherapyActionGroupTherapyStrictPaymentGateTest,EnsureStrictPaymentGateSatisfiedActionGroupTherapyTest,EnsureUserCanAccessTherapyContentActionGroupTherapyTest,MessageServiceGroupTherapyStrictPaymentGateTest,GetGroupTherapyPaymentRosterActionTest}.php`,
`tests/Feature/{UpdateGroupTherapyPaymentGateTest,GroupTherapyPaymentRequiredRedirectTest,HomePaymentRequiredPropsTest,GroupTherapyJoinPaymentGateRegressionTest,GroupTherapyPaymentGateEpicRegressionTest}.php`.
