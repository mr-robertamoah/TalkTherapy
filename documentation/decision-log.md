# Decision Log

A running record of non-trivial decisions made during autonomous/semi-autonomous execution —
scope narrowings, design choices where a ticket's text was ambiguous, deviations from what a
ticket literally says, resolved architectural questions. Complements (doesn't replace) the
per-ticket Jira comments. See CLAUDE.md's "Autonomous execution" section for when this gets
written to versus when the assistant pauses to ask instead.

Newest entries at the bottom.

---

## 2026-08-26 — SCRUM-110: idempotency guard scope

**Decision**: the "already paid" guard on `RecordTransactionStatusAction` needed to reject not
just exact-duplicate status updates but any update attempting to move a transaction away from a
terminal state (`success`/`failed`), not only identical re-deliveries.

**Why**: an out-of-order or racing webhook event with a *different* status could otherwise
regress an already-completed payment — the exact-match check alone was insufficient for
money-moving code.

---

## 2026-08-26 — SCRUM-110: `VerifyPaystackTransactionAction` status mapping

**Decision**: map only `success`/`failed`/`abandoned` Paystack statuses explicitly; leave any
other status (e.g. a non-terminal "processing"/"queued") unrecorded rather than collapsing it
into `abandoned`.

**Why**: reviewer and security-engineer both independently flagged the original blanket mapping
as premature finalization risk for a status Paystack might still resolve further.

---

## 2026-08-27 — SCRUM-111 ticket sequencing: frontend-flow gap

**Decision**: added a "Frontend / UX flow per user type" section to all six Organizations-epic
feature tickets (SCRUM-110–115) after the user pointed out none of the planning had considered
what the frontend should look like per role. Filed SCRUM-118 as a new ticket for SCRUM-110's
missing payment UI rather than reopening the already-implemented/PR'd backend ticket.

**Why**: subagent planning passes (product-owner/project-manager/architect) default to a
backend/data-model lens unless explicitly directed otherwise — this is now something to check
for proactively on future feature planning, not just when asked.

---

## 2026-08-27 — SCRUM-111: authorization mechanism deviates from architect's recommendation

**Decision**: implemented org-admin authorization as an Action (`EnsureUserIsOrganizationAdminAction`,
Service-layer), not a Laravel Policy, despite the architect/PM passes both suggesting "a Policy."

**Why**: this codebase has zero `app/Policies/` classes anywhere — every existing authorization
check (including the `Administrator`/`isAdmin()` equivalent) is Action/Service-layer. Introducing
Policies for one ticket would be a new, unprecedented pattern; matching existing convention wins
over the subagents' textbook-Laravel suggestion.

---

## 2026-08-27 — SCRUM-111: verification state as `verified_at`, not an enum

**Decision**: `Organization` verification is a nullable `verified_at` timestamp (verified or
not — no persisted "rejected" state), not the `verification_status` enum
(pending/verified/rejected) the original ticket text specified.

**Why**: mirrors the existing `Counsellor`/`Administrator` verification pattern exactly.
Rejection is captured on the `Request`'s own status instead — there's no need for the target
model to separately remember "was rejected"; it just stays unverified.

---

## 2026-08-27 — SCRUM-120: narrowed from 4 request flows to 2

**Decision**: TT-6.2 originally named 4 org-related request flows (org↔counsellor invite/apply,
org↔member invite/self-apply). Implemented only the 2 counsellor-side flows; deferred the 2
member-side flows to a not-yet-filed TT-6.3 ticket.

**Why**: the member-side flows need an `organization_members` table that doesn't exist yet and
isn't this ticket's to create — building request plumbing with no table to write into on
acceptance would be untestable, speculative code. TT-6.4a (next in the M1 chain) only needed the
counsellor-side flows anyway.

---

## 2026-08-27 — SCRUM-120: enumeration oracle closed with a single generic message

**Decision**: collapsed "organization is unverified" and "organization is not a provider" into
one generic "You cannot apply to this organization" message for the counsellor-apply flow
specifically (kept distinct for the admin-invite flow).

**Why**: security-engineer found the apply endpoint let any counsellor probe an arbitrary
organization's verification/provider status via distinguishable error messages, inconsistent
with `OrganizationController::show()` being admin-only. The admin-invite flow has no equivalent
oracle risk since an org admin already knows their own org's state.

---

## 2026-08-27 — SCRUM-121: "(c) direct contract" is not a third creation path

**Decision**: interpreted the original background text's "(c) an already-independent counsellor
can be additionally, non-exclusively contracted" as the *guarantee* that holds for outcomes of
paths (a)/(b) (both already built as `Request` flows in SCRUM-120), not a third distinct
creation mechanism. No separate "direct contract" flow was built.

**Why**: re-reading the source text, (c) describes a property (non-exclusivity, multi-org
affiliation), not a new actor-initiated action — and nothing else in the ticket described a
third distinct trigger. Multi-org affiliation falls out naturally from allowing multiple
`organization_counsellors` rows per counsellor.

---

## 2026-08-27 — SCRUM-121: affiliation row created at accept-time, status `pending`

**Decision**: an `organization_counsellors` row is created immediately when a SCRUM-120 request
is accepted, with `status = pending` — not deferred until compensation terms exist (TT-6.4b).

**Why**: the original ticket text ("results in a row only once compensation terms have been
agreed") was ambiguous between "the row doesn't exist yet" and "the row exists but isn't yet
active." The latter keeps the affiliation queryable immediately after acceptance instead of
leaving an accepted-but-unrepresented gap spanning two different tickets/PRs.

---

## 2026-08-27 — Process change: semi-autonomous execution adopted

**Decision**: per explicit user request, adopted a semi-autonomous operating mode project-wide:
once a bugfix/chore or an already-approved epic's direction is set, proceed through that work's
full workflow — including moving on to the next sub-ticket in a clear dependency chain — without
waiting for a per-ticket go-ahead. Pauses only for a genuinely ambiguous/consequential decision,
a needed clarifying question, an undetermined "what's next" (chain ended or multiple unrelated
tickets equally valid), or when continuing would require branching off a not-yet-merged PR.
Brand-new epics/features still always get the full `/start-feature` plan-and-approval gate first.
Documented in `CLAUDE.md`'s new "Autonomous execution" section.

**Why**: the user found the per-ticket "next one" prompt unnecessary ceremony once a chain's
direction (e.g. an epic's milestone order) is already established, and wanted a durable,
reviewable log of judgment calls made along the way rather than only scattered Jira comments.

---

## 2026-08-27 — Process change: local permission allow-list broadened

**Decision**: broadened `.claude/settings.local.json`'s permission allow-list to cover git/Bash
generally, Write/Edit/Read/Grep/Glob, the Agent tool, and the routine GitHub/Jira MCP calls used
throughout a ticket's workflow (PR/issue reads and creates, Jira CRUD/comment/transition/link) —
so neither the main session nor a spawned subagent (`reviewer`, `security-engineer`, etc., which
share the same tool set) hits a per-call approval prompt for ordinary ticket work.

**Why**: direct user request, twice reiterated after the semi-autonomous policy above still hit
approval prompts in practice — first for routine GitHub calls, then specifically because
subagents' own `Bash`/`Read`/`Grep` calls weren't covered by the initial narrower allow-list.
This is a local, gitignored, personal file — not a project-wide/team-shared policy change.
Destructive operations (merging PRs, force-push, branch deletion) are deliberately NOT
allow-listed; those stay refused by the assistant's own hard rules regardless of what this file
would technically permit — the permission file was never the actual backstop for those.

---

## 2026-08-27 — SCRUM-122: currency validation left as free-text, not ISO-4217-restricted

**Decision**: did not add an ISO 4217 currency allow-list to the new compensation-terms
endpoint's `currency` field, despite the security-engineer subagent flagging its absence.

**Why**: every other currency field in this codebase (`Therapy`, `GroupTherapy`) is the same
free-text `string` rule with no allow-list — this exact gap is already tracked as its own
follow-up (TT-7.4, "real currency validation, replacing free-text"). Adding a bespoke allow-list
to just this one new field would be an inconsistent one-off rather than the actual fix, which
belongs in TT-7.4 across all currency fields at once.

---

## 2026-08-27 — TT-6.3 split into SCRUM-124/125, mirroring the TT-6.2/6.4a/6.4b split

**Decision**: split the originally-planned single 13-point TT-6.3 (consumer-org membership +
billing config) into two sub-tickets — SCRUM-124 (membership request flows) and SCRUM-125
(billing-mode config) — before starting implementation.

**Why**: matches the precedent already set on the provider side, where the analogous combined
scope was split into TT-6.2 (request plumbing) and TT-6.4a/6.4b (affiliation + compensation
terms) specifically because it was too large and mixed concerns for one reviewable PR. Filing
the split tickets up front (rather than discovering the need to split mid-implementation, as
happened with TT-6.4) keeps each PR bounded and independently testable from the start.

---

## 2026-08-27 — SCRUM-124: "self-apply link" read as no separate join-token

**Decision**: the self-apply flow is gated purely by `Organization.self_apply_enabled` — reachable
by any authenticated user who knows the organization's id — rather than inventing a separate
secret join-code/token mechanism.

**Why**: the original decision text ("a setting where if enabled, users with the link on their
profile can apply to join") doesn't clearly call for a secret token, and no invite-token pattern
exists anywhere else in this codebase to extend. Flagged explicitly on the SCRUM-124 ticket
itself for the requester to correct if this wasn't the intent — a genuinely ambiguous point, but
not consequential enough to block on asking before starting implementation.

---

## 2026-08-27 — SCRUM-124: no `self_apply_enabled` re-check at accept time

**Decision**: did not re-validate `self_apply_enabled` when an org admin accepts a pending member
*application* (the verified/`is_consumer` re-checks were kept, mirroring the counsellor flow).

**Why**: the security-engineer subagent suggested this for consistency, but disabling self-apply
is a "stop accepting *new* applications" toggle, not a statement that already-pending ones become
invalid — an admin who paused self-apply (e.g. because they were overwhelmed with volume) should
still be able to honor an application already in their queue if they choose to. This differs from
verified/`is_consumer`, which are fundamental eligibility the org itself controls, not a
submission-throttle.

---

## 2026-08-27 — SCRUM-124: invite endpoint response trimmed to close a PII-enumeration risk

**Decision**: `OrganizationMemberController::invite()`'s success response no longer includes the
invited user's full `OrganizationRequestResource`/`UserMiniResource` data (name, username,
gender, country, DOB) — it returns only `{id, type, status}`.

**Why**: security review found any org admin could supply an arbitrary `userId` and get back
that user's PII synchronously, plus create a persisted pending invite against them as a
side-effect of probing — an enumeration vector this platform's ordinary `User` records (therapy
clients) aren't meant to be exposed to, unlike `Counsellor` profiles which are intentionally
publicly browsable. The inviting admin already knows the id they supplied, so nothing useful is
lost by not echoing the invitee's profile back.

---

## 2026-08-27 — SCRUM-125: verified a security finding empirically before accepting its severity

**Decision**: security review flagged `(bool) $request->includeGroupTherapies` as a silent-flip
risk (a string `"false"` casting to PHP `true`). Fixed the cast to `$request->boolean(...)`
regardless, but before writing it up as a live vulnerability, wrote a feature test to actually
exercise the claimed exploit path — it failed: Laravel's own `'boolean'` validation rule
(`vendor/laravel/framework/.../ValidatesAttributes.php:496`) only accepts
`[true, false, 0, 1, '0', '1']`, and the word `"false"` is rejected at validation (422) before
the cast ever runs. Every value that rule *does* accept happens to cast correctly via `(bool)`.

**Why**: a subagent's finding describes a plausible-sounding mechanism, but "plausible" isn't
"verified" — this specific claim didn't hold up against the actual validated behavior. Filed
SCRUM-126 as a systemic follow-up (the same cast pattern exists in several older, unrelated
controllers) with the corrected, verified severity nuance: it's only a *live* bug on a field
missing the `'boolean'` rule, which needs checking per-site, not assuming either way. Keeping
the `$request->boolean()` change anyway as harmless, more idiomatic defense-in-depth — just not
overstating why it mattered in the PR/ticket writeup.

---

## 2026-08-27 — SCRUM-117: mismatch rejection reuses the existing failed-job alert channel instead of a new one

**Decision**: `EnsureTransactionAmountAndCurrencyMatchAction` throws a `TransactionException` on
an amount/currency mismatch, in both `ProcessPaystackWebhookJob` (async path) and
`VerifyPaystackTransactionAction` (synchronous browser-callback path), rather than building a
dedicated "flag for manual review" mechanism.

**Why**: `AppService::alertAdminsOfFailedJob()` (SCRUM-82) already notifies admins on any queued
job failure -- letting the webhook job's mismatch throw uncaught means that channel does the
"manual review" work for free. The verify-callback path throws for the same reason the file's
existing checks do ("Transaction not found.", etc.) -- it surfaces as an ordinary error response
to the browser rather than a silent success. Only checked when the resolved status is `success`:
there's nothing money-correctness-sensitive to protect once a charge resolves to `failed`/
`abandoned`.

---

## 2026-08-27 — SCRUM-116: regression test flakiness traced to unrelated pre-existing bugs, not fixed here

**Decision**: `tests/Feature/RouteParamSpoofingTest.php`'s session-update test intermittently
failed with a 422 depending on run — traced to two pre-existing bugs unrelated to SCRUM-116:
`Timeable::isNotUpdateable()`'s `orWhere` branches aren't scoped by session id (any row globally
"about to start"/ongoing falsely blocks an unrelated session's update), and
`EnsureSessionDataIsValidAction::validateTherapy()` runs a system-wide conflict scan rather than
one scoped to the relevant participant's actual sessions. Both were being tripped at random by
the test's own unrelated-session fixture, whose `start_time`/`end_time` were left on
`SessionFactory`'s bogus default (`$this->faker->timezone()`, not a real date). Fixed by pinning
that fixture's times safely in the past; filed SCRUM-129 for the underlying scoping bugs rather
than fixing them as part of this ticket.

**Why**: same discipline as SCRUM-127/128 — bugs discovered incidentally while writing regression
tests for a different, narrowly-scoped fix get filed as follow-ups, not folded into the ticket
in progress.

---

## 2026-08-27 — SCRUM-116: same-pattern IDOR left in other controllers, filed as SCRUM-130 rather than expanded scope

**Decision**: reviewer and security-engineer subagents on PR #64 both independently found the
identical `$request->xId` magic-property route-param bug still present, unfixed, in
`DiscussionController`, `MessageController`, `ReportController`, `TherapyTopicController`,
`UserController::deleteGuardianship`, `CounsellorController`, and
`AdministratorController::getCounsellorStats` — none of which SCRUM-116 named. Verified
independently via grep against `routes/web.php`/`routes/api.php` before filing. Filed as SCRUM-130
(High, given `DiscussionController`/`MessageController` carry private clinical content) rather
than pulling those controllers into SCRUM-116's PR.

**Why**: SCRUM-116 was explicitly scoped to three named controllers; a security finding that's
real but outside a bugfix's stated scope gets its own ticket, not silent scope expansion —
consistent with how SCRUM-127/128/129 were handled. Both subagents approved PR #64 itself
unconditionally on its stated scope.

---

## 2026-08-27 — SCRUM-123: implemented read path + accountability trail now; deferred notification/accept-step

**Decision**: built the read path (org admins, per the existing write-side scoping, plus the
affiliated counsellor themselves, reading their own current/historical terms) and an
accountability trail (`set_by_id` on `organization_counsellor_compensations`, a plain foreign key
to `users` since only an org admin can ever set these terms today, not a polymorphic morph).
Did not implement counsellor notification-on-change or any accept/dispute step; filed SCRUM-131
as a follow-up specifically for the notification decision.

**Why**: the ticket's own "Process" note explicitly says to confirm with product "before
implementing the notification/accept-step pieces" specifically, while treating the read path and
accountability trail as the first-pass ask — the ticket itself draws this line, so no separate
pause-and-ask was needed here. Notification design (on first activation only vs. every
renegotiation, email vs. in-app, etc.) is a genuine product decision with real consequence for a
platform where this affects a counsellor's livelihood, exactly the kind of decision this
project's autonomous-execution policy pauses on rather than guessing.

---

## 2026-08-27 — SCRUM-126: checked each site individually rather than blanket-replacing

**Decision**: verified, per-site, whether each `(bool) $request->x` cast sat behind a `'boolean'`
validation rule before fixing. `OrganizationController` (isProvider/isConsumer/selfApplyEnabled)
and `AdministratorController::updateUser` (emailVerified) already had the rule -- switched to
`$request->boolean()` anyway for consistency/defense-in-depth, not because they were live bugs.
`MessageController` (confidential, on both create and update) had no `'boolean'` rule at all --
added it. `TestimonialController::markTestimonial` and `GroupTherapyController::joinGroupTherapy`
use a plain `Request` with no FormRequest/validation at all -- fixed via `$request->boolean()`
alone, which normalizes correctly regardless of any validation rule.
`ProfileController::update`'s `(bool) $request->dob ? $request->dob : null` is a different shape
(a truthiness check deciding whether to null out a date value, not a boolean-flag parse) --
simplified to `$request->dob ?: null`, not a security fix.

**Why**: matches SCRUM-125's established discipline -- a plausible-sounding pattern doesn't mean
every site is actually exploitable; checking each one individually is what determined `confidential`,
`use`, and `anonymous` (join) were live bugs while the others were already safe. Two more
magic-property route-param instances were noticed in passing while reading these controllers
(`AdministratorController::updateUser`'s `$request->userId`, `TestimonialController::markTestimonial`'s
`$request->testimonialId`) -- out of this ticket's scope, added as a comment to the already-open
SCRUM-130 rather than filing a duplicate ticket.

---

## 2026-08-27 — Process change: Playwright MCP wired in for autonomous UI/QA verification

**Decision**: per explicit user request, the Playwright MCP tools (`mcp__playwright__*`) are now
part of the standard feature-development workflow at two points: a quick main-session smoke-check
right after implementing a UI change (before handing off to `reviewer`/`security-engineer`), and
`qa-engineer`'s own fuller acceptance-criteria walkthrough as part of its existing verification
pass. This is autonomous (no per-use approval needed) for full-ceremony feature work with a
frontend/UI component; available but not mandatory for bugfixes/chores that happen to touch a
`.vue` file. Documented in the new `.claude/workflows/playwright-qa.md` (the use-case catalog),
`.claude/agents/qa-engineer.md` (tool grant + instructions), `.claude/workflows/feature-development.md`
(the two checkpoints), and `CLAUDE.md` (pointers from the relevant existing sections). Local
permission allow-list (`.claude/settings.local.json`, gitignored) broadened with
`mcp__playwright__*` so this doesn't hit per-call approval friction, consistent with the existing
semi-autonomous-execution permission handling.

**Why**: Pest's HTTP/JSON-assertion layer can't see what actually renders or behaves in a real
browser — this codebase already has precedent for that gap mattering (the `RequestResource`
identity-masking bugs were about exactly the kind of thing a backend-only test can't catch). The
user wants this fired autonomously once work reaches the relevant sections, not asked for each
time. Scoped to full-ceremony features (not blanket-applied to every bugfix) to avoid ceremony
creep on small changes, per the same "match the weight to the work" principle as the rest of
CLAUDE.md's process tiers.
---

## 2026-08-27 — SCRUM-135: filed SCRUM-136 for a real, unrelated bug surfaced during review

**Decision**: implemented both parts of the ticket as scoped (delete `app/Http/Kernel.php`,
add a unique constraint migration for `links.uuid`). Added a DB-level regression test for the
new constraint (`tests/Feature/LinkUuidUniqueConstraintTest.php`) after `reviewer` correctly
flagged its absence as breaking this codebase's own established convention (the analogous
SCRUM-80/SCRUM-99 unique-index migrations both ship with one). Verified empirically: removed the
migration file, confirmed the test fails with the DB not rejecting a duplicate insert, restored
it, confirmed it passes.

`security-engineer`, while confirming the `Kernel.php` deletion itself is safe, surfaced an
unrelated, pre-existing bug in `bootstrap/app.php` (not touched by this PR): `$middleware->use([...])`
*replaces* Laravel's entire global middleware stack rather than appending to it, silently
disabling `TrustProxies`, `HandleCors`, `PreventRequestsDuringMaintenance`, and several others
app-wide. Verified directly against the framework source
(`vendor/laravel/framework/.../Configuration/Middleware.php`) -- confirmed real, not a false
alarm. Filed as SCRUM-136 (High) rather than fixing inline, since it's unrelated to this PR's
scope and `bootstrap/app.php` isn't part of this diff.

**Why**: matches this session's standing discipline of verifying subagent findings against the
actual source before acting on them (same pattern as the SCRUM-133 false-alarm correction), and
filing rather than silently fixing keeps this PR's diff scoped to what it says it does, per
CLAUDE.md's "keep commits small and focused" -- a global-middleware fix affecting every request
in the app deserves its own PR, review, and rollout attention, not to ride along inside an
unrelated dead-code-removal chore.
## 2026-08-27 — SCRUM-136: corrected a stale finding, filed two genuinely-new follow-ups

**Decision**: fixed `bootstrap/app.php`'s `$middleware->use([...])` → `append(...)` as scoped.
`reviewer` approved and suggested strengthening the new test to assert ordering (TrustProxies
before StoreVisitationMiddleware), not just presence -- added, since the original assertion
wouldn't have caught a future `append()` → `prepend()` regression that silently breaks IP
resolution. `reviewer` also surfaced a genuinely new, unrelated bug (`StoreVisitationMiddleware`'s
stray semicolon making its `/login` guard a no-op) -- filed as SCRUM-137.

`security-engineer` approved the fix as safe, confirmed the current no-op `TrustProxies::$proxies`
config is *correct* for this repo's actual topology (nginx uses `fastcgi_pass`, not `proxy_pass`
-- no HTTP-level proxy hop exists yet, so there's nothing to configure trust for today) rather
than a gap, and recommended two follow-ups: (1) configure `TrustProxies` once a real reverse
proxy/LB/CDN is introduced -- filed as SCRUM-138; (2) delete `app/Http/Kernel.php` -- **not
filed**, because that subagent's review branch was cut from `develop` before SCRUM-135's PR #73
(which already deletes that exact file) had merged, so it was seeing pre-SCRUM-135 state, not a
new gap. Corrected in the PR comment rather than filing a duplicate.

**Why**: same discipline as the SCRUM-133 false-alarm correction earlier in this sweep -- a
subagent's review branch reflects whatever `develop` looked like at the moment it was cut, not
the current state of all in-flight PRs, so a finding that matches already-completed-but-unmerged
work is a branch-topology artifact, not a real gap, and should be verified (`git show` /
checking the other PR's diff) before filing a duplicate ticket.
## 2026-08-27 — SCRUM-128: fixed the bug in a second, deeper location the ticket didn't name

**Decision**: the ticket's own diagnosis named only `UpdateSessionRequest::rules()`. Empirically
reproducing the described symptom (PATCH with only `{"name": "..."}`) after fixing just that file
showed the same class of bug one layer deeper, in `EnsureSessionDataIsValidAction::validateTherapy()`
(called unconditionally by `SessionService::updateSession()`, independent of the FormRequest) --
it re-parsed the same `null` startTime/endTime and threw a 422 with a near-identical message.
Fixed both: `UpdateSessionRequest` now only parses submitted fields; the Action now falls back to
the session's existing `start_time`/`end_time` when the DTO's values are null, so the real
double-booking/30-minutes-apart conflict checks still run against the session's actual schedule
on a partial update rather than being skipped outright. Also removed an unrelated leftover
`Log::info('update session request', ...)` debug line found in the same file.

Post-review (security-engineer) surfaced a second gap in the initial fix: `UpdateSessionRequest`
treated "omitted" via `filled()` (blank string counts as not-provided) while the Action treated it
via a strict `!== null` check -- so a request sending `"startTime": ""` slipped past the
FormRequest's guard but still hit `Carbon::parse('')`, which returns "now" just like
`Carbon::parse(null)`. Fixed by normalizing once, in `UpdateSessionRequest::prepareForValidation()`
(blank -> null via `$this->merge()`), rather than duplicating a blank-check in every downstream
consumer -- this also transitively fixes `UpdateSessionAction::setValueOnData()`'s matching
`is_null()` blind spot, since the controller's `$request->startTime` reads the merged value.

**Why**: this is the same empirical-verification discipline used throughout this sweep -- a
ticket's stated root cause is a hypothesis, not a guarantee; the fix isn't done until the actual
reported symptom is confirmed gone by reproducing it, not just until the named file compiles.
Normalizing at the single request-parsing entry point (rather than adding matching `blank()`
checks in the Action and in `UpdateSessionAction`) avoids the exact kind of inconsistent-null-
handling-across-layers that caused this ticket in the first place.

---

## 2026-08-27 — SCRUM-130: one regression test per controller, not per method; systematic sweep found more instances (SCRUM-133)

**Decision**: fixed every magic-property route-param usage SCRUM-130 named (`DiscussionController`,
`MessageController`, `ReportController`, `TherapyTopicController`, `UserController::deleteGuardianship`,
`CounsellorController`, `AdministratorController`), plus two already-noted-in-passing instances from
SCRUM-126 (`AdministratorController::updateUser`/`deleteUser`'s `userId`, `TestimonialController`'s
`testimonialId`) and `TherapyTopicController::createTherapyTopic`/`getTherapyTopics`'s `therapyId`,
which SCRUM-130's own ticket text incorrectly assumed was already fixed (verified it wasn't).
Added one regression test per *controller* (not per method, despite most controllers having 3-9
affected methods), using an admin actor wherever the target action has an `isAdmin()` bypass, to
keep fixture setup tractable. Also ran a systematic sweep -- every route-bound `{param}` across
`routes/web.php`/`routes/api.php` checked against every controller's magic-property usages at the
specific-method level -- which found six more affected controllers (`CommentController`,
`ContactController`, `HowToController`, `LinkController`, `PostController`, `RequestController`).
Filed as SCRUM-133 (High, given `RequestController::respond` backs org/group-therapy/guardianship/
counsellor accept-reject flows) rather than expanding this already-large PR further.

**Why**: the acceptance criteria said "a regression test per affected controller," which one
well-chosen representative test per controller satisfies without needing 20+ near-duplicate tests
for every method sharing the identical bug shape. `TherapyTopicController`'s therapyId correction
matters because a source ticket's own claims about "already fixed" sites shouldn't be trusted
without verifying, same discipline as SCRUM-125's empirical-verification lesson. Continuing to
sweep and fix every newly-found instance inside SCRUM-130 itself would make an already-large PR
open-ended -- filing SCRUM-133 keeps this PR reviewable while still surfacing the additional risk
immediately rather than losing track of it.

---

## 2026-08-27 — SCRUM-130: patched the unreachable deleteCounsellor site anyway; found a dead frontend route reference (SCRUM-134)

**Decision**: security-engineer confirmed `CounsellorController::deleteCounsellor` genuinely has
no registered route (grepped both route files), but flagged it as a landmine: the frontend
(`resources/js/Pages/Profile/Counsellor/Show.vue`) already references a `counsellor.delete` route
name that doesn't exist anywhere, meaning the delete-account button is currently non-functional --
a very plausible future fix (wiring up that route) would silently reintroduce this exact spoofing
bug if `deleteCounsellor` isn't revisited at the same time. Patched `deleteCounsellor` to use
`$request->route('counsellorId')` anyway, even though it's currently dead code, as cheap
insurance. Filed the broken frontend route reference itself as SCRUM-134 (Medium, a separate
functional bug) rather than fixing it here. Also added two tests reviewer flagged as the
highest-risk untested lines in the PR: `MessageController::getTopicMessages` (the one method in
that file where topicId is actually route-bound, unlike every other method) and
`TherapyTopicController::getTherapyTopics` (the therapyId correction the ticket text got wrong).

**Why**: a "no route, so not exploitable" argument is only true until someone adds the route --
fixing the pattern now, while already in this file for the exact same bug class, costs nothing
and removes the dependency on the fix being remembered later. The two added tests target the
lines both subagents independently called out as most likely to silently regress, rather than
treating "one test per controller" as fully satisfied by whichever method happened to be chosen
first.
## 2026-08-27 — SCRUM-133: fixed PostController::getPost's session-stash too; skipped a second Link test

**Decision**: fixed every site SCRUM-133 named across `CommentController`, `ContactController`,
`HowToController`, `LinkController`, `PostController`, `RequestController`. Also fixed
`PostController::getPost`, which the ticket flagged as ambiguous ("doesn't look up a Post model
directly... check whether this one needs the same fix or is otherwise inert") -- it doesn't look
up a model, but it does `session()->put('postId', $request->postId)`, which would stash a
client-spoofed id (this is a GET route, so spoofable via query string) for whatever later reads
`session('postId')` to act on -- same bug, same fix. One test per controller (6 total), except
`LinkController` gets only one (`changeLinkStatus`) despite also having a second affected method
(`performAction`, keyed by `uuid` not `linkId`) -- `performAction`'s authorization
(`EnsureCanUseLinkAction`) has no admin-bypass and dispatches to type-specific side-effecting
actions, making a second fixture meaningfully more expensive for a fix that's mechanically
identical to `changeLinkStatus`'s, unlike SCRUM-130's `TherapyTopicController` case where the
second site carried a distinct, ticket-text-contradicting risk.

**Why**: `getPost`'s session-stash doesn't fit either of SCRUM-116/130's "clearly still vulnerable"
or "clearly already safe" buckets as written, so it needed its own read of the code rather than
guessing from the ticket's hedge -- confirmed vulnerable and fixed. Declining a second Link test
follows the same per-controller (not per-method) acceptance criteria SCRUM-130 established, applied
consistently: extra tests are worth their setup cost only when they cover meaningfully different
risk, not merely a different route-param name for the identical one-line fix.

---

## 2026-08-27 — SCRUM-133: added the performAction test after all; corrected a false-alarm review finding; severity nuance on two sites

**Decision**: both reviewer and security-engineer pushed back on skipping a second
`LinkController` test for `performAction`, noting it has no admin bypass and its side effects
(creating a guardianship, discussion participation, or counsellor affiliation) are more
consequential than `changeLinkStatus`'s state toggle. Added it. Also corrected a security-engineer
finding that flagged `UserController::deleteGuardianship`/`ReportController`/`TestimonialController`/
`DiscussionController`/`CounsellorController` as still-unfixed instances of this bug: verified via
`git show` that all five are already fixed on `bugfix/scrum-130-route-param-spoofing-more-controllers`
(PR #70) -- the subagent reviewed this branch, which was cut from `develop` *before* PR #70 merged,
so those files' pre-fix state on this specific branch is expected, not a new gap. No new ticket
filed for this; PR #70 already covers it. Also incorporated security-engineer's severity nuance for
two sites: `RequestController::respond`'s fix is real (a user could act on a *different one of
their own* addressed items, not an arbitrary other user's -- `EnsureUserCanRespondToRequestAction`
checks the resolved request's own `to`, so authorization is enforced on whichever record ends up
in the DTO either way) but narrower than "arbitrary cross-user IDOR"; `PostController::getPost`'s
fix is correct (its `session('postId')` is read by `HomeController::goHome`) but Posts have no
access control anywhere in the app regardless, so this specific site is a confused-content bug,
not a privacy escalation. Filed SCRUM-135 (Low) for two unrelated hygiene items security-engineer
noticed in passing (apparently-unused `app/Http/Kernel.php`, missing unique constraint on
`links.uuid`).

**Why**: two independent subagents converging on the same "add this test anyway" conclusion,
backed by a concrete structural difference (no admin bypass, more consequential side effects), is
exactly the signal that should override an initial per-controller-is-enough judgment call. The
"other controllers still vulnerable" finding needed verification, not acceptance at face value --
same discipline as SCRUM-125's empirical-verification lesson -- and turned out to be explainable
by branch topology rather than a real gap. Overstating a finding's severity is as much a
verification failure as understating one; recording the corrected nuance keeps the PR/Jira trail
accurate for whoever reads it later without re-deriving the same analysis.
## 2026-08-27 — SCRUM-127: fixed the same crash on group-therapy creation too, not just update

**Decision**: widened `CreateTherapyDTO`'s `public`/`allowInPerson`/`anonymous` and
`GroupTherapyDTO`'s `public`/`allowInPerson`/`anonymous`/`allowAnyone`/`shareEqually`/
`counsellorIds` to nullable, per the ticket's ask. While auditing, found the identical crash was
also reachable on group therapy *creation* (not just the update path the ticket described):
`CreateGroupTherapyRequest` already allows `counsellorIds` to be omitted (`'nullable'`), and has
no validation rule for `shareEqually` at all, so both could already reach `fromArray()` as `null`
on a create request. Fixed both DTOs' properties in one pass rather than filing a separate ticket,
since it's the exact same root cause and fix shape the ticket already asked to audit for
("audit both DTOs fully for every non-nullable property... not just the two found so far").

**Why**: the ticket's own text explicitly asked for a full audit, not just the two originally
-reported properties -- finding and fixing the create-path instance of the same bug in the same
PR is honoring that ask, not scope creep. `UpdateTherapyAction`/`UpdateGroupTherapyAction` and
`SendTherapyAssistanceRequestAction` already had correct null-skip/null-guard logic in place for
every one of these fields -- the bug was purely the DTO property type declarations, confirmed by
reverting the fix and watching the new regression tests fail with the exact generic-500 message
described in the ticket, then pass again once restored.

---

## 2026-08-27 — SCRUM-127: newly-reachable partial-update validation nuance filed as SCRUM-132, not fixed here

**Decision**: reviewer and security-engineer both independently found that
`EnsureTherapyDataIsValidAction`'s `payment_type == free` / `public` cross-field check reasons
only about the current request's DTO values, not the record's persisted state -- previously
unreachable for any partial update omitting a boolean field (it always crashed with a 500 first),
now reachable since SCRUM-127 fixed that crash. Filed SCRUM-132 (Low) rather than expanding this
PR, since it's a pre-existing validation-design gap this PR exposed, not one it introduced.

**Why**: SCRUM-127 was scoped to "stop the crash," verified by both subagents to fail safe (the
false-positive case rejects rather than silently corrupting state) with no privacy/authorization
impact -- exactly the kind of newly-exposed-but-out-of-scope finding that gets its own ticket
per the established pattern (SCRUM-127/128/129 themselves, SCRUM-130/131), not silent scope
expansion into redesigning a validation action's partial-update semantics.

---

## 2026-08-28 — SCRUM-130: fixed a cross-PR CI failure surfaced by merge order

**Decision**: `RouteParamSpoofingBatch2Test.php` (this PR) and `RouteParamSpoofingBatch3Test.php`
(SCRUM-133) each independently declared a top-level `anAdmin()` Pest helper -- harmless while
both PRs were separate, unmerged branches, but SCRUM-133 merged into `develop` first while this
PR was still open, so once GitHub tested this PR's branch merged against the new `develop` tip,
both files loaded in the same run and PHP fataled on redeclaring a global function. Fixed by
merging current `develop` into this branch and renaming this file's helper to
`anAdminForBatch2()` (all 8 call sites updated). Verified via `php artisan test --parallel`
(the actual CI command) -- 483 passed -- and confirmed no other duplicate top-level helper names
exist elsewhere in the suite.

**Why**: Pest test files share one global PHP namespace for top-level `function` declarations
(not scoped to a class), so any two files that each define a same-named helper will collide the
moment both are loaded together -- independent of which one is "correct" or which came first.
This is a variant of the existing "Pest --parallel test helpers" rule (never call a global helper
defined in a different file) worth keeping in mind specifically for this sweep's pattern of
adding near-identical "BatchN"-style regression test files across sibling PRs: give each file's
local helpers a unique, file-scoped name up front rather than reusing an obvious name like
`anAdmin()` that another sibling PR is equally likely to reach for.

---

## 2026-08-28 — SCRUM-132: user directed scope expansion; surfaced a High-severity live bug during review

**Decision**: per explicit user direction, generalized the fix from the ticket's two named
checks to all six checks in `EnsureTherapyDataIsValidAction` with the identical partial-update
reachability bug (see PR #76 body for the full list). PR #76 merged before `reviewer`'s findings
came back; addressed the one required change (missing test coverage for the paid+once+per check)
and both suggestions as a small follow-up, PR #77, since the original branch/PR was already closed.

`security-engineer`'s review of #76 surfaced a separate, High-severity, actively-occurring bug in
`UpdateTherapyAction`/`UpdateGroupTherapyAction::setValueOnPaymentData()` (pre-existing, not
introduced by SCRUM-132): any partial update omitting payment fields silently nulls out the
entire `payment_data` JSON column for a PAID therapy/group-therapy, even though
`EnsureTherapyDataIsValidAction` correctly validates the (never-persisted) effective state as
consistent first. Confirmed via direct tinker reproduction against both actions. Filed as
SCRUM-140 (Highest) and picked up immediately given the live-data-corruption impact, rather than
just filing and moving to the next ticket.

**Why**: this is a case where a subagent's finding, while outside the current PR's diff, was
severe and immediately actionable enough (silently corrupts production pricing data on essentially
any ordinary edit, no adversarial action needed) to warrant fixing right away rather than only
logging a follow-up ticket for later -- consistent with CLAUDE.md's "never skip or silently ignore
a finding" rule, weighted by how urgent this particular one is compared to the Low/Medium-severity
follow-ups filed earlier in this sweep (SCRUM-137/138/139), which were correctly left for later.
## 2026-08-28 — SCRUM-129: paused for a genuine product decision, then extended scope on a related unambiguous bug

**Decision**: `isNotUpdateable()`/`isNotDeleteable()`'s scoping bugs were fixed directly (unambiguous
query-logic errors, matching the ticket's own suggested fix). For the `EnsureSessionDataIsValidAction`
`whereDoesntHave`/`whereHas` question, the ticket text itself flagged genuine uncertainty about
intended business-rule semantics -- paused and asked the user before touching production
double-booking validation logic, rather than guessing. Confirmed: flip to `whereHas`. Cross-checked
against `EnsureDiscussionDataIsValidAction`'s already-correct analogous check to validate the
direction before implementing.

Also fixed `isNotDeleteable()` (not named in the ticket, same file/bug class as `isNotUpdateable()`,
found while reading the trait) -- it had no id-scoping at all (`$this->where(...)` on a model
instance queries the whole table), confirmed empirically via `->toSql()`.

Post-review (`reviewer`), added Discussion-branch test coverage for `isNotUpdateable()` (it branches
explicitly on `Session::class` vs `Discussion::class`, and only the Session branch had a test) and
filed SCRUM-139 for a related-but-separate finding: `EnsureDiscussionDataIsValidAction`'s own
`where`/`orWhere` chains have the identical textual shape as SCRUM-129's bug, but happen to be
correct today only because `whereDateIsBetweenStartAndEndTimes`/`whereIsThirtyMinituesBeforeOrAfter`
are Eloquent local scopes, whose auto-grouping behavior masks the issue -- not fixed here since it's
not a live bug, just a latent footgun in a different file.

**Why**: this is the clearest example this session of correctly distinguishing "the ticket names an
unambiguous bug, fix it" from "the ticket itself says this needs a decision" -- SCRUM-129's own text
explicitly said to re-check intent with product before changing the whereDoesntHave logic, and the
autonomous-execution policy's carve-out for genuinely ambiguous/consequential product decisions
applies exactly here (getting a booking-conflict rule wrong is costly in either direction). Filing
SCRUM-139 rather than fixing it inline keeps this PR scoped to what it says it does, per the same
"keep commits small and focused" principle applied throughout this sweep.

---

## 2026-08-28 — SCRUM-140: fixed a live High-severity data-corruption bug found mid-review

**Decision**: implemented the fix as scoped by the ticket (only write payment_data keys the DTO
explicitly provided). `reviewer` confirmed the simplified condition is strictly equivalent minus
the buggy null-overwrite branch, and flagged a secondary, previously-dormant `$objectKey`-vs-
`$dataKey` inconsistency in `UpdateGroupTherapyAction::setValueOnPaymentData()`'s old code that
the fix also incidentally corrects (harmless today since no call site passes a differing
`$objectKey`, but worth noting as a real behavior change beyond the stated bug). `security-engineer`
confirmed the fix closes the gap with no new inconsistency direction, and flagged that rows already
corrupted by this bug before the fix ships won't self-heal -- filed SCRUM-141 for the production
audit/backfill task.

**Why**: this is the second time in this sweep (after SCRUM-136/137/138 during the middleware fix)
that a security-engineer review surfaced something beyond the current PR's diff -- in both cases,
severity determined the response: High/live-and-worsening issues (this one, and SCRUM-136 itself)
got fixed immediately in a follow-up PR; Low/deferred-until-a-future-event issues (SCRUM-138, and
now SCRUM-141) got filed and left for later. Consistent triage criterion: is this actively
happening/getting worse right now, or is it a one-time cleanup/future-conditional concern.

---

## 2026-08-28 — SCRUM-109: deploy migrations + health-check backstop (merged)

**Decision**: implemented both halves of the user-chosen "Both" option: `php artisan migrate --force`
added to the SSM deploy chain in `.github/workflows/main.yml`, plus a `FailHealthCheckOnPendingMigrations`
listener on Laravel's `DiagnosingHealth` event as a backstop for whenever that step is ever skipped or
fails silently. `reviewer` approved with one non-blocking style suggestion (prefer `handle(Migrator
$migrator)` parameter injection over `app(Migrator::class)` in the listener body) -- left as-is since
the PR was already merged before triaging it, and it's purely idiomatic with no functional effect.
`devops-engineer` flagged real operational gaps in the broader deploy pipeline (no maintenance-mode
wrap around the live single-instance deploy, MySQL DDL isn't transactional so a partially-applied
migration won't self-heal on retry, `aws ssm send-command`'s result is never checked so a failed
`migrate --force` would show green in GitHub Actions, and the leading `git stash` in the deploy chain
would silently discard any on-server hand-fix made during incident recovery) -- all pre-existing
pipeline characteristics, not regressions introduced by this PR, and out of scope for a "run migrations
+ add a health check" ticket. Confirmed no regression of SCRUM-94's exception-disclosure fix: the `/up`
route's Blade view never echoes the exception message in the HTTP body regardless of `APP_DEBUG`.

**Why**: the devops findings describe a real, pre-existing single-instance-deploy risk profile that
predates this ticket and would take a separate infra-hardening effort (maintenance mode, SSM
result-checking, migration idempotency review) to address properly -- not something to bolt onto a
bugfix PR. Not filing a ticket for these yet since they're a cohesive block of related infra work
better scoped as one deliberate follow-up conversation with whoever owns infra, rather than several
disconnected Low-priority tickets; noting them here so the context isn't lost.

---

## 2026-08-28 — SCRUM-108: implemented validateGroupTherapy(), fixed a real bug found along the way

**Decision**: implemented `EnsureSessionDataIsValidAction::validateGroupTherapy()` (previously a
completely empty stub) mirroring `validateTherapy()`, per the user's explicit choice ("Implement it
now, mirroring validateTherapy()"). While writing the addedby-owner double-booking check, found that
`GroupTherapy::scopeWhereUser()` only matched the `group_therapy_user` join-pivot and never the
`addedby` column -- so a group therapy's own direct creator was invisible to `whereParticipant()`/
`whereUser()` queries, including the live `GroupTherapyService::getRecentGroupTherapies()` dashboard
query. Fixed it to also match `addedby_type === User::class && addedby_id === $user->id`, consistent
with `Therapy::scopeWhereUser()`'s existing pattern, and added a dedicated regression test
(`GroupTherapyWhereParticipantTest`) separate from the SCRUM-108 test file since it's a distinct bug.
Verified both fixes empirically (temporarily reverted each independently, confirmed the relevant
tests fail, restored, confirmed they pass).

Noted but deliberately not touched: `GroupTherapy` already has a separately-defined
`scopeWhereIsParticipant()` (used by `Session`'s `whereHasMorph` dispatch) that already had the
correct addedby-is-User logic -- my fix now duplicates that logic under a different, similarly-named
scope. Flagging as a pre-existing duplication smell for a future cleanup pass, not consolidated here
to keep this PR scoped to the double-booking bug it needed to fix.

**Why**: this is the session's continuing "fix the underlying bug directly when it's small and
directly blocks the current ticket's own required functionality" pattern (same reasoning as SCRUM-
129's Timeable fix) -- without this fix, SCRUM-108's own addedby-owner double-booking check would be
silently non-functional for the most common case (a group therapy created directly by a User rather
than joined via the pivot).

**Post-review** (`reviewer` + `security-engineer`, both approved with no required changes): added the
one suggested test both agents flagged as the genuinely new/risky path -- a group therapy whose
`addedby` is itself a Counsellor (the one branch with no `validateTherapy()` analogue at all). This
also surfaced and pinned down a real, if minor, behavior quirk: because that counsellor is resolved
both as `$addedbyUser` (via `addedby->user`) and inside `getCounsellors()` (which pushes `addedby`
when it's a Counsellor), a conflicting session for them trips the generic addedby-owner check first,
so the thrown message is "The user has sessions..." rather than the more-specific "Counsellor for
this group therapy has sessions..." -- not a correctness bug (the session is still correctly
rejected), just a slightly misleading message in that one scenario. Left as-is rather than
deduplicating the two checks, per `reviewer`'s own "low priority" framing.

Filed SCRUM-144 for `reviewer`'s two other findings: (a) the `scopeWhereUser`/`scopeWhereParticipant`
vs `scopeWhereIsParticipant` duplication flagged when the fix was made, and (b) `scopeWhereNotUser()`
not having been updated alongside `scopeWhereUser()`, so the two are no longer logical complements --
latent since `scopeWhereNotUser()` on `GroupTherapy` currently has no callers, but a trap for
whichever future change adds the first one. Declined the two remaining optional suggestions (a
partial-update test mirroring the Therapy-side coverage, and deduping the addedby-owner/counsellor-
loop double-query above) as genuinely low-value for a verbatim-mirrored code path already covered by
the Therapy-side tests -- explicitly noted here rather than silently dropped.

---

## 2026-08-28 — SCRUM-134: counsellor account deletion, full /start-feature ceremony

**Decision**: per the user's explicit choice ("Implement the feature properly") this went through
the full `product-owner` -> `project-manager` -> `architect` gate before any code was written,
since CLAUDE.md requires that for new feature work regardless of the autonomous-execution policy.
The user then answered four open questions directly: (1) `current_password` re-confirmation is
required; (2) the admin-triggered flow should be included in this ticket, not deferred; (3) former
clients should be notified when a counsellor deletes their account; (4) soft-delete now, hard-delete
via a scheduled job after a *configurable* grace period, default 60 days.

Several concrete technical decisions were made autonomously while implementing these answers (not
themselves reopened with the user, since they're implementation details of an already-approved
direction, not new product forks):

- **Admin path requires `isSuperAdmin()`, not just `isAdmin()`** -- matches
  `UserService::deleteUserByAdmin()`'s existing precedent for an equally destructive action on
  another account. `EnsureCanDeleteCounsellorAction`'s pre-existing single admin-or-self condition
  was tightened accordingly (previously unreachable via HTTP at all, since no admin route existed).
- **The admin path does not bypass the eligibility gate** -- an admin can trigger deletion, but is
  still blocked by pending sessions/in-session therapies/active affiliations, same as self-service.
  A true emergency-override mechanism, if ever needed, would be separate, out-of-scope work.
- **Config file over `env()` calls** -- `config('counsellor.deletion_grace_period_days')` via a new
  `config/counsellor.php`, rather than following the existing (but not-best-practice)
  `env('GROUP_THERAPY_MAX_USERS', 50)`-in-application-code pattern found in
  `EnsureTherapyDataIsValidAction`. A new config value has no reason to inherit that pattern's
  config-caching-unsafety; the existing instance was left alone as out of scope.
- **Hard-delete scope is narrow**: only the `Counsellor` row itself is force-deleted after the grace
  period. Related historical records (therapies, sessions, licenses, testimonials) are left
  untouched -- consistent with the non-functional requirement `product-owner` raised ("never
  hard-delete... licenses or verification documents... legal/dispute history must survive") and
  with how those records already tolerate a merely-soft-deleted counsellor via `withTrashed()`.
- **Request gate/cleanup split resolved by `architect`**: the eligibility gate only checks
  `receivedRequests()` (things awaiting the counsellor's own decision); cleanup only auto-declines
  `sentRequests()` (things the counsellor initiated that become moot). `groupTherapyMembership`
  requests are excluded from both -- they always resolve to the underlying `User`, never the
  `Counsellor` model, so deleting the counsellor doesn't affect them at all.
- **`counsellor_group_therapy` pivot cleanup**: state-flip to `inactive`, not `detach()` --
  `architect` confirmed this matches the pivot's own `state`-column design and how
  `GroupTherapy::isCounsellor()` already reads it, unlike `counsellor_discussion` (no state column,
  hard-detach is correct there, mirroring the existing `RemoveCounsellorFromDiscussionAction`).
- **Dedicated seeded test data** (`deletable_counsellor`, `blocked_counsellor`) added rather than
  reusing the 6 main demo counsellors, since those are woven into therapies/group
  therapies/discussions/chat demo data used by many other features -- deleting one for this
  feature's own testing would be destructive to unrelated scenarios.
- **Playwright browser smoke-check skipped**: port 8000 (this project's `web`/nginx service) was
  occupied by an unrelated, pre-existing container from a different project on this machine.
  Deliberately not stopped/touched, since it's infrastructure with no context available about what
  else depends on it -- noted in the feature doc as follow-up verification still needed before
  merge, rather than silently skipped.

**Why**: this is the session's first true full-ceremony feature (as opposed to the bugfix sweep
that preceded it), and the volume of technical sub-decisions above is exactly why CLAUDE.md
distinguishes "genuinely ambiguous/consequential" product forks (which went back to the user) from
implementation details of an already-approved direction (which don't need re-litigating one at a
time) -- re-asking about each of these would have meant seven more round-trips for decisions each
backed by a clear precedent already in the codebase.

---

## 2026-08-28 — SCRUM-134 post-review: one correctness bug fixed, one severe pre-existing bug found via manual browser QA

**Decision**: `reviewer` requested changes on one real bug; `security-engineer` found nothing above
Low severity. Both fixed, plus a second, more severe bug found independently while doing the manual
browser walkthrough this ticket's own feature doc had flagged as still owed.

1. **`DeleteCounsellorAction::getFormerClients()` notified co-counsellors and the deleted
   counsellor themselves** (`reviewer`, required fix). `GroupTherapy::getUsers()` -- a
   general-purpose helper used nowhere else -- returns every counsellor attached to a group, not
   just clients, and (since this counsellor's own pivot row hasn't been flipped to `inactive` yet
   at the point notifications are gathered) that includes the counsellor being deleted. Fixed by
   building the group-therapy recipient list from the actual `group_therapy_user` pivot members
   plus a `User`-type owner only. New regression test with two counsellors on one group therapy,
   asserting neither the co-counsellor nor the deleted counsellor's own account gets notified.
   Verified empirically (reverted, confirmed the new test fails, restored).

2. **`Counsellor::hasPendingSessions()` had the same ungrouped `where()->orWhere()` scoping bug
   already fixed twice elsewhere this sweep** (SCRUM-129's `Timeable` trait, SCRUM-139's
   `EnsureDiscussionDataIsValidAction`) -- found not by static review but by actually clicking
   through the self-service delete flow in a browser: `deletable_counsellor` (seeded with zero
   sessions of their own) was rejected with "You have sessions that need to be completed..." purely
   because *some other counsellor's* session happened to be upcoming. Only `wherePending()` stayed
   scoped to `addedSessions()`; the trailing `orWhere()`s broke out to match any session in the
   whole table. This silently made the entire feature this PR ships non-functional for any
   counsellor as soon as any session anywhere in the system was upcoming or about to start --
   effectively always, in a live app. Fixed by grouping all three conditions inside one outer
   `where()`. New dedicated test file (`CounsellorHasPendingSessionsTest`) since this method had no
   prior coverage at all; verified empirically the same way as (1).

**Why**: this is the second time this exact `where()->orWhere()` shape has hidden a live bug behind
what looked like correct, tested code (SCRUM-129 was the first). It reinforces the standing
practice from this sweep of never trusting a boolean-returning query helper's *name* -- reading
its actual generated SQL (or, here, just clicking the feature) is what catches this class of bug,
because unit tests that only ever create one relevant row per test never exercise the "another
unrelated row exists" case that this ungrouped-OR footgun depends on. Also reinforces why the
Playwright/manual-browser step of full-ceremony QA is not a formality: this bug was invisible to
34 passing unit/feature tests and was caught within the first real click-through.

3. **`FormLoader.vue`'s outer `fixed` wrapper intercepted clicks meant for whatever was underneath
   it, whenever it was supposedly hidden** (`qa-engineer`, found via Playwright). Its inner child
   collapses via `invisible`/`opacity-0`/`h-0` when `show` is false, but the outer wrapper (`fixed
   w-full z-10 left-0 bottom-4`) never got a matching visibility/pointer-events class -- it kept
   occupying real, hit-testable box space at a fixed viewport position regardless of `show`. This
   is a pre-existing defect shared across roughly 48 usages of this component, not something SCRUM-
   134 introduced, but it happened to fully block mouse users from clicking either button in this
   PR's own new delete-confirmation modal -- only the password field's `@keyup.enter` handler let
   deletion complete at all (confirmed both ways: Playwright's real click timed out with
   "intercepts pointer events" before the fix, and worked cleanly after).

   **Fixed in place, not deferred to a follow-up ticket**, despite being pre-existing: severity-
   based triage (the same criterion used all session) puts this in the "actively blocking a golden
   path right now" bucket, not "one-time cleanup" -- a real end user cannot currently delete their
   own counsellor account by clicking a button, only by knowing to press Enter after typing their
   password. Fix: toggle `pointer-events-none`/`pointer-events-auto` on the outer wrapper to match
   `show`, without touching the inner element's existing opacity/height animation -- purely
   additive for every other usage (when `show` is true, `pointer-events-auto` is a no-op; when
   false, it can only ever stop something that was previously an unintended click-block). Verified
   manually via Playwright (real mouse clicks on both "delete" and "cancel" in the SCRUM-134 modal,
   confirmed via DB before/after) since this project has no JS test framework to add automated
   component-level coverage.

---

## 2026-08-28 — SCRUM-131 (TT-6.4c): scope grew from "add a notification" to a full negotiation feature, split into 5 sub-tickets

**Decision**: SCRUM-131 started as "decide and implement counsellor notification for compensation-
terms changes (and evaluate accept/dispute step)" -- a small follow-up from SCRUM-123. Through the
full `/start-feature` product-owner -> project-manager -> architect cycle (run twice, since the
user's answers expanded scope mid-planning), it became: mandatory notification (as asked) +
accept/reject/**counter-offer** (not just accept/dispute) + a **reminder/expiry mechanism** (in
scope now, not deferred, per explicit user direction). Given the resulting ~27-28 point size --
comparable to TT-6.4a/b or TT-6.3a/b before *those* were split -- this project's own established
splitting pattern was applied again: 5 sub-tickets (SCRUM-146-150), sequenced 1/5 (proposal
creation) -> 2/5 (accept/reject) -> 3/5 (counter-offer) -> 4/5 (reminder/expiry) -> 5/5
(admin-facing read API), tracked as **TT-6.4c** in `documentation/implementation_plan.md`.

Key design decisions locked in during planning, all confirmed by `architect`:
- **Zero schema changes to `organization_counsellor_compensations`** -- that table stays exactly
  what SCRUM-122 built (append-only, every row already-accepted). All negotiation state (proposed
  terms, direction, expiry, round count) lives in the generic `requests` table instead (`data`
  JSON column for terms, new nullable `expires_at`/`round` columns for the rest) -- reusable by
  any future request type needing expiry, not compensation-specific columns.
- **"Dispute" = counter-offer, not a new status.** The ticket's own wording chose "dispute" over
  "reject" deliberately; rather than adding a reason field or an escalation/mediation path, the
  user's own suggestion (counter-offer) resolves this cleanly -- a counter-offer atomically
  resolves the current request and creates a new one in the reverse direction. No new
  `RequestStatusEnum` case needed for either dispute or expiry (expiry reuses `rejected`, since it
  must behave identically to a manual reject everywhere except user-facing copy, which gets a
  lightweight non-enum signal instead).
- **Fairness-critical invariant, called out explicitly and required as its own regression test,
  not just a design intent**: reject and expiry, at any round, in either direction, must never
  cascade into pausing or ending the affiliation. A `pending` affiliation whose first-ever
  proposal isn't yet accepted now stays non-active indefinitely by design (a real, deliberate
  behavior change from SCRUM-122's instant single-write activation) -- this is the whole point of
  the feature (real counsellor consent, not just visibility), not an incidental side effect.
- **Round cap (5) and expiry window (7 days default) are both config-driven**, not hardcoded --
  explicitly so that if either number turns out wrong in practice, it's a one-line env change, not
  a code change. The round-cap number itself was flagged by `project-manager` as worth a quick
  confirm but explicitly **non-blocking** for starting 1/5 or 2/5, which don't touch it at all.
- **A real, pre-existing gap almost got "fixed" on a false premise.** Earlier planning (during
  SCRUM-134) concluded `DeleteCounsellorAction` needed an extracted action to resolve pending
  *received* requests on affiliation end, since it only resolves *sent* ones. Re-examining this
  during SCRUM-131's second `project-manager` pass surfaced that `EnsureCanDeleteCounsellorAction`
  already blocks deletion entirely while ANY pending received request exists (for any type) --
  meaning this specific gap may not exist for a bidirectional request type like compensation
  changes, since whichever direction a pending negotiation is in, it's caught by either the
  eligibility gate (if received) or the existing sent-request cleanup (if sent). Flagged to
  empirically verify before writing any fix, rather than trust the earlier claim -- consistent
  with this session's standing discipline of never trusting a subagent's claim (including a past
  one of my own) without reproducing it.

**Why**: this is the second time this session a ticket's scope grew substantially *during* the
`/start-feature` conversation itself (SCRUM-134's admin-flow/grace-period additions were the
first) -- in both cases, the response was to re-run the planning chain against the expanded scope
rather than patch the original plan informally, and to split into sub-tickets once the resulting
size crossed this project's own established single-PR comfort threshold, rather than force an
oversized PR through review. The `DeleteCounsellorAction` false-premise catch is also a concrete
instance of why a completed decision from an earlier ticket (SCRUM-134) should still be
re-verified rather than assumed permanent, once a later ticket's investigation touches the same
code path from a different angle.

---

## 2026-08-28 — SCRUM-146 (TT-6.4c, 1/5): removed `setCompensation()` instead of leaving it as a live bypass, deviating from the ticket's literal "tests unmodified" wording

**Decision**: SCRUM-146's acceptance criteria said "all existing SCRUM-122/123 tests continue to
pass unmodified" -- written during planning, before it was clear that literally satisfying this
meant leaving `OrganizationCounsellorCompensationService::setCompensation()` (the pre-SCRUM-146
unilateral, immediately-effective write) fully intact and still callable. Doing that would have
left a live bypass of this entire feature's purpose: anyone with access to call the service
directly could skip the negotiation/consent flow altogether and write straight to
`organization_counsellor_compensations`.

Instead: `setCompensation()` was removed outright. `proposeCompensationChange()` (new) takes over
its authorization/validation guarding (`EnsureUserCanSetOrganizationCounsellorCompensationAction`,
`EnsureOrganizationCounsellorCompensationDataIsValidAction`, both reused unchanged) but creates a
`Request` instead of writing directly. The regression proof the old tests provided over the
underlying row-creation/activation/versioning mechanics
(`CreateOrganizationCounsellorCompensationAction`, itself completely unchanged and unaffected --
it's what SCRUM-147's accept step will call) was preserved by moving to a new, dedicated
`tests/Unit/CreateOrganizationCounsellorCompensationActionTest.php` that exercises that action
directly, bypassing the removed service method entirely. `tests/Unit/OrganizationCounsellorCompensationTest.php`
was rewritten (not left as-is) to test `proposeCompensationChange()`'s new behavior instead, plus a
new regression test proving `OrganizationCounsellor::currentCompensation()` never returns a pending
proposal's terms (the actual spirit of AC5, even though the specific test bodies changed).

Also empirically verified beyond the earlier `DeleteCounsellorAction` question this session
already resolved (see the SCRUM-131 entry above): reproduced the whole propose flow end-to-end
against the real dev MySQL database (not just the sqlite test suite), including confirming the
`requests.type` native enum column actually accepts the new
`ORGANIZATION_COUNSELLOR_COMPENSATION_CHANGE_REQUEST` value after a fresh migration run -- this
matters because sqlite (used by the test suite) doesn't enforce native enum constraints the way
MySQL does, so a passing test suite alone wouldn't have caught a missed
`$table->enum('type', ...)->change()` migration the way the original 2024-05-25 migration for this
same column required.

**Why**: literal compliance with a planning-time acceptance criterion should give way to the
criterion's actual intent once implementation reveals a conflict -- here, "prove the underlying
mechanics still work" was the intent; "leave a dangerous bypass method reachable" was never the
goal, just an unexamined side effect of how that intent was phrased before the codebase was read
in enough detail to see the tension. Logging this explicitly rather than silently deviating,
consistent with this session's standing decision-log practice for any deviation from what a ticket
literally says.

---

## 2026-08-28 — SCRUM-146 (TT-6.4c, 1/5): PR #84 review findings -- one fixed now, two deferred

**Reviewer** (approved, no blocking issues) and **security-engineer** (no High/Critical; one
Medium, one Low) both audited PR #84 before merge, per CLAUDE.md's mandatory review gate for
anything touching compensation/money.

**Fixed now**: security-engineer found that `RequestService::getRequests()` (the generic
`/requests` list endpoint) renders every hit through `RequestResource`, not
`GetRequestResourceAction`'s per-type dispatch -- and `RequestResource::getFrom()`/`getTo()`/`getFor()`
assumed any non-`User` `from`/`to` was a `Counsellor`, with an unmatched `for_type` falling back to
the same assumption. For the new `organizationCounsellorCompensationChange` type (`from` =
`Organization`, `for` = `OrganizationCounsellor`), this throws an uncaught `BadMethodCallException`
(`Organization`/`OrganizationCounsellor` have no `getName()`) -- meaning a counsellor with a
pending compensation proposal would get a 500 just loading their normal requests list. Both
reviewers noted this generic-resource gap already existed for the 5 pre-existing org-context types
(`organization`, `organizationCounsellorInvite/Application`, `organizationMemberInvite/Application`
-- all `from`/`for` an `Organization` too), but this PR is the first to route a live,
routinely-triggered money negotiation through it. Rather than patch only the new type, fixed
`RequestResource` comprehensively: added an `Organization::class` branch to `getFrom()`/`getTo()`/
`getFor()`, and an `OrganizationCounsellor::class` branch to `getFor()` (rendering organization +
counsellor mini-resources, not the full negotiation payload -- that's `OrganizationRequestResource`'s
job for the two dispatch-aware call sites). This also silently fixes the same latent 500 for the 5
pre-existing types, at no extra cost. Added a regression test exercising `RequestService::getRequests()`
directly for a pending compensation proposal (`tests/Unit/OrganizationCounsellorCompensationTest.php`).
Also applied reviewer's minor comment-staleness finding: `EnsureUserCanSetOrganizationCounsellorCompensationAction`'s
comment claiming "no negotiation workflow yet" was rewritten, since SCRUM-146 is exactly that
follow-up.

**Deferred, not fixed**: both reviewers independently flagged the same TOCTOU race --
`EnsureNoPendingOrganizationCounsellorCompensationRequestAction`'s check-then-create has no DB-level
uniqueness backing it, so two concurrent proposal submissions could both pass the "no pending"
check before either commits. Both agents confirmed this mirrors the *exact same* existing pattern
in `EnsureNoPendingOrganizationCounsellorRequestAction`/`EnsureNoPendingOrganizationMemberRequestAction`
-- consistent with established project risk tolerance, not a regression -- and is not exploitable
today since a pending `Request` here is inert (no compensation row is written until accept). Not
worth a schema/locking change scoped to this PR alone; flagged for SCRUM-147 (accept/reject) to
address (a partial unique index or `lockForUpdate()`) since that ticket is what will need
"at most one pending request per affiliation" to actually hold under concurrency once accept
exists. Also left `config('organization.compensation_negotiation_max_rounds')` unused by this PR
as-is (reviewer's low-severity suggestion to move it to SCRUM-148 where it's consumed) -- both
tunables were added together deliberately during planning as a single config-driven-tunables
decision, so splitting them back apart now would just be churn.

**Why**: CLAUDE.md requires never silently ignoring a reviewer/security finding -- apply a fix, or
explicitly flag why one is deferred with a follow-up. The Medium finding was fixed immediately
since it's a live, easily-triggered usability break once real proposals exist (starting with this
PR). The Low finding is a pre-existing, deliberate-risk-tolerance pattern shared by two sibling
actions already in production, better addressed once, at the ticket that actually depends on the
invariant holding, than patched in isolation here.

---

## 2026-08-28 — SCRUM-147 (TT-6.4c, 2/5): the ticket's planned `EnsureUserCanRespondTo...` action turned out unnecessary

**Decision**: SCRUM-147's scope, written during SCRUM-131's planning, called for a new
`EnsureUserCanRespondToOrganizationCounsellorCompensationRequestAction` gating who may
accept/reject a proposal. Before writing it, read the existing generic
`EnsureUserCanRespondToRequestAction` (already invoked by `RequestService::respondToRequest()`
ahead of every type's dispatch) and traced through its checks against this ticket's actual scope
(the org-initiated direction only; `to` is always a `Counsellor`): its
`$respondent->is($requestResponseDTO->user?->counsellor)` check already correctly authorizes
exactly the counsellor the request is addressed to, and its admin/Organization-administered-by
branches correctly reject everyone else -- with no touch to (or relaxation of) 1/5's admin-only
`EnsureUserCanSetOrganizationCounsellorCompensationAction`. Wrote
`RespondToOrganizationCounsellorCompensationRequestAction` with no bespoke authorization check at
all (mirroring `RespondToOrganizationCounsellorRequestAction`'s own shape, which also has none),
and proved it empirically with dedicated tests: an outsider is rejected, the proposing admin
cannot respond to their own proposal, and the addressed counsellor can. All pass without any new
authorization code.

Also verified AC5 (the ticket's requested `OrganizationRequestResource` `for`→`Organization`
fix) was already shipped as part of SCRUM-146 -- no additional change needed, just a regression
test proving it holds through the full respond pipeline too.

One real gap the ticket's scope missed: `Request.data` (as SCRUM-146 wrote it) never recorded
*which specific org admin* proposed the terms -- only the `Organization` (`from`). Accept's
"attribute the compensation row to the original proposer" requirement (AC1) needed that
`User` id, so `ProposeOrganizationCounsellorCompensationChangeAction` (already merged) was
extended to also store `proposedById` in `Request.data`.

**Why**: consistent with this session's standing discipline (the `DeleteCounsellorAction` and
`OrganizationRequestResource` catches earlier this epic) of verifying a planning-time ticket's
stated need against the actual current codebase before building it, rather than assuming a
plan written before implementation started is still accurate once the dependency it builds on
(1/5) actually exists. Building an unneeded authorization action would have been redundant code
duplicating a check the pipeline already performs -- a maintainability cost with no security
benefit.

---

## 2026-08-28 — SCRUM-147 (TT-6.4c, 2/5): PR #85 review findings -- all three fixed

**Reviewer** (Changes Requested) and **security-engineer** (Medium + Low/informational) both
audited PR #85 before merge.

**Fixed: the concurrency gap SCRUM-146's review explicitly assigned to this ticket.**
`EnsureNoPendingOrganizationCounsellorCompensationRequestAction`'s check-then-create was flagged
during SCRUM-146's review as a TOCTOU race deferred specifically to "SCRUM-147 (accept/reject) ...
since that ticket is what will need 'at most one pending request per affiliation' to actually
hold under concurrency once accept exists." This PR's first draft didn't touch it -- caught by
the reviewer re-checking that the deferred item was actually addressed, not just re-reading the
new code in isolation. Fixed by wrapping `OrganizationCounsellorCompensationService::proposeCompensationChange()`'s
check-then-create in a `DB::transaction()` that first does
`OrganizationCounsellor::query()->lockForUpdate()->findOrFail(...)` on the affiliation itself --
this serializes proposal creation per affiliation (a concurrent second attempt blocks on the row
lock until the first transaction commits, by which point its "no pending" check correctly sees
the just-created row). Scoped to only this negotiation's action, not a schema-wide fix, since a
naive table-wide unique constraint on `(for_type, for_id, type, status=pending)` would have been
incorrect for other request types that legitimately allow multiple concurrent pending requests
for the same `for` from different `from` parties (e.g. `groupTherapyMembership`, where many
different clients can have independent pending requests to join the same group). As both
reviewers separately noted, this fix's correctness rests on documented InnoDB `SELECT ... FOR
UPDATE` behavior and cannot be exercised by an automated test, since the suite runs against
sqlite `:memory:` (`phpunit.xml`), which has no real concurrent-transaction semantics -- a
pre-existing, shared limitation across every `lockForUpdate()`-based action in this codebase, not
unique to this fix.

**Fixed: silent degradation when the original proposer's account no longer exists.**
`RespondToOrganizationCounsellorCompensationRequestAction` read `Request.data['proposedById']`
via `User::find()` and passed the (possibly null) result straight into
`CreateOrganizationCounsellorCompensationAction`, which unconditionally reads `$dto->user->id` --
either silently writing `set_by_id = NULL` or (as security-engineer verified by reproducing it)
crashing with a generic, uncoded `ErrorException` that maps to an opaque 500, permanently blocking
that counsellor from ever accepting the proposal. Fixed with an explicit guard: accept now throws
a clean `OrganizationException` if the proposer can't be resolved, leaving the request `pending`
(so it can still be rejected, or resolved once the situation is understood) instead of degrading
invisibly. Reject was deliberately left untouched -- it must succeed even with no resolvable
proposer, since a decline is not an accountability-sensitive write.

**Fixed (reviewer's suggested improvement, treated as required given the ticket's own
fairness/accountability framing): no affiliation-eligibility recheck on accept.**
`RespondToOrganizationCounsellorRequestAction` (SCRUM-121, the sibling this ticket's action
mirrors) explicitly rechecks the organization's eligibility at accept time, since it can change
between propose and respond. This ticket's action had no analogous check -- accepting a stale
proposal against an affiliation that has since moved to `ended` would silently resurrect
compensation terms for a terminated relationship. Fixed by rejecting (with the same clean
`OrganizationException` pattern) an accept attempt against an `ended` affiliation; reject remains
unaffected, matching the "reject must never be blocked" principle applied to the proposer-missing
case above.

Added 4 new regression tests covering all three fixes (proposer-gone on accept vs. reject,
ended-affiliation on accept vs. reject) plus verified the existing sequential "second proposal
rejected" test still passes under the new transaction wrapper. Full suite: 578 passed (up from
574). Pint clean.

**Why**: CLAUDE.md requires never silently dropping a reviewer/security finding, and doubly so
here since one finding was a previously-deferred item this exact ticket was assigned to address
-- silently not doing it would have repeated, not resolved, the SCRUM-146 review's own reasoning.
All three fixes are narrowly scoped to the actual failure mode found (no schema change, no new
abstraction, no speculative validation added beyond what was demonstrated to be reachable).

---

## 2026-08-28 — SCRUM-149 (TT-6.4c, 4/5): built independently of the still-unmerged SCRUM-148, plus two implementation-level design calls

**Branching decision**: SCRUM-149's own ticket text states "Does not depend on TT-6.4c (3/5) --
expiry applies identically regardless of round/counter-offer state," and depends only on 1/5 and
2/5, both already merged. Confirmed no file-level collision risk either (149 touches
`AppService`/`routes/console.php`/two new notifications; 148's only new/changed files are its own
counter-offer action, service method, and its own notification's generalization -- no overlap).
Per CLAUDE.md's "work that's genuinely independent of the unmerged PR can still proceed without
waiting" -- branched off fresh `develop` (which has 1/5+2/5 but not yet 3/5) rather than waiting
for PR #86 to merge.

**Design decision 1**: the ticket flagged needing "a lightweight, non-enum signal" so 5/5 can
later distinguish a manually-rejected request from an auto-expired one, listing two candidate
mechanisms and asking to "confirm the exact mechanism with a quick architect check before building
5/5 against it." Rather than defer this choice, picked the explicit `data['resolvedBy'] = 'expiry'`
marker (set only by `expireStaleCompensationRequests()`, never by manual accept/reject) over
comparing the resolution timestamp against `expires_at` -- the latter is fragile (clock skew, or a
manual reject landing coincidentally close to the boundary), while an explicit marker is
unambiguous and trivial for 5/5 to read. This is a small, reversible, implementation-level choice
well within ordinary engineering judgment, not a product/scope decision -- made directly rather
than pausing the chain for a synchronous architect round-trip.

**Design decision 2**: reminder "exactly once" is enforced with a new `reminder_sent_at` timestamp
column + `whereNull()` guard, not by narrowing the daily sweep's time window alone. A pure
day-arithmetic window (e.g. `expires_at BETWEEN now()+2days AND now()+3days`) would double-send if
the scheduled job ever ran twice in a day, and silently skip a request entirely if the job missed
a day -- a persistent flag is exactly-once regardless of how reliably the cron actually fires.

**Also duplicated (not extracted)**: `AppService::notifyCompensationRequestRecipient()` -- "if `to`
is an Organization, notify every admin; otherwise notify `to` directly" -- is a near-copy of
`CounterOfferOrganizationCounsellorCompensationChangeAction::notifyNewRecipient()` from the
still-unmerged SCRUM-148. Per this session's own reviewer guidance on SCRUM-146's PR ("a third
copy would be a good trigger to extract a shared helper"), this is only the second occurrence, so
duplicated rather than speculatively extracted across two branches that haven't even merged yet in
either order. Worth consolidating (e.g. into a small shared action) if a third need for this same
direction-aware-notify logic arises, most plausibly in SCRUM-150.

**Why**: consistent with this session's standing practice of proceeding on genuinely independent
work rather than serializing everything through a single PR queue, and of making small, reversible
implementation-level judgment calls directly (logged here) rather than treating every open question
in a ticket's text as a hard blocker requiring a synchronous check-in.
## 2026-08-28 — SCRUM-148 (TT-6.4c, 3/5): counter-offer's reverse direction addresses the whole Organization, not a specific admin

**Decision**: the ticket text didn't specify exactly what a counsellor's counter-offer's `to`
should resolve to once direction flips. Rather than address it back to the specific admin who
made the original proposal (`proposedById`), it's addressed to the `Organization` itself --
mirroring how the org's own turn already works in SCRUM-146 (`from` = `Organization`, never a
specific admin). This means any admin of the organization can respond to a counter-offer, not
only whichever admin happened to propose the previous round -- more realistic for an org with
multiple admins, and it lets `EnsureUserCanRespondToRequestAction`'s existing
`Organization`-administered-by branch (already in the codebase for other org-context request
types, unused until now by this feature) authorize the org's turn with zero new authorization
code, continuing the pattern from SCRUM-147's finding that a bespoke authorization action wasn't
needed.

Since `Organization` isn't `Notifiable`, a counter-offer addressed to it notifies every admin
individually (`Notification::send($organization->admins, ...)`) rather than a single party.

**Also**: rather than write a second near-duplicate notification class for the counter-offer
recipient (the ticket's own AC2 says "same notification shape as 1/5's proposal-created
notification"), `OrganizationCounsellorCompensationChangeProposedNotification` was generalized in
place -- its mail copy no longer assumes the organization is always the one proposing, and it now
resolves a display name correctly for either a `Counsellor` or a `User` (org admin) notifiable.
Reused directly for both the original propose (SCRUM-146) and every counter-offer round.

**Why**: consistent with this epic's standing preference for reusing existing generic
infrastructure (`Request`/`RequestTypeEnum`, `EnsureUserCanRespondToRequestAction`) over building
parallel, type-specific mechanisms -- a counter-offer is fundamentally "another proposal, in the
reverse direction," not a conceptually new capability, so it should look exactly like the
original proposal wherever the two are actually the same operation from the recipient's point of
view.

---

## 2026-08-28 — SCRUM-148 (TT-6.4c, 3/5): PR #86 review findings -- one High fixed, one Low deferred to its own already-planned ticket

**Reviewer** approved with no required changes. **security-engineer** found one High and one Low.

**Fixed: missing request-type guard on the counter-offer path.**
Reusing `EnsureUserCanRespondToRequestAction` for counter-offer authorization (this ticket's own
"no new authorization action needed" finding, mirroring SCRUM-147) came with a gap that finding
didn't surface: that action only checks *who* the `to`-party is, never the request's `type`. For
accept/reject this is safe because `RespondToRequestAction` dispatches strictly by
`$request->type` via an explicit `if` chain -- an unmatched type is a no-op. But
`OrganizationCounsellorCompensationController::counterOffer()`'s route
(`POST /requests/{requestId}/compensation-counter-offer`) has no equivalent per-type dispatch: it
resolves any `Request` by id and hands it straight to `counterOffer()`. Without a type check, a
user legitimately `to` on *any* other pending request (a group-therapy invite, a guardianship
request, an org invite/application, ...) could have it force-rejected and mutated as if it were a
compensation negotiation. In the current codebase this happened to fail safely -- `for` on every
other request type isn't an `OrganizationCounsellor`, so `notifyNewRecipient()`'s
`$organizationCounsellor->organization` access throws, rolling back the whole transaction
(including the wrongful `status = rejected` write) -- but that's an accident of the current model
graph, not a designed safeguard, and would silently become a real cross-feature data-integrity bug
the moment any future change makes that access more defensive or another type ever reuses
`OrganizationCounsellor` as `for`.

Fixed by checking `$dto->request->type !== RequestTypeEnum::organizationCounsellorCompensationChange->value`
in `OrganizationCounsellorCompensationService::counterOffer()`, immediately after the existence
check and before authorization, throwing the exact same `RequestNotFoundException`/message
`EnsureRequestExistsAction` already uses -- a wrong-type request is indistinguishable from a
non-existent one to whoever's asking, consistent with this codebase's established anti-enumeration
pattern (SCRUM-120's "same generic message either way"). Added a regression test constructing an
unrelated pending request type addressed to the same user and confirming it's rejected and left
completely untouched.

**Deferred (not a new gap -- this is precisely what the next sub-ticket already exists to do):**
`expires_at`/`expiryDays` is stored on every round but nothing anywhere (accept, reject, or this
ticket's counter-offer) actually checks it against `now()`, and no scheduled job auto-expires
stale pending negotiations. This isn't a SCRUM-148-introduced gap -- SCRUM-149 ("TT-6.4c 4/5:
Compensation-change reminder + expiry") is specifically the ticket already filed to build exactly
this enforcement. No new follow-up ticket needed; noting it here only so it isn't mistaken for an
oversight in this PR.

Also applied reviewer's two minor, non-blocking suggestions: type-hinted
`notifyNewRecipient()`'s second parameter as `OrganizationCounsellor`, and added a test covering
`expiryDays` override on the counter-offer path (previously only tested on propose).

**Why**: CLAUDE.md requires never silently dropping a reviewer/security finding. The High finding
was a genuine authorization/type-integrity gap masked by incidental failure behavior, not an
intentional safeguard -- fixed immediately rather than relying on that accident to keep holding.
The Low finding is explicitly, already the scope of a specific upcoming ticket in this same
5-part sequence, so re-filing it as a new follow-up would just create a duplicate to reconcile
later.

---

## 2026-08-28 — SCRUM-149 (TT-6.4c, 4/5): post-merge security findings fixed via a follow-up PR

PR #87 was merged before its `reviewer`/`security-engineer` passes finished (a user "merged both"
instruction landed while they were still running against the already-open PRs). `reviewer`
approved with no required changes. `security-engineer` found two **High** issues on the
now-merged code -- both fixed immediately in a new PR (`bugfix/scrum-149-reminder-expiry-race-safety`)
rather than left outstanding, since merged status doesn't change CLAUDE.md's "never silently drop
a finding" rule, it only changes the mechanism (a follow-up PR/commit instead of amending the
original one).

**Fixed: TOCTOU race between the sweep's batch SELECT and its per-row UPDATE.** Unlike
`RespondToOrganizationCounsellorCompensationRequestAction`/`CounterOfferOrganizationCounsellorCompensationChangeAction`
(both lock-then-recheck-then-write), `sendCompensationRequestExpiryReminders()`/
`expireStaleCompensationRequests()` fetched a batch of pending requests, then unconditionally
`update()`d each one -- a concurrent accept/reject/counter-offer landing in that window could be
silently clobbered back to `rejected` with a false `resolvedBy: 'expiry'` audit trail, even though
compensation terms had already been legitimately accepted. Fixed by wrapping each row's actual
write in `DB::transaction(fn () => Request::query()->lockForUpdate()->findOrFail(...))` followed
by a fresh `status !== pending` recheck, mirroring the sibling actions exactly. Same acknowledged
limitation as those siblings: the fix's correctness rests on documented MySQL InnoDB `SELECT ...
FOR UPDATE` behavior and isn't directly exercisable by an automated test against sqlite -- verified
instead via an idempotency test (running either sweep twice back to back only resolves/notifies
once).

**Fixed: an unresolvable recipient (or an admin-less organization) could abort the entire daily
sweep for every other pending negotiation.** `notifyCompensationRequestRecipient()` called
`$request->to->notify(...)` with no null guard -- if the counterparty had been soft-deleted while
a negotiation was still pending (a real, already-supported lifecycle:
`purgeExpiredSoftDeletedCounsellors()` runs in this exact same file), `to` resolves to `null` and
the resulting fatal error, uncaught, would abort `Collection::each()` partway through, silently
starving every other pending affiliation's reminder/expiry that day -- indefinitely, since nothing
would ever retry it. Fixed two ways: (1) `notifyCompensationRequestRecipient()` now checks for a
null `to` or an empty admins collection and logs a warning instead of throwing, so a request
still correctly resolves even when nobody can be notified; (2) each row's processing is now also
wrapped in its own `try/catch` with `Log::error()`, so even an unanticipated failure mode can
never take down the rest of the batch. Verified with a test asserting an unaffected second
request in the same sweep still resolves correctly when the first one's recipient is gone.

**Not separately fixed (already covered by the above)**: the Medium "empty admins" and Low
"malformed `expires_at`/`created_at`" findings were folded into the same two fixes above (the null
guard covers admin-less orgs too; an explicit `expires_at <= created_at` check was added alongside
the existing window-size guard) rather than treated as separate follow-ups, since they're the same
class of defensive-guard gap. The Info-level "extract a shared notify helper" and "move magic
numbers to config" suggestions remain deliberately unaddressed -- both were explicitly optional
polish, not required changes, in `reviewer`'s own verdict.

**Why**: two High findings on code already live in `develop` are not optional to defer just
because the merge already happened -- the fix mechanism changes (new commit/PR instead of
amending #87), not the obligation to fix. Both fixes are narrowly scoped to the actual failure
modes found, matching this session's established lock-then-recheck and per-row-isolation patterns
already proven out in SCRUM-147/148, rather than introducing new abstractions.

---

## 2026-08-28 — SCRUM-150 (TT-6.4c, 5/5): "resolved" state covers accepted too, not just rejected/expired

**Decision**: the ticket's scope text enumerated four states -- no active proposal, pending
(org's turn), pending (counsellor's turn), and "last negotiation rejected or expired." It didn't
explicitly name a fifth "last negotiation was accepted" case. Rather than treat an accepted
negotiation as unclassifiable or force it into "no active proposal" (technically true --
nothing's pending -- but silently discarding useful context), the resource's `state` field covers
it under the same `'resolved'` bucket as rejected/expired, with `status: 'ACCEPTED'` and no
`resolvedBy` key (that field is only meaningful for `rejected`). This keeps the discriminator
values to exactly `none`/`pending`/`resolved` -- three states, not five -- while still exposing
enough information (`status`) for a consumer to render different copy for each of accepted vs.
rejected vs. expired within the `resolved` bucket.

**Also**: `getNegotiationState()` was written as a second, wholly separate query method
alongside `getCompensations()` rather than folding negotiation-state data into that existing
method's response shape, per the ticket's own explicit AC4 constraint ("the existing SCRUM-123
accepted-terms history endpoint is unmodified") -- verified with a dedicated test that a pending
negotiation never appears in `getCompensations()`'s results, not just asserted by code structure.

**Why**: a five-way discriminator (none/pendingOurs/pendingTheirs/rejected/expired) would have
duplicated information already available via `from`/`to` (direction) and `resolvedBy`
(reject-vs-expiry) onto the top-level `state` field itself -- redundant surface area for the same
underlying facts. Three states plus two orthogonal detail fields is simpler and covers every
case the ticket actually described, including the one it didn't name.

---

## 2026-08-28 — SCRUM-150 (TT-6.4c, 5/5): PR #89 review findings -- a real correctness bug, a security fix, and a duplication extraction

`reviewer` requested changes (blocked on a functional bug, verified by reproduction against the
real app). `security-engineer` found one Medium finding. All fixed before merge.

**Fixed: `getNegotiationState()`'s ordering broke across multiple negotiation chains.** The
original query ordered `orderByDesc('round')->orderByDesc('id')`. `round` only increases *within
one chain* -- `ProposeOrganizationCounsellorCompensationChangeAction` always starts a fresh chain
at `round: 1`, and nothing blocks a new proposal once the prior chain has resolved (only a
*pending* duplicate is blocked). Reviewer reproduced the bug directly: propose → counter (round 2)
→ reject (chain 1 ends at round 2) → propose again (chain 2, round 1, pending) -- the query
returned chain 1's resolved round-2 request instead of chain 2's actual pending round-1 request,
directly undermining the ticket's entire purpose (reporting the *current* state). Fixed by
ordering on `id` alone -- a strictly increasing PK regardless of chain/round, so it's the correct
"most recent" ordering unconditionally. Added a dedicated regression test reproducing the exact
reviewer-found scenario, plus strengthened an existing test to actually re-call
`getNegotiationState()` after a fresh proposal rather than only asserting creation didn't throw.

**Fixed: `proposedTerms` spread the raw `Request.data` payload, including `proposedById`** (an
internal `User.id`, added by the propose/counter-offer actions purely for `set_by_id` attribution
on accept -- never meant for display) straight to the other negotiating party. Fixed with an
explicit field whitelist (`type`/`amount`/`currency`/`percentage`/`basis`) instead of spreading
`$this->data` wholesale.

**Correction, made during re-review of this same fix**: this entry originally claimed the
identical pattern in `OrganizationRequestResource` was "not currently exploitable... only ever
returned to the actor who just performed a write, never to the counterparty," and deferred it to
SCRUM-152 on that basis. That claim was wrong -- both `reviewer` and `security-engineer`
independently traced and empirically confirmed that `OrganizationRequestResource` is *also*
returned via `RequestService::respondToRequest()`'s accept/reject path, which is answered by
`$request->to` (the counterparty), not the proposer. An existing test already exercised this exact
call chain without ever asserting on `proposedTerms`, so the leak passed silently. This was a
live leak in already-merged code, not a dormant pattern safe to schedule as routine backlog.
Fixed immediately in the same commit rather than left deferred, applying the identical whitelist,
with a new regression test exercised through `respondToRequest()` specifically (not just direct
resource instantiation) so the actual leaking call path is what's under test.

**Fixed (required, not just suggested): duplicated `partyResource()` type-switch.** The new
resource copied `OrganizationRequestResource::partyResource()`'s Organization/Counsellor/User
branching verbatim. Extracted both into a shared `ResolvesOrganizationOrCounsellorParty` trait
rather than leaving a second copy -- reviewer treated this as a required change, not the
optional "wait for a third occurrence" judgment call used elsewhere this session, since the
duplication was introduced within the same PR being reviewed, not accumulated gradually across
already-merged tickets.

**Also fixed**: an inverted doc-comment on `getNegotiationState()` that claimed it "only ever
reads organization_counsellor_compensations, never requests" -- backwards; corrected to describe
what the method actually queries. Eager-loaded `from`/`to` to match `getCompensations()`'s
existing `->with('setBy')` convention.

**Why**: the round-ordering bug is a genuine functional defect in the ticket's core deliverable,
not a style nit -- fixed immediately per CLAUDE.md's "never silently ignore a reviewer finding."
The `proposedById` leak is a real trust-boundary crossing (an internal identifier reaching the
other negotiating party), and once shown to be live rather than latent, fixing it immediately
rather than leaving a known, already-exploitable leak sitting in the backlog was the only
defensible call -- consistent with this session's standing discipline of re-verifying a claim
(including one made minutes earlier in this same log) before relying on it, rather than assuming
a written rationale is correct just because it was already recorded.

---

## 2026-08-29 — SCRUM-47 (TT-7.2): re-scoped from a 3-point stub to an 18-point, 3-sub-ticket feature

**Decision**: TT-7.2 ("Counsellor sets and displays preferred pricing on profile") was carried in
`documentation/implementation_plan.md` as a 3-point stub since the original SCRUM-111-era backlog
review. Product-owner research (grounded in the actual codebase, not the stub's wording) found
that no counsellor-pricing concept exists anywhere today -- all pricing currently lives on
`Therapy`/`GroupTherapy.payment_data`, filled in by the client at booking time, not the
counsellor -- and that `Therapy`/`GroupTherapy.currency` is unconstrained free-text everywhere,
a gap `documentation/implementation_plan.md`'s TT-7.4 row already flagged but hadn't built.

Four user decisions (informational/display-only pricing with zero coupling to
`CreateTherapyRequest`/charge logic; flat-OR-per-service-type pricing, counsellor's choice, not
one forced shape; explicitly no link to `OrganizationCounsellorCompensationBasisEnum::COUNSELLOR_RATE`;
and a platform-wide, config-driven supported-currency list applied everywhere currency appears,
not just the new field) turned this from a single field into a real three-part feature. Split into
TT-7.2a (currency foundation, absorbing TT-7.4's currency-validation item, 5 points) →
TT-7.2b (pricing data model + API, 8 points) → TT-7.2c (pricing UI, 5 points), mirroring the
TT-6.4a/b/c sub-ticket-split precedent. TT-7.4's own estimate was revised 8 → 5 points to remove
the now-relocated currency-validation scope. `documentation/implementation_plan.md` updated
accordingly (TT-7.1 also given its overdue ✅ marker -- SCRUM-110 is Done in Jira but had no
marker in the doc).

Architect review additionally settled the TT-7.2b schema before implementation: a single
`counsellor_pricings` table with nullable `therapy_type`/`session_type`/`per` scope columns
(all-null = flat rate; all-three-non-null = a fully-specified override row), written via a
full delete-and-reinsert transaction per save rather than incremental upsert (avoids needing
DB-level uniqueness tricks for a single-writer, low-contention field), with no versioning/history
table -- unlike `organization_counsellor_compensations`'s effective-dated design, there is no
negotiation or accountability trail to reproduce here, since the counsellor unilaterally sets
their own non-binding, informational number. A new `TherapyTypeEnum` (individual/group) is needed
since no existing shared enum covers that distinction (it's expressed today only by `Therapy` and
`GroupTherapy` being separate Eloquent models).

**Why**: literal compliance with a years-old backlog stub's point estimate would have meant either
building a materially incomplete feature (a single flat-rate field, contradicting the user's
explicit flat-or-per-service decision) or silently absorbing 15 extra points of scope into a
ticket sized for 3 -- both worse than re-scoping and re-filing sub-tickets transparently, matching
this project's own established practice (TT-6.3, TT-6.4, TT-7.3 were all split the same way once
their real scope became clear during planning).

---

## 2026-08-29 — SCRUM-153 (TT-7.2a): PR #90 review fixes -- default-currency bug, normalization, legacy-value handling

**Decision**: PR #90's own reviewer and security-engineer independently found the same
HIGH/blocking issue: `IndividualTherapyFormModal.vue`, `UpdateIndividualTherapyFormModal.vue`,
`GroupTherapyFormModal.vue`, and `UpdateGroupTherapyFormModal.vue` all defaulted/reset `currency`
to `'GHȻ'` (the Cedi *symbol*, not the ISO code `'GHS'`) via a free-text `TextInput` -- a value
that would fail the PR's own new `Rule::in(config('currencies.supported'))` validation unless a
user manually retyped the field, breaking paid-therapy creation by default. Fixed by sharing
`config('currencies.supported')` to the frontend as an Inertia prop (both the dead
`HandleInertiaRequests.php` and the actually-registered `HandleInertiaRequestsV2.php`, for
consistency) and replacing the free-text input with a `<Select>` in all four modals, deliberately
deviating from this codebase's usual `useEnums.js` hardcoded-JS-mirror convention for enums --
a hardcoded mirror would defeat the user's original "configurable, applied everywhere" requirement
for currency, since an env change wouldn't reach the frontend without a code change.

Also fixed the same round's Medium/Low findings: `config/currencies.php` now normalizes every
entry to uppercase at the source (`strtoupper(trim(...))`) so `Rule::in()` and
`EnsureCanInitiateChargeAction`'s stored-value check compare on the same casing without each
needing its own normalization step; `env('SUPPORTED_CURRENCIES') ?: 'USD,GHS'` replaces the
two-arg `env()` form to avoid silently accepting an empty-string override; `array_filter` after
the trim/uppercase map drops empty entries (a stray comma or whitespace-only segment) so `Rule::in()`
can never treat `''` as a valid currency; `SUPPORTED_CURRENCIES` is now documented in `.env.example`.

A second review pass on the fix itself (both reviewer and security-engineer, run again given the
frontend scope) confirmed all three original findings resolved and no new bugs, but the reviewer
surfaced a real, previously-unasked-about gap: the two Update modals load
`props.therapy.paymentData['currency']` verbatim into the form, but the new `<Select>` can only
render options from `supportedCurrencies` -- a therapy whose stored currency predates the current
supported list (plausible, since `'GHȻ'` was this exact field's own hardcoded default for a long
time) would silently desync the dropdown from the form value, surfacing later as a confusing
"currency" validation error on an unrelated field update. Fixed by making each Update modal's
`currencyOptions` reactively include the form's current `currency` value when it falls outside
`supportedCurrencies`, so a legacy/out-of-list value stays visibly selected and editable instead
of disappearing. Also added a regression test (`TransactionServiceTest`) pinning the assumption
`EnsureCanInitiateChargeAction`'s comment documents -- that it only needs to normalize the stored
value because `config('currencies.supported')` is already uppercase -- since both reviewers
independently flagged this as an untested cross-file invariant.

**Deferred, not applied**: reviewer's suggestion to add a dedicated unit test exercising
`config/currencies.php`'s raw env-parsing behavior (uppercase normalization and the empty-string
fallback, as opposed to `Rule::in()` against an already-normalized config array) was left for a
follow-up, since reliably testing `env()`-sourced config in this codebase's test setup needs more
plumbing than this fix round's scope justified; the `array_filter` correctness fix itself was
still applied immediately since it was cheap and directly on the touched line. Also deferred:
reviewer's minor note that `currencyOptions` in the two *Create* modals doesn't need `computed()`
since `supportedCurrencies` is captured once and never changes there (unlike the Update modals,
where it's now genuinely reactive against `therapyForm.currency`) -- correct but non-blocking.

**Why**: CLAUDE.md requires applying or explicitly deferring every reviewer/security-engineer
finding, never silently dropping one. The default-currency bug and its Medium/Low siblings were
correctness/robustness fixes with no product-decision content, so they were applied without
asking. The Update-modal legacy-value gap was the reviewer's own explicit "Changes requested"
blocker on a scenario the review was asked to check, so it was fixed in the same pass rather than
deferred. The two genuinely low-priority suggestions were deferred with a stated reason rather
than either silently skipped or force-fit into this round.

---

## 2026-08-29 — SCRUM-154 (TT-7.2b): counsellor pricing implemented per architect-settled schema

**Decision**: Implemented the `counsellor_pricings` data model + API exactly per the schema the
architect settled during SCRUM-47's planning (see this file's 2026-08-29 "SCRUM-47 (TT-7.2)"
entry) — nullable `therapy_type`/`session_type`/`per` scope columns, full delete-and-reinsert per
save, no versioning table, `EnsureCounsellorPricingDataIsValidAction`/`EnsureUserCanSetCounsellorPricingAction`
mirroring `EnsureOrganizationCounsellorCompensationDataIsValidAction`/
`EnsureUserCanSetOrganizationMemberBillingConfigAction`'s existing style, `GetPayableAmountAction`
guardrail comment, no link to `COUNSELLOR_RATE`. One implementation-time addition beyond the
ticket's literal text: `EnsureCounsellorPricingDataIsValidAction` also rejects two override rows
covering the identical `(therapy_type, session_type, per)` combination — the ticket said each
override must be "fully specified," which rules out partial rows but doesn't by itself rule out
two fully-specified rows disagreeing on the price for the same exact scope. Rejecting duplicates
outright avoids an ambiguous "which one wins" question with no clear answer (insertion order isn't
a meaningful business rule to build on).

`EnsureCounsellorExistsAction`'s accepted DTO union type was widened to include
`CounsellorPricingDTO`, reusing the existing shared counsellor-existence check rather than writing
a near-duplicate one, consistent with how that action already serves
`UpdateCounsellorDTO`/`DeleteCounsellorDTO`/`VerifyCounsellorDTO`/`OrganizationCounsellorRequestDTO`.

**Why**: the duplicate-scope rejection is a defensive data-integrity rule filling a gap the ticket
didn't explicitly address, not a product decision requiring sign-off — rejecting an ambiguous input
outright is the conservative default, and the alternative (silently picking last-inserted-wins) has
no stated rationale to justify over rejecting. Everything else in this ticket was already fully
specified by the prior architect pass, so no new judgment calls were needed for the core schema or
authorization design.
## 2026-08-29 — SCRUM-48 (TT-7.3a): org-as-payer charge initiation, scope finalized

**Decision**: SCRUM-48 was carried in Jira as an 8-point "TT-7.3: Org subscription billing" stub,
but `documentation/implementation_plan.md` already had it pre-split (during earlier TT-7 planning)
into TT-7.3a (org-as-payer charge initiation only, 5 points) and TT-7.3b (payout/refund lifecycle,
deferred — depends on TT-7.6/TT-7.7, neither filed yet). This entry finalizes TT-7.3a's scope
through a full product-owner → project-manager → architect pass before implementation.

Key design decisions: (1) `organization_id` is an explicit, always-required input when charging
"via org" — never inferred from a single-org membership, even when a member belongs to only one
org. (2) Which org financed a charge is recorded on a new `transactions.organization_id` FK
(nullable, `nullOnDelete` since `Organization` is soft-deletable but a `Transaction` must remain a
permanent record) — not on `Therapy`/`GroupTherapy`/`Session`, since a `PER_SESSION`-billed therapy
has many charge events whose org attribution can differ per session, and a member later leaving an
org must not retroactively rewrite past attribution. (3) Error messaging splits into one generic
anti-enumeration message for "not eligible via this org" (no shared org / counsellor not covered /
org unverified — mirrors `EnsureCanPayForModelAction`/`EnsureOrganizationCanReceiveMemberApplicationsAction`'s
existing convention) versus specific messages for retainer-mode and group-therapy-exclusion
rejections, on the theory that those two are only reachable once the requester has already proven
active membership and full counsellor coverage, so they add no new disclosure beyond confirming the
requester's own billing configuration -- the counsellor-coverage fact itself can't be probed at
will, since a counsellor only ever ends up attached to a Therapy/GroupTherapy by actually accepting
a real assistance/membership request, not via any client-settable input (security-engineer review,
2026-08-29). (4) The new `EnsureOrganizationCanPayForModelAction` slots in as
a 4th step in `TransactionService::initiateCharge()`, after the existing `EnsureCanInitiateChargeAction`
(cheaper/existing checks fail first) and before `InitiatePaystackChargeAction`, with a single
top-of-method `if (is_null($dto->organizationId)) return;` guard making the personal-pay path a
structural no-op, not just a tested one.

**Multi-counsellor GroupTherapy question** (the one open item product-owner couldn't resolve
unilaterally): for a `GroupTherapy` with several counsellors, does the shared org need to cover
*every* currently-active counsellor, or is *any one* sufficient? User decided **every active
counsellor** — the stricter option, matching product-owner's own non-binding recommendation, to
avoid a bookkeeping gap where TT-7.3b's future payout logic would have no compensation relationship
to reference for a counsellor the org never actually covered. Architect confirmed this was a safe
question to leave open through the design pass: the counsellor-resolution step (new
`GroupTherapy::activeCounsellors()`, feeding a single set-based `whereIn`/`whereHas`-or-`whereDoesntHave`
query, mirroring `EnsureThereIsNoPendingRequestForCounsellorsAction`'s existing idiom) makes "every"
vs. "any one" a one-line query-polarity change, not a structural rewrite either way.

**Also confirmed, not a new coupling**: TT-6.4c's negotiation flow (accept/reject/counter-offer on
`organization_counsellor_compensations`) does not change what `organization_counsellors.status`
being `active` means for this ticket — `CreateOrganizationCounsellorCompensationAction` activates
the affiliation on the *first* compensation row regardless of whether that row came from the
original unilateral proposal or the result of a later negotiation round, so `isActive()` remains a
stable, negotiation-history-independent check.

**Why**: this is a brand-new feature slice (org-as-payer is new charge-initiation behavior, not a
bugfix), so it required the full `/start-feature`-style gate per CLAUDE.md even though its
dependencies (TT-6.3b, TT-7.1) were already merged and its estimate was already provisionally
sized during earlier TT-7 planning. The multi-counsellor question was genuinely undecidable by the
product-owner subagent alone (a real product trade-off between strictness and simplicity, not a
technical question with one correct answer), so it was surfaced to the user rather than guessed —
consistent with this session's standing practice of pausing only for genuinely ambiguous product
decisions, not technical ones the architecture review could settle on its own.

**Implementation-time deviation from the architect's design**: the architect recommended resolving
`organizationId` from a route parameter only (mirroring `getFor()`'s existing route-param-only
resolution, which guards against a request body overriding which resource is being charged).
During implementation this was changed to accept `organizationId` from the request body instead,
since no route currently carries an `organizationId` segment and adding one would mean doubling
every charge-initiation route (with/without org) for no real security benefit: `getFor()`'s
concern is spoofing *what* is being charged, but `organizationId` doesn't identify the charge
target (that's still `for`, unchanged) — it's an additional payer credential that
`EnsureOrganizationCanPayForModelAction` independently and fully re-verifies regardless of where
the value came from. Also added a raw `TransactionDTO::$organizationId` field alongside the
resolved `$organization`, which the architect's design didn't explicitly call for: this is what
lets the gate action distinguish "no organizationId supplied" (personal-pay, skip entirely) from
"an organizationId was supplied but doesn't resolve to a real org" (reject with the same generic
anti-enumeration message as every other ineligibility reason) — collapsing those two cases, as a
single resolved-model field would, would have silently downgraded an invalid/foreign
`organizationId` to a personal charge instead of rejecting it, which is both surprising (a user
who explicitly asked to pay via their org would be charged personally without warning) and
inconsistent with the architect's own stated anti-enumeration intent for that exact scenario.

**Post-implementation review fixes**: `reviewer` and `security-engineer` both ran against the
implementation before merge. Two required changes from `reviewer`, applied immediately: (1)
`EnsureOrganizationCanPayForModelAction`'s generic-message cluster now also re-checks
`Organization::is_consumer`, not just `isVerified()` — an org can have `is_consumer` toggled off
after members already exist (`UpdateOrganizationAction` doesn't retroactively remove them), and the
action's own comment claims to mirror `EnsureOrganizationCanReceiveMemberApplicationsAction`'s
convention, which does check it. (2) `GroupTherapy::activeCounsellors()` now excludes a
soft-deleted `addedby` counsellor from the coverage set — without this, a GroupTherapy created by a
counsellor who has since deleted their account could never be paid for via any organization, since
a deleted counsellor can never again satisfy an active `organization_counsellors` row, and the
"every active counsellor must be covered" rule would treat that permanently-uncoverable ghost
counsellor as blocking every future org-pay attempt on that GroupTherapy. Both fixes came with new
regression tests. `security-engineer`'s one finding (Low): `organizationId` had no input-shape
validation before reaching `Organization::find()`, so an array value (`organizationId[]=1`) tripped
an uncaught `TypeError` on the DTO's typed `?Organization` property, degrading to a generic 500 --
no information disclosure, but inconsistent with the codebase's explicit-validation convention.
Fixed with an upfront `is_numeric()` guard in `TransactionController::initiate()` throwing a clean
422 `TransactionException` instead.

**Why**: both required changes were genuine functional gaps in the ticket's stated design (the
`is_consumer` check the code's own comment claimed to already follow; the multi-counsellor coverage
rule the human product owner explicitly chose), not style nits, so fixed immediately per CLAUDE.md.
The validation gap was a robustness/UX issue, not a security hole (the existing
`ResolvesExceptionResponse` safety net already prevented any leak), but cheap enough to fix in the
same pass rather than deferred.

---

## 2026-08-29 — SCRUM-155 (TT-7.2c): pricing UI, resolving an AC-vs-schema conflict, and a discovered backend gap

**Decision**: Built the counsellor-facing pricing edit form and public display per SCRUM-155's
scope, following the established `Show.vue` section pattern (edit button → modal, mirroring
`UpdateCounsellorContact.vue`/`UpdateCounsellorPreferences.vue` exactly). Two judgment calls made
without asking, both logged here:

1. **AC #1 vs. the merged schema, resolved in favor of the schema.** SCRUM-155's AC #1 describes
   the flat rate as "amount + currency + `per`", but the schema section of the same ticket family
   (and the actually-implemented, reviewed, merged `EnsureCounsellorPricingDataIsValidAction`)
   defines flat mode as `therapy_type`/`session_type`/`per` **all null** — no `per` on a flat row
   at all. Since SCRUM-154 is already merged and its schema can't be casually changed without a
   migration + re-review, the UI was built to match what's actually running (flat = amount +
   currency only), treating the older AC wording as stale planning text rather than a live
   requirement.
2. **Backend gap discovered mid-build**: `SetCounsellorPricingAction` always requires at least one
   pricing entry, so there was no way to represent "no pricing listed" once a counsellor had ever
   set one — but this ticket's own AC #6 explicitly requires a Playwright-verified "clear pricing"
   step. Added a small, additive `DELETE /counsellor/{counsellorId}/pricings` endpoint
   (`ClearCounsellorPricingAction`, `CounsellorPricingService::clearPricing()`), reusing the same
   `EnsureUserCanSetCounsellorPricingAction` authorization check rather than inventing a new one.

**Why**: (1) is a documentation-accuracy call, not a product decision — the human product owner's
actual, confirmed decision (full specification, no partial/cascading overrides, recorded in the
SCRUM-47 entry above) is unambiguous about override rows; the flat-row wording conflict is an
artifact of AC text not being updated after the schema was finalized during SCRUM-47's planning,
not a new requirement to litigate. (2) is a scope gap the UI ticket's own acceptance criteria
surfaced, not an invented feature — AC #6 cannot be satisfied without it, so it was treated as
part of delivering this ticket rather than a separate backend follow-up ticket, since it's a
minimal, low-risk addition to an already-reviewed, merged feature (same auth rule, same model,
no schema change).

**Post-implementation review fixes**: `reviewer` requested 4 changes, all applied. (1) Added a
Feature-level HTTP test for the new `destroy()` endpoint — the Unit tests only exercised
`CounsellorPricingService::clearPricing()` directly, mirroring the exact gap
`tests/Feature/SetCounsellorPricingTest.php` was originally written to close for `store()`. (2)
Wired `onError` on both `savePricing()`/`clearPricing()` to surface `errors.alert` via
`setAlertData`, matching this codebase's established convention (`CreateSessionFormModal.vue` and
others) for Action-thrown exceptions that arrive via `Redirect::back()->withErrors(['alert' =>
...])` rather than a field-named key — previously, a duplicate-override-scope rejection (or any
other server-side validation failure) failed completely silently from the user's perspective. (3)
Fixed `canSave`'s amount check from a bare truthiness test (which is `true` for the strings `"0"`
and `"-5"`, since any non-empty string is JS-truthy) to an `isValidAmount()` helper matching the
server's `integer, min:1` rule — the old check let a client submit `0`/negative amounts that the
server would reject, but with no error ever rendering (the `InputError` was bound to the
`pricings` key, which a wildcard `pricings.*.amount` validation failure never populates). Verified
by browser: typing `0` now correctly keeps "save pricing" disabled. (4) Added a `MiniModal`
"Are you sure...?" confirmation step before "clear pricing" actually fires the delete, matching
every other destructive action in this codebase (`CommentBadge.vue`, this same `Show.vue`'s own
"delete account" flow, etc.) — there is deliberately no versioning/history table for pricing data
(per the SCRUM-154 entry above), so a stray click previously had no undo path at all. Also applied
the two suggested improvements: a client-side duplicate-override-scope check (belt-and-suspenders
ahead of the server rejection) and `min="1"` on the amount inputs.

**Why**: all four were genuine, reachable failure modes in ordinary use (a duplicate override
scope, typing `0`, an accidental click on a destructive action sitting next to the primary save
button), not style nits, and directly contradicted established conventions this same codebase
already uses elsewhere for the identical situations — fixed immediately per CLAUDE.md rather than
deferred.

---

## 2026-08-29 — SCRUM-118: split into TT-7.4a/b/c/d, group-therapy payment model decided

**Decision**: SCRUM-118 ("Payment UI") went through product-owner/project-manager/architect
review before implementation, mirroring the TT-7.2/TT-6.4 precedent, and was found to hide a
backend prerequisite plus a genuine product fork. Split into ordered sub-tickets: TT-7.4a
(SCRUM-156, backend payment-status exposure plumbing — no Resource exposes transaction status
today, and the `transactionStatus` flash value `TransactionController::callback` sets was never
read back down), TT-7.4b (SCRUM-157, client Pay/redirect/status UI, individual therapy & session
only), TT-7.4c (SCRUM-158, counsellor read-only status indicator, parallel with b). TT-7.4d
(group-therapy Pay UI) is deferred as its own future epic, not scheduled or pointed yet — the
user decided group-therapy payment should be **per-member**, not one group-wide charge covering
everyone, which needs a new per-member payment record/schema change beyond what the current
`Transaction` model (a single `morphMany` per model, blocked from a second charge once any one
succeeds) supports. The original TT-7.4's "retry-on-failure" clause is carved out as a separate
small follow-up, since none of SCRUM-118's requirements cover it and it would otherwise silently
vanish in the renumbering.

Architect review additionally required two amendments to the original ticket text before
approval: (1) TT-7.4a's `SessionResource` exposure must go through a `latestTransaction`
(`ofMany`) relation with eager-loading required at every collection call site (e.g.
`SessionService::getSessions()`), not a naive per-row lazy-load — the latter would silently
N+1 the paginated session list; (2) TT-7.4b's pay/redirect/status logic must live in a new
`usePayment()` composable (matching this codebase's existing `useTherapyState`/`useAlert`/
`useErrorHandler` conventions), not embedded directly in `TherapyPaymentDetails.vue` (a
currently pure, stateless display component) with `UnifiedTherapy.vue`'s session-actions modal
reaching into it.

**Why**: the group-therapy payment model is a real, costly-to-guess-wrong fork (a per-member
data model and a group-wide first-payer model are not incrementally related — building the
wrong one means throwing away real work), so it was raised to the user rather than assumed, per
CLAUDE.md's autonomous-execution rules. The two architect-required amendments were both grounded
in code actually read (confirmed no `latestOfMany`/`scopeSuccessful` accessor exists yet on
`Transaction`, and confirmed `TherapyPaymentDetails.vue` has no side-effecting logic today), not
speculative — applying them now avoids a rework cycle once TT-7.4b/c implementation starts.

---

## 2026-08-29 — SCRUM-156 (TT-7.4a): `latestOfMany()` column, and a deferred guest-exposure question

**Decision**: `TherapyTrait::latestTransaction()`/`Session::latestTransaction()` explicitly order
by `latestOfMany('created_at')` rather than the Eloquent default (`'id'`) — reviewer caught that
the default coincides with insertion order in this codebase today (every `Transaction` row is
created once and only ever updated afterwards) but is an implicit assumption, not a guarantee,
and diverges from this codebase's usual `->latest()` (created_at-based) convention elsewhere.
Also added a code comment on both relations clarifying that "latest" means latest across *all*
eligible payers for that model, not scoped to the current viewer — a `GroupTherapy` with
multiple members each attempting to pay can surface one member's pending/failed attempt to a
different member (security-engineer finding); this is a read of the existing single-payer-per-
model backend limitation already tracked as TT-7.4d's blocker, not a new bug, so it's documented
rather than worked around here.

**Deferred, not fixed**: security-engineer also flagged that `paymentStatus` (like the
pre-existing `paymentData`/`paymentType`) is now reachable by an unauthenticated guest on a
`public` therapy/group therapy/session, via `EnsureUserHasAccessToTherapyAction`'s existing
`public`-bypass and `SessionService::getSessions()`'s existing guest branch (SCRUM-74). Rated
Low–Medium: it's an incremental extension of an already-accepted exposure pattern (amount/
currency were already guest-visible; whether a payment succeeded is arguably less sensitive than
the amount), not a new class of leak, and restricting it would be a product-visibility decision
outside SCRUM-156's stated scope (status-exposure plumbing only). Recommend a follow-up ticket to
get an explicit product decision on guest visibility of payment status/data on public
engagements, rather than silently narrowing scope here.

**Why**: both are genuine findings worth recording, but only the first is a correctness bug
inside this ticket's own scope (fixed immediately); the second is a pre-existing product
decision this ticket extends rather than introduces, and CLAUDE.md's autonomous-execution rules
call for logging a scope question like this rather than either silently fixing it (unscoped
change) or silently ignoring it.

---

## 2026-08-29 — SCRUM-157 (TT-7.4b): Pay-button placement, and a pre-existing broken toggle fixed

**Decision**: the client's PER_SESSION "Pay Now" control was placed inside the existing "Session
Actions Modal" in `UnifiedTherapy.vue` (reachable only once a session is the therapy's *active*
session — imminent/ongoing, same rule already used for "start session"/"end session"/"abandon
session") rather than inventing a new, always-visible surface. This means a session created with
a start time far in the future won't show a Pay action until it becomes active, which is a
looser reading of the ticket's "concrete, reachable Pay action after session creation" AC than
"immediately after creation" — treated as acceptable since it mirrors how every other per-session
action in this app already works, and building a second, earlier-reachable surface would be a
larger UI addition than this ticket's scope implies.

While Playwright-verifying this, the "Session Actions Modal" itself turned out to be completely
unreachable through the UI for *any* user, regardless of this ticket's changes: the "show session
information" toggle in `TherapyActiveHeader.vue` only emitted `clicked-show-all` to
`UnifiedTherapy.vue`'s `clickedShowAll()`, which is an empty no-op stub (`// Implementation for
showing all session/discussion info`) — so the local `showAll` ref the component's own template
reads was never actually set, and the double-click target that opens the modal never rendered.
Fixed by also toggling `showAll` locally on click, keeping the (still no-op) emit for
compatibility with whatever it was originally meant for.

**Why**: this is a genuine, pre-existing, unrelated bug (confirmed via git blame: introduced as
dead code in the original page-consolidation commit, not by this ticket), not a regression —
but it directly blocked this ticket's own AC #7 (Pay action must be reachable), so per CLAUDE.md's
"never skip or silently ignore" rule it was fixed immediately rather than deferred or worked
around with a different UI surface. `security-engineer` confirmed the fix only repairs the dead
toggle and doesn't relax any authorization gate (the outer `v-if="activeSession && isParticipant"`
guard, and each modal action's own condition, are all unchanged).

**Also deferred (informational, not fixed)**: `security-engineer` separately flagged that
`DatabaseSeeder::run()` has no environment guard against accidental production seeding, applying
equally to all ~8 demo accounts in the file (pre-existing, not introduced by this ticket) —
logged here as a candidate follow-up chore, not fixed in this ticket's scope.

**Full Paystack sandbox checkout could not be Playwright-verified end-to-end**: this dev
environment has no `PAYSTACK_SECRET_KEY` configured, so the real redirect-out/redirect-back cycle
can't complete. Verified instead via: the Pay button rendering/gating correctly for client vs.
counsellor on both surfaces, the initiate call reaching the real backend and surfacing its actual
502 error distinctly (a genuine `TransactionException`, not a mocked/faked response), and manually
flipping a `Transaction` row to `SUCCESS` in the database to confirm the "Paid" states render
correctly on both surfaces.

---

## 2026-08-29 — SCRUM-158 (TT-7.4c): counsellor status excludes group therapy; TT-7.4 fully implemented

**Decision**: the new counsellor read-only status branches in `TherapyPaymentDetails.vue` and
`UnifiedTherapy.vue`'s Session Actions Modal explicitly exclude group therapy
(`therapyType !== 'group'`), matching TT-7.4b's own scope exclusion, even though the ticket's own
AC text didn't spell this out explicitly (it inherited "same two surfaces TT-7.4b adds the Pay
control to", and TT-7.4b's Pay control is already group-excluded). Displaying a
per-model payment status for a group therapy would be premature and potentially misleading given
TT-7.4d's still-open group-payment-model question (per-member vs. group-wide) — showing "Awaiting
payment"/"Paid" for a group therapy today would imply a single, well-defined payer state that
doesn't actually exist yet for that case.

**Why**: consistent with the same reasoning already logged for TT-7.4b/SCRUM-157 — this is a
scope-narrowing that follows directly from an already-made decision (TT-7.4d deferred), not a new
judgment call, so it's recorded briefly rather than re-litigated. With TT-7.4a/b/c all merged, TT-7.4
(SCRUM-118's payment UI) is now fully implemented for individual therapies and sessions.

---

## 2026-08-29 — SCRUM-164 (TT-6.7): reused the generic Link infrastructure end-to-end; closed
two gaps review found in that reuse before merge

**Decision**: implemented the org self-apply link entirely on top of this codebase's existing
generic `Link` model/flow (already used for guardianship/discussion/therapy-counsellor links) per
the architect's original TT-6.7 call, rather than building bespoke plumbing — a new
`LinkTypeEnum` case, a new `PerformOrganizationSelfApplyLinkAction` branch dispatched from the
existing `PerformLinkAction`, and reusing `OrganizationMemberRequestService::applyAsMember()`
unchanged for the actual eligibility checks (AC2). No new controller, route, or DTO shape beyond
widening `CreateLinkDTO`'s (and, after review, `GetLinksDTO`'s) `$for` union to include
`Organization`.

**Gap found and fixed 1**: the generic `createLink()` flow had no per-type authorization hook at
all (`EnsureAddedbyIsValidAction` only validates the *addedby* identity, never the *for* target) —
without a new guard, any authenticated user could have generated a working self-apply link for an
organization they don't administer. Added `EnsureUserCanCreateOrganizationSelfApplyLinkAction`
(a no-op for every other link type) into `LinkService::createLink()`. Both reviewer and
security-engineer then independently caught that the sibling `createMultipleLinks()` method builds
its own per-item DTO and skipped this same guard entirely — fixed by adding the identical guard
call inside its loop. (Currently unreachable over HTTP due to a pre-existing, unrelated routing
mismatch — `POST /api/links/multiple` maps to `createLink`, not `createMultipleLinks` — but fixed
proactively rather than left as a landmine for whenever that mismatch gets "fixed.")

**Gap found and fixed 2**: security review noted the new guard, run *after* the generic
`EnsureLinkDataIsValidAction`, produced two different status codes for "organization doesn't
exist" (422, from the generic for-is-missing check) vs. "organization exists but you don't
administer it" (403, from the new guard) — a fresh, distinguishable enumeration oracle for
organization IDs that this ticket introduced. Fixed by reordering the new guard to run *before*
`EnsureLinkDataIsValidAction`: since the guard's own `! $dto->for instanceof Organization` check
is true for both a null `for` (nonexistent org) and any non-Organization target, both cases now
collapse into the same 403.

**Why**: both fixes are cheap, newly-introduced-by-this-ticket gaps with no architectural
redesign required — squarely the "apply it" case CLAUDE.md describes, not the "defer with a
follow-up ticket" case (contrast SCRUM-159's Decision 3/SCRUM-170, a pre-existing gap that would
have needed a larger redesign).
## 2026-08-29 — SCRUM-163 (TT-6.6e): owner-only enforcement built as direct actions; org
deletion left ungated since it doesn't exist yet; a TOCTOU gap fixed pre-merge

**Decision 1 (already made, recorded here for the trail)**: real behavioral enforcement of the
existing `organization_admins.role` column — only an owner may remove the org or add/promote/
demote other admins; any-admin access remains for existing profile/invite actions via the
existing `EnsureUserIsOrganizationAdminAction`. Implemented as new direct actions (add/remove/
promote/demote an admin, gated by a new `EnsureUserIsOrganizationOwnerAction`), not the `Request`/
respond negotiation flow used elsewhere in this domain (compensation changes, invites) — the
architect's distinction is that those flows exist because a second party's consent is being
negotiated; an owner managing their own org's admin roster has no such second party.

**Decision 2 — the ticket's AC3 lists "removing the organization" as one of the four owner-gated
actions, but no organization-deletion capability exists anywhere in this codebase** (confirmed via
`grep`: `OrganizationController` only has `store`/`update`/`show`; `Organization` uses
`SoftDeletes` but nothing ever calls `delete()`). Read AC3 as "the gate must apply to org removal
whenever it's built," not "build org removal now" — the ticket's concrete, itemized deliverable
(AC2) only lists add/remove/promote/demote-admin, and inventing a deletion feature (with its own
unspecified business rules — can a verified org with active members/counsellors be deleted? does
it cascade?) would be scope invention beyond what any AC actually describes.

**Decision 3 (fixed before merge, not deferred)**: `EnsureOrganizationRetainsAnOwnerAction`'s
owner-count check and the subsequent pivot write were originally two separate, unlocked steps —
both reviewer and security-engineer independently caught the same real TOCTOU gap: two concurrent
requests demoting/removing two different owners of a 2-owner org could each read owner-count=2
before either write commits, both pass the guard, and leave the organization with zero owners.
Fixed by wrapping the guard+write pair in `DB::transaction()` with
`Organization::query()->lockForUpdate()->find(...)`, mirroring the identical pattern already
established in `OrganizationCounsellorRequestService`/`OrganizationMemberRequestService` for
analogous invariants on the same model.

**Why**: Decision 1/2 are recorded because they're non-trivial judgment calls made without asking
first (an architectural pattern choice, and a scope boundary a literal reading of the ticket text
could dispute). Decision 3 is a required fix, not a deferral — CLAUDE.md requires applying a
reviewer/security-engineer finding, and a real, low-likelihood-but-serious data-integrity risk
(an org with sensitive client data left permanently ownerless) with a cheap, already-precedented
fix is exactly the case for applying it rather than filing a follow-up ticket.
## 2026-08-29 — SCRUM-162 (TT-6.6d): fixed a real PII-enumeration oracle the ticket's own
additive query change reopened in the generic RequestResource

**Decision**: `RequestService::getRequests()` was changed exactly as the ticket specified — one
additive `orWhere` block matching `to`/`from` against any of the user's `administeredOrganizations()`
ids, mirroring the existing `$counsellor` block's shape. Security review then found this newly
surfaces `organizationMemberInvite`/`organizationMemberApplication` request rows to an org admin
through the endpoint's generic `RequestResource`, whose `getFrom()`/`getTo()` fall through to the
full `UserMiniResource` (gender/country/dob) for any plain-`User` party. Since inviting a member
only requires a valid user id (`InviteOrganizationMemberRequest`'s validation is just
`exists:users,id` — no prior relationship required), this reopened the *exact* PII-enumeration
oracle SCRUM-124 already closed for `OrganizationMemberController::invite()`'s own response: create
an org, get it verified, invite arbitrary/sequential user ids, read each target's PII back via
`GET /api/requests`. Fixed by adding a narrow, type-scoped projection
(`isOrgMemberFlowUser()`/`narrowUserProjection()` in `RequestResource.php`) that returns only
`id`/`fullName`/`username` for the User party of these two request types specifically (except a
user viewing their own request) — every other request type's `from`/`to` rendering is unchanged.
Two regression tests added: one pinning the narrowed fields, one confirming an admin of one
organization never sees a different organization's requests (an adjacent invariant the reviewer
flagged as untested, though verified correct).

**Why**: this is a real, newly-introduced privacy exposure on a mental-health platform with a
cheap, narrowly-scoped fix available — CLAUDE.md requires applying a security-engineer finding
like this, not deferring it, when the fix doesn't require redesigning shared architecture (contrast
with SCRUM-159's Decision 3, a pre-existing gap deferred to SCRUM-170 because fixing it properly
would have meant redesigning a guard-action pair used by four endpoints). The narrow, type-scoped
projection (rather than replacing `RequestResource` with `GetRequestResourceAction`'s full per-type
dispatch here) keeps the fix inside this ticket's explicit additive-only scope guard — it touches
only the two newly-surfaced request types' `from`/`to` rendering, not the broader resource-dispatch
refactor the ticket text explicitly said to leave alone.
## 2026-08-29 — SCRUM-159 (TT-6.6a): kept currentCompensation()/currentBillingConfig() as
compatibility wrappers; trimmed member PII; deferred a pre-existing enumeration gap

**Decision 1 — `OrganizationCounsellor::currentCompensation()`/`OrganizationMember::currentBillingConfig()`
converted to `latestOfMany()`-backed relations, but the old method names kept as thin wrappers**
around new `latestCompensation()`/`latestBillingConfig()` relation methods, rather than renaming
every call site to property access. ~8 existing test files and one production call site
(`EnsureOrganizationCanPayForModelAction.php`) all call these as methods expecting a model back;
renaming them all to satisfy the new eager-loadable-relation requirement would have been a much
larger, purely mechanical diff for no behavioral gain. The composite tie-break is
`ofMany(['effective_from' => 'max', 'id' => 'max'])`, matching the existing
`orderByDesc('effective_from')->orderByDesc('id')` ordering exactly.

**Why**: architect's finding (from the earlier TT-6.5/6.6 restructuring pass) was that these two
methods would N+1 the first time they're used across a paginated collection — the fix required is
"make it eager-loadable", not "rename the public API". Reviewer confirmed this is a reasonable,
low-risk shim, not a duplicate-API smell worth blocking on. Reviewer also flagged (not a blocker)
that unlike the old always-re-queried form, the relation is now cached per-instance — a comment
was added on both wrapper methods warning that a write-then-reread on the same instance now needs
an explicit `refresh()`.

**Decision 2 — `OrganizationMemberResource`'s `user` field is a narrow inline projection
(`id`/`fullName`/`username`), not the full `UserMiniResource`**, even though `UserMiniResource` is
the codebase's default "mini" shape for a `User`. Security review caught that reusing it here would
regress a decision already made and documented four lines above it in the same file
(`OrganizationMemberController.php`'s `invite()` deliberately excludes gender/country/dob per
SCRUM-124, since "an ordinary User isn't meant to be publicly/cross-org discoverable"): an org
admin configuring a member's billing mode has no legitimate need for that member's gender, country,
or date of birth. Fixed before merge; a regression test
(`'listing organization members does not leak the member's gender, country, or dob'`) pins the
narrower shape. Left `OrganizationCounsellorResource`'s nested `counsellor.user` (via
`CounsellorMiniResource`) unchanged, since counsellor profiles are already treated as more broadly
discoverable elsewhere in this codebase by deliberate design (same file's own comment) — trimming
`CounsellorMiniResource` itself would be a much wider, out-of-scope change affecting every other
consumer of that shared resource.

**Why**: a real, newly-introduced privacy exposure on a mental-health platform is a required fix,
not something to defer — CLAUDE.md is explicit that a security-engineer finding must be applied or
explicitly flagged with a follow-up ticket, and this one had a cheap, scoped fix available.

**Decision 3 (deferred, not fixed) — the pre-existing 404-vs-403 organization-existence
enumeration oracle** (`EnsureOrganizationExistsAction` vs. `EnsureUserIsOrganizationAdminAction`
return distinguishable statuses for "org doesn't exist" vs. "org exists, caller isn't its admin",
and the route only requires `auth`, not org-specific standing) was **not** fixed in this ticket.
It already existed on `OrganizationController::show`/`update` before this ticket; SCRUM-159 extends
the same weakness to the two new list endpoints rather than introducing it fresh. Filed as
**SCRUM-170** (Low priority) rather than fixed inline, since fixing it properly means redesigning
the shared guard-action pair used by four endpoints (two pre-existing, two new), which is a
larger, more architecturally-invasive change than this ticket's scope, and risks conflating an
existing-and-accepted-until-now gap with new work. A regression test
(`'a nonexistent organization and a real one the caller cannot administer return different
statuses (known gap)'`) pins the current (soon-to-change) behavior so SCRUM-170 has a concrete
test to flip.

**Why**: CLAUDE.md permits deferring a finding "with a follow-up ticket" when it isn't introduced
fresh by the current change and fixing it properly is out of proportion to the ticket at hand —
this is exactly that case, unlike Decision 2 above which was cheap and newly-introduced.
## 2026-08-29 — TT-6.5 (Organizations frontend): restructured after discovering real backend gaps, three product decisions made

**Decision**: TT-6.5 (SCRUM-111's frontend work) went through the full `/start-feature`
product-owner/project-manager/architect gate. What was scoped as a 13-point, frontend-only
ticket turned out to need real new backend work — no list endpoint for an org's own members or
affiliated counsellors, no "my organizations" endpoint for a counsellor/member, no organization
directory (so a counsellor/member had no way to discover an org id to apply to at all), no
org-scoped request queue for admins, no co-admin management (the `OrganizationAdminRoleEnum`
owner/admin distinction exists in the data model but is never enforced). Split into **M4a**
(TT-6.6a–e, new backend enablement, ~23 pts, filed as SCRUM-159–163) which blocks **M4b**
(TT-6.5a/a2/b/c/c2, restructured frontend, ~22–24 pts, filed as SCRUM-165–169) per-ticket rather
than milestone-wide, plus **TT-6.7** (shareable self-apply link, filed as SCRUM-164). Total
~45–52 points, up from the original 13 — the same undersizing pattern already seen in TT-6.3 and
TT-7.2 on first pass.

Three decisions made by the user during planning:
1. **Owner-vs-admin roles get real behavioral enforcement now**, not a display-only badge — only
   an owner may remove the org or add/promote/demote other admins (new
   `EnsureUserIsOrganizationOwnerAction`, TT-6.6e).
2. **Organization discovery ships as both a directory (TT-6.6c) and a shareable admin-generated
   link (TT-6.7), not either/or** — architect confirmed these solve genuinely different problems
   (curated browse-and-apply vs. a targeted, single-use grant mirroring the existing
   discussion/guardianship Link pattern), so building both isn't redundant duplication.
3. **The directory is verified-only** — an unverified organization stays invisible to browse/apply
   until a platform admin verifies it, matching how counsellor verification already gates
   visibility elsewhere in the app.

**Deferred, not this restructuring's call**: the "sponsored by [org]" indicator on therapy/
counsellor cards (SCRUM-111's third flagged open item) — both product-owner and project-manager
recommended deferring it to a future TT-7.3a-adjacent ticket, since it depends on TT-7.3a (not yet
built) and an undecided definition of "sponsored" over time. Proceeding with that deferral since
neither reviewer treated it as blocking, and no objection was raised.

**Why**: the three decisions above are genuine, costly-to-guess-wrong forks (real enforcement vs.
display-only changes TT-6.6e/TT-6.5a2's actual scope; directory-vs-link changes which backend
gets built at all; verified-only vs. show-all changes the directory's query and field exposure) —
raised to the user per CLAUDE.md's autonomous-execution rules rather than assumed. The backend-gap
discovery itself mirrors TT-7.4's own planning history (TT-7.4a's backend-plumbing prerequisite
found the same way) — treated as a scope correction grounded in code actually read (architect
verified every claimed gap against `OrganizationController`/`RequestService`/`Organization`
model), not a guess.

---

## 2026-08-30 — SCRUM-160 (TT-6.6b): last of the M4a backend batch; a reviewer-verified
non-bug in the administered-organizations ordering

**Decision**: implemented all three "my organizations" endpoints as straightforward self-scoped
Eloquent relation reads (`Counsellor::organizationCounsellors()`, `User::organizationMemberships()`,
`User::administeredOrganizations()`) with no new guard/authorization actions — the query is
already fully scoped to the requesting `$user`'s own relations, so there's no cross-user
authorization decision to make (security review independently confirmed no cross-user leakage is
possible, since no route parameter or request field ever influences which user's data is
queried). The one asymmetry — a missing `Counsellor` account throws a 422
(`CounsellorNotFoundException`) for the counsellor-affiliations endpoint, while the memberships/
administered endpoints return an empty list for a user with none — is deliberate: a `User` always
has memberships/administered-orgs as a valid (possibly empty) set, but "your counsellor
affiliations" presupposes a `Counsellor` account that may not exist at all, mirroring
`EnsureCounsellorExistsAction`'s existing precedent for other counsellor-only actions.

**Non-bug, verified empirically**: `GetMyAdministeredOrganizationsAction` explicitly qualifies
`orderByDesc('organizations.created_at')` rather than an unqualified `latest()`, based on a
belief that `organizations`/`organization_admins` (both having their own `created_at` via
`withTimestamps()`) would otherwise throw an ambiguous-column SQL error once the `belongsToMany`
join compiles. Reviewer tested this directly against the project's real MySQL container and found
it does NOT currently error — Eloquent aliases the pivot's copy as `pivot_created_at`, so only one
output column is literally named `created_at`, and MySQL's ORDER BY resolution isn't ambiguous in
that specific case. The qualification is kept anyway (harmless, and not dependent on that pivot-
aliasing behavior continuing forever), but the code comment and test description were corrected
to stop asserting a failure mode that doesn't actually reproduce against the current relation
shape — a future engineer reading the original wording could have over-generalized it to a
`belongsToMany` case where it *would* actually matter.

**Why**: recorded because the original (incorrect) rationale could otherwise propagate as a
"known codebase gotcha" via comment-copying, per the reviewer's own explicit maintainability
concern — a cheap, no-behavior-change correction applied immediately rather than left as-is.
With SCRUM-160 merged, all of M4a (TT-6.6a–e) and TT-6.7 are complete.

---

**Note (2026-08-30)**: this file's entries for SCRUM-159/162/163/164 (all merged into `develop`
via PRs #99-#102 before this ticket branched) do not appear between this entry and the one below,
even though they were written and committed on each of those branches. Likely lost during manual
merge-conflict resolution on one or more of those PRs (the same class of issue caught and fixed
in `OrganizationService.php` for PR #99's actual code) — flagged for awareness, not reconstructed
here, since this is a documentation-history gap, not a functional defect.

---

## 2026-08-30 — SCRUM-165 (TT-6.5a): first frontend ticket; a real, substantial QA-caught bug
batch fixed before merge

**Decision**: built the org admin dashboard as a single `Organization/Show.vue` page (matching
`Profile/Counsellor/Show.vue`'s actual established shape — one page, edit-via-modal, NOT a
separate routed Edit page, since `Counsellor/Edit.vue` turned out to be an empty, unused file)
with three section Partials (Counsellors/Members/RequestQueueSection) driven by props from a new
`OrganizationController::dashboard()` route, additive to the existing JSON-only `show()` action
rather than modifying it. A new `GetOrganizationRequestQueueAction` mirrors SCRUM-162's
`getRequests()` `orWhere` shape but scoped to one organization, since the org-scoped request
queue itself was out of SCRUM-162's own additive-only scope guard.

**QA found real, substantial bugs on the first pass — not approved, fixed before merge**:
1. **"Load more" pagination was completely broken** on all three lists: each paginator's
`links.next` defaulted to the dashboard's own URL (not the dedicated JSON list endpoints), so the
frontend's plain axios "load more" GET got back a full HTML page instead of JSON and crashed.
Fixed by explicitly `->setPath(route(...))` on each paginator before wrapping it for the Inertia
props.
2. **Compensation counter-offers always failed, and — separately — every toast in all three new
section components was invisible.** The counter-offer form sent stale/irrelevant fields
regardless of the selected type, tripping the backend's cross-field validation for every
configuration; fixed via a payload builder that only includes type-relevant fields. Independently,
all three section Partials called `useAlert()` locally (a deliberately non-singleton composable)
but never rendered an `<Alert>` — only the parent `Show.vue` does, bound to its own separate
instance — so every success/failure toast from inside those three components updated state
nothing displayed. Fixed by having them `emit('alert', ...)` instead, forwarded by `Show.vue` to
its one rendered `<Alert>` (mirrors this codebase's existing `RequestBadge.vue` → parent `@alert`
pattern).
3. **Editing the org profile didn't visually update the dashboard until a hard reload**, and
reopening the edit modal kept showing pre-save values. Root cause: `Show.vue` took a one-time
`ref({...props.organization})` snapshot that never re-tracked Inertia's (correctly) updated props;
fixed by switching to `computed(() => props.organization)`. `UpdateOrganizationForm.vue` had the
identical root cause for its own form defaults, fixed via a `watch(() => props.show, ...)` that
calls `form.defaults()`/`form.reset()` on reopen.
4. A second QA re-verification pass (after the above were fixed) then caught a follow-on bug
**introduced by fix #2's own new error-handling code**: Laravel's validation-error shape
(`{field: [messages]}`) was assigned directly to the `InputError` component's `message` prop
(expects a plain string), rendering as a literal `["The percentage field must be..."]` array
string instead of clean text. Fixed by routing through this codebase's existing
`useErrorHandler()`/`setErrorData()` composable (already used elsewhere for exactly this
unwrapping) rather than a raw assignment.

**Deferred, not fixed (out of scope for this ticket)**: the same QA pass surfaced that the
*generic* request-respond pipeline (`RequestService::respondToRequest()`, used by every request
type in the app — guardianship, group-therapy membership, counsellor verification, org
invites/applications, discussion invites) never re-checks a `Request`'s `status` is still
`PENDING` before applying an accept/reject, unlike the dedicated check
`CounterOfferOrganizationCounsellorCompensationChangeAction` already has for compensation
negotiations specifically. Filed as **SCRUM-171** rather than fixed inline: it's a pre-existing
gap in shared, cross-cutting infrastructure this ticket happens to exercise more heavily, not
something SCRUM-165 introduced, and fixing it properly means touching the shared guard chain used
by every request type in the app — out of proportion to this ticket's own scope.

**Why**: fixes #1-#4 are all newly-introduced-by-this-ticket bugs with cheap, scoped fixes
available — CLAUDE.md requires applying these, not deferring them. SCRUM-171 is the opposite case
(pre-existing, cross-cutting, disproportionate-to-fix-here) — the same distinction already drawn
for SCRUM-159's Decision 3/SCRUM-170. Recorded in detail here because this is the first ticket in
the session where a qa-engineer pass genuinely found the feature not-done on its first submission,
not just style/architecture feedback — worth keeping as a concrete example of why the Playwright
QA gate matters for full-ceremony UI work, not just a checkbox.

---

## 2026-08-30 — SCRUM-167 (TT-6.5b): counsellor "my organizations" + apply flow; a genuine
route-ordering bug, a seeded-data bug, and two self-caught process mistakes

**Decision**: built the counsellor-facing counterpart to SCRUM-165's org admin dashboard as a
new `Organization/MyOrganizations.vue` page (`GET /organizations/mine/dashboard`) with three
sections — affiliations list, a dedicated request queue, and a browse-and-apply directory —
mirroring SCRUM-165's page/Partials shape. A new `GetMyOrganizationRequestQueueAction` mirrors
`GetOrganizationRequestQueueAction`'s query shape but needs an explicit `whereIn('type', [...])`
filter that the org-scoped version doesn't: `Organization` is exclusively used as a `from`/`to`
party for org-context request types, but `Counsellor` is also a polymorphic party for unrelated
types (therapy assistance, discussion invites, verification) — an unfiltered `from/to` match would
leak those into this org-specific queue. Reused `OrganizationRequestResource` (not the plainer
`RequestResource` SCRUM-165's queue uses) so proposed compensation terms/round/expiry are visible
before a counsellor decides — a deliberate improvement over the org-admin queue's blind
counter-offer form, since AC3 explicitly requires this data to be shown. Extracted the counter-offer
modal (previously inline in `RequestQueueSection.vue`) into a shared `CompensationCounterOfferModal.vue`
now used by both sides, rather than duplicating a non-trivial form for a second use site.

**Bug found via reviewer, fixed before QA**: registering `/organizations/mine/dashboard` *after*
`/organizations/{organizationId}/dashboard` in `routes/web.php` meant Laravel matched the wildcard
route first, capturing `organizationId="mine"` and producing "organization not found" — caught by
my own feature test, not by a subagent. Fixed by moving the entire `mine/*` block above
`organizations.dashboard` (the pre-existing SCRUM-160 routes happened not to collide only because
none of them shared a literal path segment with `organizations.dashboard`'s `dashboard` suffix).

**Bug found via qa-engineer's Playwright pass**: the seeded pending compensation-change Request I
added to `org_demo_counsellor`'s demo data had no `data.proposedById`, so accepting it threw "the
original proposer no longer exists" — `RespondToOrganizationCounsellorCompensationRequestAction`
resolves this id to attribute the accepted terms. Fixed by setting it to the seeded org admin's
user id.

**A real gap I found myself, not flagged by any reviewer**: accepting the seeded compensation
negotiation updated the affiliation's compensation server-side but left the My Organizations
table showing the old amount until a manual reload — the request-queue section had no way to tell
its sibling affiliations section a compensation change had just landed. Fixed with a
`compensation-accepted` event bubbled from `MyOrganizationRequestQueueSection` up to
`MyOrganizations.vue`, which reloads `MyAffiliationsSection` (same `ref().reload()` pattern as
SCRUM-165's `@invited` wiring). This exact staleness already exists, unfixed, on the merged
org-admin side (accepting an application there doesn't refresh `CounsellorsSection`'s row either)
— left as-is there since it's out of this ticket's scope and not something QA or the reviewers
flagged on that already-shipped page; worth a small follow-up if it comes up again.

**Two of my own process mistakes, both caught before merge**:
1. Used `Write` to create `tests/Feature/MyOrganizationsControllerTest.php` for this ticket's
tests without first reading the file — silently overwriting SCRUM-160's existing tests for
`organizations.mine.memberships`/`.administered` (guest-401 checks and an N+1 regression guard)
that lived at the same path. Caught by `security-engineer`'s review, not by me. Fixed by restoring
the original file via `git checkout HEAD --` and moving this ticket's new tests to a distinctly-named
`tests/Feature/MyOrganizationsDashboardControllerTest.php` instead.
2. qa-engineer flagged an intermittent, unreproducible-by-static-review click failure on invite-type
accept/reject buttons ("Bug 2"), recommending re-verification before sign-off rather than dismissal.
Investigated directly: built a fresh test scenario via `tinker` and reproduced a real 500 on first
click — but the actual cause was that my ad-hoc test Request was missing `for()->associate($organization)`
(only `from`/`to` were set), which `RespondToOrganizationCounsellorRequestAction` dereferences as
the Organization to re-check eligibility; `for` being null threw a `\Error` that the controller's
generic `catch (Throwable $th)` swallowed into a silent 500 with no log entry (caught-and-handled
exceptions aren't auto-logged by Laravel's global handler). Fixing the test data's `for` association
and retrying reproduced success on the very first click, every time — confirming this was a test-
artifact (most likely qa-engineer made the same construction mistake when fabricating an invite
scenario, since none of the properly-seeded data ever exhibited it), not a real defect.

**Why**: recorded in this much detail because two of the five issues resolved during this ticket's
review cycle were self-inflicted process mistakes rather than product bugs — worth keeping as a
concrete reminder to always read a file before `Write`ing over it even under time pressure, and
that an "intermittent, no-console-error" QA finding can still be worth a real repro attempt rather
than either blind acceptance or a shrug, per qa-engineer's own explicit ask not to dismiss it
without a definitive answer.

---

## 2026-08-30 — SCRUM-168 (TT-6.5c): member "your organizations" view; unified into the
existing dashboard rather than a new page, plus a real bug found via my own smoke-testing

**Decision**: rather than building a third near-duplicate page/route for the member-facing view,
extended the already-built `Organization/MyOrganizations.vue` (SCRUM-167, previously
counsellor-only) to be reachable by any authenticated user. `OrganizationController::myOrganizationsDashboard()`
now branches on `$user->counsellor`: present → populate the counsellor-only sections (affiliations,
request queue, browse-and-apply) exactly as before; absent → those props are simply `null` and the
page omits those sections, while a new "My Memberships" section (`MyMembershipsSection.vue`,
consuming the unchanged SCRUM-160 `organizations.mine.memberships` endpoint) is always shown. This
mirrors SCRUM-165's own is_provider/is_consumer conditional-section pattern on the org-admin
dashboard, and avoids the app accumulating three separate "my organizations"-shaped pages
(counsellor, member, and eventually admin via SCRUM-173) with near-identical layouts. AC2 ("accept/
reject an org's invite via the existing generic request-respond endpoint") needed zero new backend
or a dedicated queue like SCRUM-167's — a member's invite/application already flows through the
pre-existing, app-wide personal "Requests" nav-dropdown modal, which every user already has.

**A real, pre-existing bug found via my own Playwright smoke-test, not part of this ticket's
stated scope**: that generic "Requests" modal (`RequestBadge.vue`, used by every user in the app
for every request type) rendered a literal "from: @undefined" for any request whose party is an
Organization — its from/to label logic only handled `isCounsellor` vs. falling through to
`@${party.username}`, with no `isOrganization` branch. This had apparently never been exercised
end-to-end before: org-context request types have existed since much earlier in this epic, but
nothing had shown one to a plain member through this specific generic modal until this ticket's
own AC2 verification. Fixed with a `partyLabel()` helper mirroring the same-shaped helper already
used in `RequestQueueSection.vue`/`MyOrganizationRequestQueueSection.vue` — incidentally also fixes
the same "@undefined" bug for a *deleted* counsellor/user party, per reviewer's independent trace
of the resource shapes.

**Two reviewer-flagged fixes applied before commit**: `documentation/seeded-data.md` wasn't
updated for the new `org_demo_member_invitee` seed account (added to exercise AC2) — fixed.
`MyMembershipsSection.vue`'s `statusLabel()` had a `PENDING` branch copied from the counsellor/
admin-side pattern, but `OrganizationMemberStatusEnum` only has `ACTIVE`/`ENDED` — a membership is
active immediately on creation, unlike a counsellor affiliation which can sit pending compensation
agreement. Since this file was brand new and not yet merged (unlike its already-shipped
`MembersSection.vue` counterpart, left untouched per reviewer's own scoping), simplified it to the
two real states rather than propagating a third copy of speculative dead code.

**Deferred, not fixed here**: qa-engineer's Playwright pass separately found that
`RequestBadge.vue`'s message map also has no entry for `groupTherapyMembership` (SCRUM-72's
join-a-group-therapy request type), rendering blank for both parties — pre-existing, unrelated to
this ticket's own changes to that file, filed as **SCRUM-175** rather than fixed inline (same
deferral pattern as SCRUM-171/SCRUM-174: a real gap in shared infrastructure this ticket happened
to touch, not something SCRUM-168 introduced).

**Why**: the unify-into-one-page call is recorded because it's a real architectural fork (three
separate pages was the naive alternative) resolved by precedent (SCRUM-165's own conditional-
section pattern) rather than by asking, per CLAUDE.md's guidance to proceed on well-precedented,
reversible calls. The `RequestBadge.vue` fix is recorded in detail because it's the second ticket
in a row (after SCRUM-167's own self-caught bugs) where my own manual Playwright verification
found something real that no subagent had reason to look for, since it required actually clicking
through the specific combination this ticket newly exercises.
