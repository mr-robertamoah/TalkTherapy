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

---

## 2026-08-30 — SCRUM-166 (TT-6.5a2): co-admin management UI; the last piece of TT-6.5a's
backend, already fully built and tested, needed only a frontend consumer

**Decision**: this ticket turned out to be almost entirely a frontend exercise. SCRUM-163 (much
earlier in this epic) had already built and fully tested the entire backend for co-admin
management — `OrganizationAdminController::store/update/destroy`, `OrganizationAdminService`,
owner-only enforcement (`EnsureUserIsOrganizationOwnerAction`) and last-owner protection
(`EnsureOrganizationRetainsAnOwnerAction`) — none of which needed to change. Added a new
`admins` prop to the existing `OrganizationController::dashboard()` (unpaginated, unlike its
sibling counsellors/members/requestQueue props — an org's admin roster is expected to stay small)
and a new `AdminsSection.vue` consuming the three pre-existing mutating endpoints, each of which
already returns the full refreshed admin list — no new backend surface at all beyond the one-line
prop addition.

**A real, deliberate judgment call on the promote/demote/remove buttons**: built as actual
`<SecondaryButton>`/`<DangerButton>` components rather than the text-link-styled `<button
class="text-blue-600 hover:underline">` pattern used throughout the rest of this dashboard's
existing sections (`MembersSection.vue`'s "edit billing", `RequestQueueSection.vue`'s
"counter-offer") — the user had already flagged link-styled action buttons as a TODO item to fix
project-wide, so new code shouldn't add more of the same debt even though the existing pattern is
what a naive copy-paste would have produced.

**Reviewer/security findings, all minor**: reviewer's one suggestion (a positional
`admins.0`/`admins.1` test assertion, fragile since `Organization::admins()` has no `orderBy`)
was fixed by looking up each admin by id instead, mirroring the existing convention in
`OrganizationAdminManagementTest.php`. security-engineer's one flagged item — the admin roster
(name/username/role) is now visible to *every* admin of an org on page load, not just owners,
which is a real widening vs. pre-ticket behavior where only an owner-gated mutating-endpoint
response ever returned it — is exactly what AC2 itself specifies ("a plain admin can view the
current admin list"), so no further confirmation was needed beyond the ticket's own text.
security-engineer's other finding (`AddOrganizationAdminRequest`'s `exists:users,id` validation
running before the owner-authorization check, a mild pre-existing user-ID-existence oracle from
SCRUM-163, now more directly reachable via this new UI) was filed as **SCRUM-176** rather than
fixed inline, following the same deferral pattern as SCRUM-171/174/175.

**Seed-data gap found by qa-engineer, fixed before commit**: the seeder only had a single
owner-role admin for the demo org, so exercising promote/demote/remove, last-owner protection, or
the plain-admin read-only view all required hand-creating accounts via `tinker` — contrary to
CLAUDE.md's explicit seeding convention. Added a second, plain-role admin
(`org_demo_plain_admin`) to the existing seed data.

**Why**: recorded because this is the cleanest example yet in this epic of a ticket where the
*backend* ceremony (full review/test cycle) had already happened on an earlier ticket, and this
one's own review correctly focused entirely on the thin new frontend layer rather than
re-litigating already-settled authorization logic — worth noting as the pattern to follow when a
late-epic ticket's scope turns out to be "just wire up the UI."

---

## 2026-08-30 — SCRUM-173 (TT-6.5d): "organizations I administer" list; closes the last
navigation gap this audit found, entirely by extending the existing my-organizations page

**Decision**: rather than a fourth near-duplicate "my organizations"-shaped page, added
"Organizations I Administer" as the first section on the already-shared `MyOrganizations.vue`
page (from SCRUM-167/168) -- unconditionally fetched (unlike the counsellor-only sections),
since any user can administer zero or more orgs independent of counsellor/member status. The
"nav entry point" half of this ticket's own AC1 was already satisfied for free: SCRUM-168 had
already widened the "My Organizations" nav link to any authenticated user, so no new nav change
was needed here. Reuses `organizations.mine.administered` (SCRUM-160) and `organizations.dashboard`
(SCRUM-165) entirely unchanged -- this ticket's backend footprint is a single new controller
block passing an already-existing, already-tested paginator as a prop.

**Reviewer-caught bug, fixed before commit**: the "open dashboard" link nested a `<PrimaryButton>`
(renders `<button>`) inside an Inertia `<Link>` (renders `<a>`) -- invalid HTML (interactive
content inside interactive content), and a first in this codebase; every existing example of a
button-styled `<Link>` (e.g. `Profile/Show.vue`'s "Manage Preferences") applies the button classes
directly to the `Link` itself instead. Fixed by removing the nested component and moving
`PrimaryButton`'s exact classes onto the `Link`, preserving the visual style while producing valid
markup.

**Why**: recorded because this is the last ticket the original navigation-gap audit
(2026-08-30) identified, closing out that entire line of work -- the epic's Organizations
frontend (TT-6.5a/a2/b/c/d) is now complete. The reviewer finding is worth keeping as a concrete
example of *why* this project prefers styling `<Link>` directly over wrapping button components:
the wrapped pattern silently produces invalid HTML that a visual/functional test alone won't
catch (the link still rendered and navigated correctly either way).

---

## 2026-08-30 — Bugfix batch: org dashboard frontend polish (TODO items); a genuine browser
regex-compilation gotcha, and a user-caught reminder that frontend validation is never sufficient
alone

**Decision**: closed out several small items from a product TODO note, all scoped to the org
admin dashboard: currency fields changed from free-text to a `Select` dropdown sourced from
`config('currencies.supported')` (matching the existing pattern in `UpdateCounsellorPricing.vue`);
amount/percentage/expiry number inputs got `min`/`max`/`step` matching the backend's own validation
ranges; remaining link-styled buttons (`edit billing`, `counter-offer`) became real button
components; "load more" (accumulate-on-scroll) pagination on the three org-admin-dashboard list
sections (`CounsellorsSection.vue`, `MembersSection.vue`, `RequestQueueSection.vue`) was replaced
with real numbered pagination via a new shared `Pagination.vue` component, scoped to just this
dashboard per the user's own explicit clarification when this batch was picked up (not the
counsellor/member "my organizations" equivalents).

**A genuine, non-obvious browser bug found and fixed while adding phone format validation**:
naively writing an HTML `pattern` attribute like `[0-9+\-\s()]{7,}` compiled and validated
correctly when tested via `new RegExp(str, 'u')` in a JS console, but silently validated
*nothing* when actually used as an `<input pattern>` in a real browser — every value, including
obviously-invalid ones, passed `checkValidity()`. Root cause: modern Chromium compiles the HTML
`pattern` attribute using regex `v`-mode (`unicodeSets`), a stricter grammar than `u`-mode where
an unescaped `-`, `(`, or `)` inside a character class is a *compile error* — and per the HTML
spec, a pattern that fails to compile is treated as "no constraint" (always valid), with zero
visible symptom short of manually testing `checkValidity()`. Fixed by escaping every special
character (`[0-9+\s\(\)\-\.]{7,}`), verified empirically against a live browser rather than
trusted from spec-reading, since the discrepancy between "compiles fine via `new RegExp(str,
'u')`" and "silently disables `pattern` validation" is exactly the kind of gap that's easy to miss
by reasoning alone.

**Reviewer-caught regression from that same fix**: the escaped pattern (before adding `.`) rejected
dot-separated phone formats (e.g. `270.271.6592`), which Faker's default `phoneNumber()` — used by
every seeded organization — frequently produces. Since the phone field is pre-filled on modal open
and native HTML5 constraint validation blocks the *entire form* if any field is invalid, this
would have silently blocked editing ANY field (not just phone) on an org whose stored phone
happened to be dot-formatted, with no visible error beyond a browser validation bubble on a field
the admin never touched. Fixed by adding `.` to the allowed character set (verified via Node's
`v`-flag regex support, then confirmed against a live seeded org with its phone deliberately set to
a dot-format).

**User-caught gap this session should have caught itself**: after fixing the frontend phone
pattern, testing (deliberately, to verify the fix) revealed that "abc" had been saved as a real
organization's phone number earlier in this session -- while the frontend pattern bug above was
still unfixed. The user pointed out, correctly, that this exposed a *separate*, more fundamental
problem: `UpdateOrganizationRequest`/`CreateOrganizationRequest`'s `phone` rule was (and had
always been) just `['string', 'max:255']` -- zero format validation server-side, meaning ANY
client that bypasses or lacks JavaScript (a raw API call, a disabled-JS browser, or simply a
frontend bug like the one above) could always have saved a non-phone value, regardless of whether
the frontend pattern was correct. Fixed by adding matching `regex:/^[0-9+\s().-]{7,20}$/`
validation to both request classes -- the frontend pattern is now a UX convenience, with the
backend as the actual enforcement boundary, per this project's established defense-in-depth
convention elsewhere (e.g. currency, compensation amounts).

**Other reviewer-required fix**: added test coverage (`tests/Unit/SupportedCurrencyValidationTest.php`)
for the `CreateOrganizationCounsellorCompensationRequest` currency tightening (`'string','size:3'`
→ `Rule::in(config('currencies.supported'))`) from earlier in this same batch, which had shipped
without a test.

**Why**: recorded in detail because this is a genuine "process worked as intended" example within
a single small bugfix batch -- a real browser gotcha was found and fixed, a reviewer pass caught a
real regression in that same fix before it shipped, and the user's own manual observation caught a
more fundamental gap (missing backend validation) that neither the implementation nor the review
pass had surfaced, since both were focused on the frontend pattern's own correctness rather than
questioning whether frontend-only validation was ever sufficient. Worth keeping as a concrete
reminder that "the frontend now validates X" is never itself a complete answer to "is X validated"
-- the backend question has to be asked explicitly, every time, not inferred from the frontend
fix being correct.

---

## 2026-08-30 — SCRUM-172: search-and-select for org invite/add-admin flows; a new shared
`SearchSelect.vue` component, and a reviewer-caught `id`-fallthrough regression worth remembering

**Decision**: replaced the raw numeric-id `TextInput`s in `CounsellorsSection.vue` (invite
counsellor), `MembersSection.vue` (invite member), and `AdminsSection.vue` (add co-admin) with a
new, reusable `SearchSelect.vue` component -- debounced search, click-to-select, "change" to clear,
closes on outside click. No backend work was needed: this reuses the two already-live,
already-tested search endpoints (`api.users`/`api.counsellors`) that `AddGuardianModal.vue`/
`DiscussionModal.vue`/`GroupTherapyFormModal.vue` already search against, confirmed via the
2026-08-30 audit that re-scoped this ticket down from its original (assumed-bigger) footprint. The
existing card-based bespoke search UIs in those three flows were deliberately left untouched
rather than folded into `SearchSelect` -- they're a different UX (multi-select with manual
pagination) serving a different need, and forcing them onto this component would have been scope
creep beyond what the ticket asked for.

**Reviewer-caught regression, fixed before commit**: the first version of `SearchSelect.vue` didn't
declare an `id` prop, so `<SearchSelect id="user" ...>` at each call site put `id="user"` on the
component's own wrapper `<div>` (Vue's default attribute fallthrough target) instead of on the
actual `<input>` nested inside it. The visible symptom was nothing -- the field still worked by
mouse -- but every `<InputLabel for="user">` pairing silently stopped associating with a focusable
control, a real accessibility regression invisible to manual click-through testing. Fixed by
declaring `id` as an explicit prop (removing it from the automatic fallthrough set) and forwarding
it explicitly onto the inner `TextInput`, then confirmed via `document.querySelector('#user')` in a
live browser session that the id now resolves to the `<input>` element itself.

**Reviewer-caught gap, fixed before commit**: the search request had no `.catch()`, so a failed or
401'd request (e.g. an expired Sanctum session mid-session) would silently do nothing visible and
throw an unhandled promise rejection -- every comparable existing search flow in this codebase
already has one. Added, along with a request-token guard against a slower, earlier query's response
landing after a faster, later one's and clobbering it (the 400ms debounce rate-limits when requests
fire, not the order they resolve in) -- a real, if low-probability, gap worth closing given this
component is meant to become the org dashboard's shared search-and-select pattern going forward.

**Deferred, not dropped**: the reviewer and security-engineer both flagged non-blocking items
that didn't need to hold up this ticket -- `SearchSelect` only ever fetches page 1 (both backend
endpoints paginate, so a common search term can silently truncate with no "more results" signal),
no keyboard-only selection support (mouse/click only), and the pre-existing, unworsened lack of
throttle middleware on `api.users`/`api.counsellors`. Filed as SCRUM-177 rather than silently
dropped, per this project's rule against ignoring review findings without a documented reason.

**Why**: recorded because the `id`-fallthrough bug is a good concrete example of why "I clicked
through it in the browser and it worked" doesn't catch every regression a code reviewer will --
label/input association has zero visible effect on a mouse-driven manual test, and would only have
surfaced as a real accessibility complaint (or an automated a11y check this project doesn't have)
much later. Worth remembering for any future shared Vue component that wraps a native form control:
forward `id` explicitly rather than relying on default attribute fallthrough once the wrapper isn't
itself a single native element.

---

## 2026-08-30 — SCRUM-177: SearchSelect.vue hardening (keyboard nav, pagination, throttle) --
a reviewer-caught concurrency bug in the very fix meant to close a reviewer-flagged gap

**Decision**: closed the three non-blocking items SCRUM-172's own review pass had flagged rather
than left them to rot: `SearchSelect.vue` gained arrow-key/Enter keyboard selection (`role`/
`aria-*` attributes, an `activeIndex` cursor) and a "load more" control paginating past each
endpoint's first page (`api.counsellors` caps at 5, `api.users` at 10); `api.users`/`api.counsellors`
gained `throttle:60,1`, matching the existing `organizations.index` precedent, since the app's
general `api` RateLimiter is disabled entirely.

**Reviewer-caught concurrency bug, fixed before commit**: the first version of `loadMore()` had no
re-entrancy guard -- two rapid clicks before the first request resolved would increment `page`
twice and fire two overlapping requests, and the existing out-of-order-response `searchToken` guard
(itself a fix from the prior SCRUM-172 review pass) would then silently discard the *first*
response once it landed, since by then `searchToken` no longer matched. The result: the page that
first response represented was never appended and never retried -- results end up as page 1 +
page 3, permanently missing page 2's rows, with no visible error and no way to recover except
retyping the search from scratch. Worth remembering as a distinct class of bug from what the
`searchToken` guard actually solves: that guard protects against out-of-order *resolution*, not
concurrent *dispatch* -- the two need separate guards even though they look like the same problem
at first glance. Fixed with a plain `if (searching.value) return` at the top of `loadMore()`,
which is sufficient because Vue's/JS's single-threaded execution means `searching.value` is already
`true` (set synchronously before the first `await`) by the time a second, near-simultaneous click's
handler actually runs.

**Reviewer-required test, added**: `tests/Feature/SearchRateLimitTest.php`, mirroring the existing
`MessageRateLimitTest.php` pattern for `throttle:messages` -- confirms 60 requests succeed and the
61st 429s, for both the authenticated (`api.users`, keyed per-user) and public (`api.counsellors`,
keyed per-IP via `withServerVariables`) cases, plus that each endpoint's limit is isolated (per-user
for `api.users`, per-IP for `api.counsellors`) rather than shared globally.

**Reviewer-suggested, applied**: a load-more failure no longer collapses the dropdown and discards
`hasMore` outright (it now only does that for a *fresh* search failure) -- it instead rolls `page`
back by one so a retry re-fetches the page that failed, and the already-fetched first page's
results stay visible. Similarly, `activeIndex` (the keyboard-highlighted row) is no longer reset on
every `results` mutation, only on an actual fresh search/clear -- an append from "load more" no
longer discards an in-progress keyboard highlight on a still-visible row.

**Reviewer-suggested, deferred (documented, not silently dropped)**: giving the "load more" row its
own keyboard path (it's currently mouse/click-only, an internal inconsistency in a diff whose whole
point was closing a keyboard-accessibility gap) and wiring `aria-activedescendant` on the input for
more complete screen-reader support of the highlight state. Both were explicitly called "polish, not
a regression" by the reviewer and don't warrant their own tracking ticket at this scale, but are
worth picking up whenever `SearchSelect.vue` is touched again.

**Why**: recorded mainly for the concurrency-bug lesson -- this is the second time this specific
component has had a "the fix for one review finding introduces a new bug that only shows up under a
timing condition manual testing won't naturally hit" (the first was the out-of-order-response race
in the original SCRUM-172 pass; this is the double-dispatch race in this pass's own pagination fix).
A pattern worth watching for: whenever a fix introduces a second async operation triggered by a
UI-repeatable action (a button that can be clicked more than once before the first call resolves),
ask explicitly "what happens on a second click before the first resolves" as its own question,
separate from "what happens if responses arrive out of order."

---

## 2026-08-30 — SCRUM-171: the ticket's own premise didn't match the codebase -- a reviewer catch
that changed the fix from "close a data-integrity bug" to "make a known no-op honest," with the
user explicitly choosing to make it a real, app-wide behavior change

**What the ticket claimed**: responding to an already-decided Request (e.g. a second, slower
responder) would "silently succeed and flip the already-rejected request's status back to
ACCEPTED" -- a data-integrity bug, reproduced via tinker + a second `requests.respond` call.

**What was actually true**: every one of the 9 `RespondTo*RequestAction` classes dispatched from
`RespondToRequestAction` already re-checks the target Request's status under a `DB::transaction` +
`lockForUpdate()`, and silently no-ops (returns the request *unchanged*) if it's no longer
PENDING -- this was built and deliberately tested as SCRUM-80 (originally just for group-therapy
membership) and extended to the other four/five action types as SCRUM-91, with
`tests/Unit/RespondToRequestActionIdempotencyTest.php`'s own header comment stating the intent
outright: a duplicate/repeated respond call "must not re-run side effects." So the request's
*status* was never actually at risk -- the ticket's reproduction, taken literally, would not have
produced the described flip in the current codebase. The real, narrower gap: `RequestController::
respond()` still reported that silent no-op as a 201 "success," giving the second caller no signal
that their response did nothing.

**How this was caught**: the reviewer, given the first version of this fix (`EnsureRequestIsStill
PendingAction`, wired into `RequestService::respondToRequest()` to throw a 422 before any write),
traced all 9 `RespondTo*RequestAction` classes and found the existing lock-and-no-op guard in each
one -- concluding the fix's own comments (and the ticket's) were factually wrong about what bug was
being fixed, and that changing a 201-silent-success into a 422-error for the *entire* shared respond
pipeline (every request type in the app) was a real, unacknowledged behavior/contract change, not a
pure bugfix, since `RespondToRequestActionIdempotencyTest.php` explicitly locks in the old contract
as intentional. This was independently verified (re-read all 9 action files, confirmed the lock +
no-op pattern in each, read the idempotency test file's own header comment) before acting on it --
the claim was serious enough (it contradicted both the Jira ticket and my own initial fix's stated
rationale) to confirm firsthand rather than either blindly trusting or blindly dismissing a
subagent's finding.

**Decision**: rather than silently pick a side, this was surfaced to the user as a genuine product
fork -- (a) make the 422 the new, real, app-wide behavior and update the SCRUM-80/91 idempotency
tests/comments to describe the corrected contract, or (b) drop the fix entirely, since the actual
data-integrity concern was already closed. The user chose (a): responding to an already-decided
request should show as an error, everywhere, going forward. Implemented by keeping
`EnsureRequestIsStillPendingAction` as-is (the 422 behavior), but rewriting every comment that had
described the old bug inaccurately (in the action itself, in `RequestService::respondToRequest()`,
and in the new test file) to correctly describe what was actually happening before this ticket: a
safe-but-silent no-op, not a data-corrupting flip. The existing `RespondToRequestActionIdempotency
Test.php` suite was deliberately left untouched -- it exercises the 9 actions directly, bypassing
`RequestService::respondToRequest()` entirely, so it is not in conflict with the new 422 behavior at
the entry point; it continues to correctly assert that a *direct* action call remains a safe no-op,
which is still true and still desirable (that's the actual data-integrity guarantee, unaffected by
this ticket). The new outer check is explicitly documented as a fast, unlocked pre-check layered on
top of that untouched guarantee, not a replacement for it -- a true, near-simultaneous race can still
land past it into a per-type action's own no-op, which is a known, narrow, and accepted residual
(closing it fully would mean moving the check inside each of the 9 actions' own locked blocks,
tracked as a possible follow-up rather than done here).

**Why**: recorded in detail because this is the most consequential single catch of the session --
not a code bug, but a ticket whose own stated bug didn't reproduce, caught only because the reviewer
(and then this agent, independently) read the *actual* call chain instead of trusting the ticket's
narrative. Worth remembering: a Jira ticket's reproduction steps are a hypothesis about the
codebase, not a fact about it, especially for a ticket describing a fix to something built in an
earlier, related ticket (SCRUM-80/91 here) -- always re-verify a "here's the bug" ticket against the
current code before trusting its diagnosis, not just its acceptance criteria.

---

## 2026-08-30 -- SCRUM-182/TT-10: Jira sub-ticket linking via Relates/Blocks, not parent

**Decision**: when filing SCRUM-183 through SCRUM-190 (TT-10.1-10.8) as sub-tickets of SCRUM-182,
`createJiraIssue`'s `parent` field was rejected ("Please select valid parent issue") because
SCRUM-182 is issue type "Feature" (`hierarchyLevel: 0`) -- the same hierarchy level as
Story/Task/Bug, not an Epic (`hierarchyLevel: 1`), so it cannot hold children via the `parent`
field the way an Epic can. Fell back to `createIssueLink` with "Relates" (each child -> SCRUM-182)
plus "Blocks" links encoding the actual dependency graph between the 8 sub-tickets (TT-10.1 blocks
10.2/10.4/10.6, TT-10.3 blocks 10.5/10.7/10.8, etc.).

**Why**: recorded so a future `/start-feature` umbrella-ticket split in this project checks the
parent issue's type first -- "Feature"-typed umbrella tickets (this project's apparent convention
for a broad, not-yet-split ticket, distinct from a true Jira Epic) need the Relates/Blocks fallback,
not the `parent` field, and that fallback should be applied from the start rather than discovered by
trial and error each time.

**How to apply**: before calling `createJiraIssue` with a `parent`, confirm the parent's
`issuetype.hierarchyLevel` is actually above the children's (i.e. it's a real Epic) via
`getJiraIssue`. If it's the same level, plan to link via `createIssueLink` instead.

---

## 2026-08-31 -- SCRUM-185/TT-10.3: bundled a pre-existing z-index bugfix, iterated UI design live

**Decision 1**: while implementing the shared `ImageUploadField` component, browser QA revealed the
counsellor "update images" edit button (`Show.vue`) was completely unclickable via a real click --
the cover's gradient overlay div (added later in DOM order, `position: absolute inset-0`, no
`pointer-events-none`) was intercepting it, because its container was missing the `z-[1]` class that
an identical sibling button elsewhere in the same file already has. Verified this was pre-existing
(reverted the refactor and confirmed the button was already broken before this PR) rather than
something introduced by the refactor. Fixed it in the same PR rather than filing a separate ticket,
since it's a one-line change directly blocking verification of the very feature being shipped
(TT-10.3's whole point is to make this modal's UI better -- shipping a nicer UI nobody could
actually open would be pointless), following the same "bundle a trivial, directly-connected fix"
precedent as the `EnsureOrganizationExistsAction` dead-code removal during SCRUM-179.

**Decision 2**: the initial implementation (plain text buttons in a row below each image preview)
was functionally complete and reviewer-approved, but the user found it visually unappealing and
asked directly whether the counsellor upload button was well-placed. Rather than presenting options
and waiting, iterated live in the same turn to a hover-reveal overlay pattern (camera icon fades in
over the image on hover, small remove/restore badge in the corner, persistent text-link below for
touch/keyboard discoverability) -- closer to the "familiar, appealing" bar a mental-health platform
should meet, and reused the existing `CameraIcon.vue` icon rather than introducing a new asset.

**Why**: recorded because (a) it's the second time this session a "reviewer approved" checkpoint
was revisited after direct user feedback on something the review process doesn't evaluate (visual
appeal, not correctness) -- approval on correctness/security doesn't preclude a later design
iteration; (b) the z-index bug was caught only because browser QA was actually performed against a
real click rather than trusting the component's logic in isolation, reinforcing why Playwright
verification (not just `npm run build`) matters for UI tickets even when "no JS test framework"
means there's no automated substitute.

**How to apply**: for future TT-10 frontend tickets (org logo, user avatar), consult this decision
before re-litigating the visual design -- the hover-overlay pattern in `ImageUploadField.vue` is now
the established look; deviating from it for a new upload surface would reintroduce the "three
bespoke implementations" problem TT-10.3 exists to close.

---

## 2026-08-31 -- SCRUM-186/TT-10.4: repeated the TT-10.2-mandated audit for Organization::logoFile()

Per TT-10.2's decision log entry, did the same bulk-listing eager-load audit for `Organization`
before considering this ticket done: grepped every `Organization`-related resource for a `logo`/
`logoUrl` read. Result: only `OrganizationResource` (single-model only, never `::collection()`)
and `OrganizationDirectoryResource` (fed by `GetOrganizationDirectoryAction`, which already
eager-loaded the old `logo` belongsTo for its own bulk listing -- just renamed to `logoFile`).
No other Organization resource reads `logo` at all, so unlike Counsellor's avatar/cover (5 call
sites needing fixes), this one had exactly one call site, and it was already correct. No N+1
regression introduced.

Also note (raised independently by both `reviewer` and `security-engineer` on this ticket):
`feature/scrum-184-counsellor-avatar-cover-fileables` (TT-10.2) and this branch are siblings cut
from the same TT-10.1 base commit -- TT-10.2 was reviewed but is **not yet merged** at the time
this ticket was implemented, despite earlier framing in this session describing it as "already
merged." Correctly not stacked on top of the unmerged branch (per CLAUDE.md), so no functional
risk, but whoever merges these two PRs should be aware they're landing the same `withPivotValue`-
on-tagged-`fileables` pattern twice, independently derived rather than one branch building on the
other -- a tweak to the pattern during TT-10.2's PR review won't automatically appear here.
## 2026-08-31 -- SCRUM-184/TT-10.2: withPivotValue vs wherePivotValue, and a class of N+1 the
migration itself introduces

**Finding 1**: `withPivotValue('tag', 'avatar')` is the correct Laravel method for a tagged
`MorphToMany` relation -- it both constrains reads (like `wherePivot`) AND auto-populates that
column on `attach()`/`sync()` writes. The similarly-named `wherePivotValue()` (used in the first
draft, and in TT-10.1's plan-doc description of the pattern) does **not exist** on this Laravel
version's `MorphToMany`/`BelongsToMany` -- calling it doesn't error, it's silently absorbed by
Eloquent's dynamic-where-clause magic (`wherePivotValue('tag','avatar')` → `where('pivot_value',
'avatar')`, a nonsense constraint), so the pivot row is created but its `tag` column stays NULL,
with no exception and no failing validation. Caught only because the new Feature tests asserted
actual DB state (`assertDatabaseHas(..., ['tag' => 'avatar'])`), not just a redirect/200 response.

**Finding 2**: migrating a nullable-FK `belongsTo` (`avatar_id`, which returns null without
querying when the FK is null) onto a `MorphToMany` (which always queries the pivot table when
accessed, since there's no single FK column to check first) silently turns every existing bulk
listing that serializes counsellors via `CounsellorMiniResource`/`StarredCounsellorResource` into
an N+1. One occurrence had an existing dedicated test that caught it immediately
(`OrganizationScopedListsControllerTest`); three more (`CounsellorService::getCounsellors`,
`getLeadingCounsellorsForCurrentMonth`, `getBestCounsellorsForPreviousMonth`, `getRandomCounsellors`)
had no such test and were found by inspection; a fifth (`DiscussionService::getDiscussionCounsellors`)
was found by the `reviewer` subagent doing its own independent search, not by inspection -- it also
had a second, pre-existing, unrelated N+1 on `counsellor.user` that got fixed alongside since it's
the same one-line pattern.

**Why**: recorded because both mistakes are the "no error, just silently wrong/slow" kind that
tests-that-only-check-the-happy-path-response won't catch -- worth remembering for TT-10.4/10.6
(org logo, user avatar), which will introduce their own `logoFile()`/`avatarFile()` `MorphToMany`
relations on `Organization`/`User` respectively: (a) use `withPivotValue()`, never
`wherePivotValue()`; (b) grep for every existing bulk listing that serializes the new model via a
Mini/collection-style resource reading the new attribute, and add the eager load there -- don't
assume "it's null today so it's free" holds once the relation changes shape.

**How to apply**: before considering an org-logo/user-avatar sub-ticket done, repeat the same
audit this ticket did -- grep for `Organization::query()`/`User::query()` (or wherever the
relevant model is queried in bulk) followed by a `::collection(...)` resource call, and confirm
the new tagged relation is in that query's eager load.

**Deferred, not forgotten**: `avatar_id`/`cover_id` remain on the `counsellors` table and in
`Counsellor::$fillable` after this ticket -- nothing reads or writes them anymore, but dropping
the columns is intentionally left for a later cleanup migration (after a verified backfill, per
the original TT-10 plan), not an oversight. The `security-engineer`'s suggestion to wrap
`UpdateCounsellorAction`'s read-old/sync/delete-old sequence in a DB transaction (to fail
gracefully instead of orphaning a file on a concurrent double-submit) was also deliberately
deferred -- explicitly flagged as optional/non-blocking, narrow in impact (requires the same
counsellor double-submitting within the same request window), and not worth the added complexity
in this ticket; worth revisiting if it's ever seen in practice.

---

## 2026-08-31 -- SCRUM-189/TT-10.7: auto-submit design choice, and a real race condition it
introduced

**Decision**: unlike `UpdateOrganizationForm.vue`/`UpdateCounsellorImages.vue` (which bundle
avatar/logo changes into one combined submit alongside other text fields, needing an explicit
"save" click), `UpdateAvatarForm.vue` auto-submits as soon as a file is picked or the remove
badge is clicked. This is deliberate, not an inconsistency: user avatar has its own dedicated,
decoupled backend endpoint (`POST /profile/avatar`), so there is no "other field" to batch the
save with -- requiring an explicit save click for a single-control change would be pure friction.
`reviewer` confirmed this reasoning and confirmed `form.reset('avatar', 'deleteAvatar')` in
`onSuccess` can't re-trigger the watchers into an infinite loop (they only fire on a truthy
value, and reset sets both back to falsy).

**Bug caught by `reviewer` (required fix, applied before merge)**: auto-submit-on-watch, with no
guard against overlapping requests, created a real correctness bug: click remove -> a POST fires
-> before its response returns (and updates `existingUrl`), click restore -> `removed` flips back
to `false`, but the watcher only calls `submit()` on a *truthy* value, so no compensating request
is sent. The in-flight delete completes anyway, silently overriding the user's restore with no
error shown -- a genuine "undo did nothing" data-loss edge case, not a style nit. Every other
form using `ImageUploadField` relied on its *own* submit button's `:disabled="form.processing"`
to prevent this same class of race, but that disable state never reached into the shared
widget's own camera/remove-restore controls -- so the other two consumers had the identical
latent gap, just harder to trigger without an auto-submitting parent.

**Fix**: added a `disabled` prop to `ImageUploadField.vue` (default `false`, so the two
already-shipped consumers -- counsellor avatar/cover, org logo -- are unaffected unless updated
to pass it) that disables the camera button, remove/restore badge, persistent text-link, and the
underlying file input, all wired to `form.processing`. Verified the fix closes the actual window,
not just in theory: checked `document.querySelector('#user-avatar').disabled` immediately after
clicking remove (before `await`ing anything further) and confirmed it read `true` mid-flight,
`false` again once the request resolved. Also wired `:disabled="loading"` into
`UpdateCounsellorImages.vue`'s two existing usages while touching the shared component, since it
was a one-line fix for the identical latent gap there. `UpdateOrganizationForm.vue` (TT-10.5) is
a separate, not-yet-merged sibling branch and could not be updated from here without stacking on
unmerged work -- **flagged as a fast-follow**: once both TT-10.5 and TT-10.7 land, add
`:disabled="form.processing"` to TT-10.5's `ImageUploadField` usage too, for the same reason.

**Also fixed** (found via the same Playwright pass, same root cause as SCRUM-187/TT-10.5's
findings): the persistent "add/change {label}" text-link and rect-shape empty-state text were
nearly illegible against `Profile/Show.vue`'s dark blue-to-indigo gradient hero -- the shared
component's hardcoded `text-gray-600`/`text-gray-400` assumed every placement has a light
background, which held for the previous two consumers but not this one. Added a `dark` Boolean
prop (default `false`) rather than a broader `theme`/`variant` prop -- `reviewer` agreed a boolean
is the right level of generalization for exactly two contexts today, not worth speculatively
building out an enum ahead of a third actual placement.

**Why**: recorded because this is the second ticket in a row (after TT-10.5) where a shared
component's implicit assumption (there, a Tailwind safelist; here, a light background and
non-overlapping requests) only broke because a NEW consumer's context differed from the ones the
assumption was written against -- worth remembering that "it works for the existing callers"
isn't the same guarantee as "it's safe for the next one," especially for a component explicitly
designed to be reused across dissimilar surfaces.
## 2026-08-31 -- SCRUM-187/TT-10.5: three real bugs invisible to Pest, only caught by actually
using the feature in a browser

Implementing the org logo frontend (wiring the already-reviewed `ImageUploadField`/backend into
`UpdateOrganizationForm.vue`) surfaced three genuine, previously-shipped bugs, none of which the
existing (passing) Pest suite could have caught -- each for a different structural reason:

1. **PATCH + multipart file upload silently drops the file.** `UpdateOrganizationForm.vue`
   submitted via `form.patch(...)`, a real HTTP PATCH. PHP never populates `$_FILES` for a
   multipart body on a non-POST verb (a `$_FILES`-level PHP limitation, not a Laravel bug) --
   `$request->file('logo')` just returned null, no exception, no validation error.
   `tests/Feature/UpdateOrganizationLogoTest.php` (SCRUM-186) passed throughout, because Laravel's
   `$this->patch(...)` test helper builds the Symfony `Request` with files already populated,
   bypassing the real wire-level parsing entirely. Fixed with Inertia's documented pattern:
   `form.transform(data => ({...data, _method: 'patch'})).post(...)` -- routes/authorizes/
   validates identically (method-override happens before routing), only the transport verb and
   file-parsing differ.
2. **A dynamic Tailwind arbitrary-value class is invisible to Tailwind's JIT scanner.**
   `Avatar.vue` built its sizing via `w-[${props.size}px]` (a runtime-interpolated template
   string). Tailwind can only generate CSS for class strings its static source scanner can see;
   a size not already present verbatim somewhere in scanned source (the safelist hack in
   `AuthenticatedLayout.vue`, six specific pre-approved sizes) silently gets zero CSS, rendering
   the element at unconstrained native size. Hit with `size={64}` for the new dashboard-header
   logo. Fixed at the root by switching `Avatar.vue` to an inline `:style` binding, which has no
   static-scanning requirement -- works for any size, permanently closing this class of bug for
   every current and future caller. The now-dead safelist div was removed in the same PR
   (confirmed via grep it was the only consumer).
3. **A new upload path needs its own storage symlink entry, and nothing enforces that.** This
   project links each upload subdirectory individually via `config/filesystems.php`'s `links`
   array (not Laravel's default single symlink) -- `docker/php/entrypoint.sh` re-runs
   `storage:link --force` off that array on every boot. TT-10.4's backend chose `'path' =>
   'logos'` but never added a matching `links` entry, so uploads saved correctly but every logo
   URL 404'd. Fixed by adding the entry, and by adding
   `tests/Unit/FilesystemLinksCoverageTest.php` -- a Pest test that scans `app/Actions`/
   `app/Services` for every `'path' => '...'` literal and asserts each has a matching
   `filesystems.links` entry. Verified this test actually catches the regression (temporarily
   removed the `logos` entry, confirmed the test fails, restored it).

**Why**: two structurally distinct "invisible to automated tests" categories, worth keeping
separate in mind rather than lumping together as "need more tests": (1) and (3) are invisible to
Pest specifically because Laravel's test client / `Storage::fake()` bypass the real PHP
request-parsing and real symlink layers respectively -- no amount of *better-written* Pest tests
closes that gap; only real HTTP + real filesystem integration testing (i.e. actually using the
feature in a browser, which CLAUDE.md already mandates for UI changes) catches them. (3) alone
happened to also be closeable by a pure-PHP config-reflection test once the pattern was known,
which is why that one got a permanent regression test and the other two didn't (their tests would
just re-pass identically on the old, broken code). (2) is invisible because there is no frontend
test framework in this repo at all (no Vitest/Jest) -- a separate, pre-existing gap, not
introduced or closed here.

**How to apply**: for TT-10.7 (user avatar frontend, the last ticket reusing these same shared
components) and any future upload surface: (a) a real Playwright upload-and-verify-the-URL-
renders pass is required, not optional, exactly as CLAUDE.md's playwright-qa policy already says
for full-ceremony UI work -- this session is the concrete example of why; (b) any new
`FileUploadDTO` `path` value will be automatically caught by `FilesystemLinksCoverageTest` if a
matching `filesystems.links` entry is missing, no manual reminder needed; (c) `Avatar.vue`'s
sizing is now safe for any `size` value, no safelist coordination needed.

**Follow-up ticket filed**: `security-engineer`'s review of the `filesystems.php` `links` array
noted, in passing, that the pre-existing `licenses` folder is *also* publicly symlinked (same
pattern, unrelated to this PR) -- if it holds counsellor verification/license documents, those
are being served from a public, unauthenticated URL today. Filed as SCRUM-191 to confirm intent,
since this predates TT-10 entirely and wasn't introduced by it.

---

## 2026-08-31 -- SCRUM-190/TT-10.8: shared validation limits, client-error precedence bug, and a
throttling gap deliberately deferred

**What shipped**: `App\Support\ImageUploadRules` as the single source of truth for the
size (2MB) / MIME (jpg, jpeg, png, webp) limits enforced on all three image-upload endpoints
(counsellor avatar/cover, org logo, user avatar), replacing the old bare `['nullable', 'file']`
rule that had shipped with zero size/type enforcement since TT-10.2/10.4/10.6. Mirrored on the
frontend via `resources/js/Constants/imageUploadLimits.js` and a pre-submit check in
`ImageUploadField.vue::onFileSelected()`, so a bad file is rejected instantly client-side instead
of round-tripping to the server first -- purely a UX convenience, the `FormRequest` rule is the
actual enforcement (both `reviewer` and `security-engineer` independently confirmed the two are
not conflated anywhere, and each of the three `FormRequest`s now carries a comment saying so
explicitly per `security-engineer`'s suggestion).

**Bug caught by `reviewer`, fixed before commit**: `ImageUploadField.vue`'s `displayError`
computed originally read `props.error || clientError.value` -- since `props.error` (the server's
last-submission error) is never cleared on a fresh file pick, a stale server error from an
earlier submission would mask a *new*, more relevant client-side rejection message. Fixed by
flipping precedence to `clientError.value || props.error`. Also cleared `clientError` in
`toggleRemoveOrRestore()` so a lingering rejection message doesn't persist after the user chooses
to remove/restore an existing image instead of retrying the upload (a `reviewer` "suggested
improvement", applied since it was a one-line fix for a real, if minor, UX papercut).

**`security-engineer` confirmed, no action needed**: Laravel's `mimes:` rule content-sniffs via
`finfo`/Symfony's `MimeTypes` (not the client-declared `Content-Type` or filename extension), and
the file's *stored* name/extension is independently derived from that same sniffed value via
`UploadedFile::hashName()` -- so a renamed-extension attack (`shell.php.jpg`) fails both at
validation and at storage time. Deliberately excluding `svg` from `ALLOWED_MIMES` (rather than
using Laravel's broader `image` rule) was confirmed to close the classic stored-XSS-via-SVG
vector, not just narrow the format list arbitrarily.

**Test coverage gap, closed before commit**: both `reviewer` and `security-engineer`
independently flagged the same issue -- the existing Feature tests for all three upload endpoints
only ever exercised the happy path (`UploadedFile::fake()->image(...)`), so the 815-tests-passing
baseline provided zero regression protection for the very validation this ticket added (a typo in
`ImageUploadRules::rules()` or a `FormRequest` not actually wiring it up would have gone
undetected). Added an oversized-file case and a disallowed-MIME case to each of
`UpdateCounsellorImagesTest`, `UpdateOrganizationLogoTest`, and `UpdateUserAvatarTest`, plus a
direct `Unit` test asserting `ImageUploadRules::rules()`'s exact return value. 822 tests passing
after the additions.

**Deliberately deferred, filed as SCRUM-192**: none of the three upload routes carry a `throttle:`
middleware (unlike several other mutating routes in `routes/web.php`, e.g. invite/apply/admin
endpoints at `throttle:30,1`). `security-engineer` assessed this as a real but low-severity,
availability-only concern (repeated cheap disk I/O from re-uploading a ~2-4MB payload, not
unbounded storage growth since old files are deleted on replace) and explicitly recommended a
follow-up ticket rather than blocking this ticket on it, since TT-10.8's scope is validation, not
rate-limiting. Filed as a standalone Task (SCRUM-192) rather than folded into this ticket.

**Merge note**: this branch was cut before TT-10.5 (SCRUM-187) and TT-10.7 (SCRUM-189) merged into
`develop`, both of which also touched `ImageUploadField.vue` (adding the `dark`/`disabled` props
and their template wiring). Merging `develop` into this branch produced one real conflict in
`toggleRemoveOrRestore()` -- TT-10.7's `if (props.disabled) return` guard vs. this ticket's
`clientError.value = ''` reset -- resolved by keeping both, guard first: a disabled field
shouldn't clear a client error it can't currently produce, but the reset is still needed for the
non-disabled path.

---

## 2026-09-01 -- SCRUM-196/TT-2.2a: cascadeOnDelete would have let a scheduled job silently
destroy clinical audit records

**What happened**: initial `session_notes` migration used `constrained()->cascadeOnDelete()` on
both `session_id` and `counsellor_id`. `security-engineer` review caught that this was a live,
scheduled data-loss path, not a theoretical one: `AppService::purgeExpiredSoftDeletedCounsellors()`
(run on a schedule per `routes/console.php`) force-deletes a `Counsellor` row 60 days after
account deletion, and that method's own comment states the deliberate house convention --
"related historical records... are left untouched." `cascadeOnDelete()` would have silently,
permanently destroyed every session note that counsellor ever authored the very next time that
job ran, directly defeating the whole reason this ticket chose soft-deletes in the first place
(an auditable clinical record). `session_id`'s cascade carried the same latent risk, just with no
current code path force-deleting a `Session` to trigger it today.

**Fix**: switched both FKs to `nullable()->constrained()->nullOnDelete()`, mirroring the exact
precedent already set by `2026_08_29_600000_add_organization_id_to_transactions_table.php`
(`transactions.organization_id`, chosen for the identical reason: a soft-deletable parent whose
force-deletion must not destroy a historical record that references it). Added `withTrashed()` to
`SessionNote::session()`/`counsellor()` (mirroring `Message::from()`/`to()`) so a note's author/
session still resolves correctly while merely soft-deleted, and two regression tests
(`SessionNoteModelTest`) that actually force-delete a counsellor/session and assert the note
survives with a nulled reference rather than trusting the migration's intent alone.

**Why recorded**: this is the second time in this project's history (after `transactions.
organization_id`) that `cascadeOnDelete()` was the wrong default for a soft-deletable parent with
a scheduled force-delete job behind it -- worth remembering as a standing rule for TT-2.2b/c and
any future FK to `Counsellor`/`Organization`/any other soft-deletable model with a purge job:
default to `nullOnDelete()` (nullable column) unless there's a specific reason the child record's
existence is meaningless without its parent, not `cascadeOnDelete()` by habit.

**Also flagged, deferred to TT-2.2b (not fixed here, correctly out of this ticket's scope)**:
`SessionNote::$fillable` includes `counsellor_id`, which is fine at the model layer with no
controller yet, but TT-2.2b's controller/action must derive it server-side from the authenticated
counsellor and never accept it from client input -- otherwise a counsellor could author a note
attributed to a different counsellor. Left a comment on the model pointing at this for whoever
picks up TT-2.2b.

---

## 2026-09-01 -- SCRUM-197/TT-2.2b: a grace window derived from a freely-rewritable column isn't
a grace window

**What happened**: `GuardsPrivateNoteEditWindow::sessionAcceptsNoteEdits()` originally derived
"how long since this session ended" from `Session::updated_at`. Both `reviewer` and
`security-engineer`, independently, caught the same hole: `updated_at` gets re-touched by
`/sessions/{id}/in_session`, `/end`, `/fail`, and `/abandon` -- none of which are idempotent
against an already-terminal session (`EnsureCanUpdateSessionStatusAction` only checks
`isParticipant()`; `EnsureCanEndSessionAction` only checks `now() > end_time`; neither checks the
session isn't already in that state). Concretely: a counsellor (or, via `/in_session`, even the
client on a `Therapy`) could replay any of these endpoints on an already-ended session to either
reset the grace-window clock indefinitely or flip status back to something
`Session::scopeWhereInSession` treats as "live," reopening a note's editability at will --
defeating the one guarantee TT-2.2b exists to provide ("a permanent, immutable part of the
clinical record" once the grace window elapses).

**Fix**: added `sessions.ended_at` (migration
`2026_09_01_200000_add_ended_at_to_sessions_table.php`), set exactly once by
`ChangeSessionStatusAction` the first time a session's *final* resolved status is `HELD`/
`FAILED`/`ABANDONED` (not the `HELD_CONFIRMATION` intermediate), and never touched again on any
later call regardless of how many times a status endpoint gets replayed.
`GuardsPrivateNoteEditWindow::sessionAcceptsNoteEdits()` now checks `ended_at` FIRST, and once
it's set, that value takes **permanent priority** over the session's current live-looking status
-- deliberately, so replaying `/in_session` to flip status back to `in_session_confirmation`
can't resurrect an already-locked note. Added a regression test
(`replaying a session status-transition endpoint does not reset or extend the note edit grace
window`) that calls the real `/sessions/{id}/abandon` endpoint twice with a backdated `ended_at`
in between and asserts the second call doesn't move it.

**Why recorded**: this is the second time in this epic (after TT-2.2a's `cascadeOnDelete`
finding) that a security-relevant guarantee was built on a column another, unrelated part of the
app is already free to mutate for its own reasons. Worth remembering as a standing check for any
future "N minutes/days since X happened" access-control window: confirm the timestamp it's
anchored to is written exactly once for that purpose, not reused from a general-purpose
`created_at`/`updated_at` that something else already touches routinely.

**Other decisions from this ticket's review, recorded here since two code comments referenced
this log without an entry existing yet (`reviewer` caught this)**:
- No `isAdmin()` bypass anywhere in `App\Actions\SessionNote\Ensure*Action` -- a deliberate,
  explicit divergence from every other `Ensure*Action` in this codebase (all of which give admins
  an unconditional bypass), confirmed with the user during `/start-feature` planning (2026-09-01):
  session notes are clinical content, not operational state, and a platform admin has no read
  access to their content.
- `SessionNoteResource` was pulled forward from its originally-planned home in TT-2.2c, since
  `SessionNoteController` needed *some* response shape to be testable at the HTTP level at all.
  Kept deliberately minimal (id/content/createdAt/updatedAt) and structurally separate from
  `SessionResource`, matching the "never leaks to the client" invariant established in TT-2.2a.
- Also fixed in this pass (both `reviewer` findings, not security-relevant): a wrong ticket
  reference in `SessionNoteService`'s docblock (`SCRUM-182`, the unrelated file-uploads epic, was
  copy-pasted instead of `SCRUM-21`), and a `max:5000` length rule added to both
  `CreateSessionNoteRequest`/`UpdateSessionNoteRequest` (`content` had no length bound at all).

---

## 2026-09-01 -- SCRUM-198/TT-2.2c: a resource collection's wire shape depends on process history,
not just its own code -- and the fastest way to catch that is to actually load the page

**What happened**: `SessionNoteController::index()` originally returned
`SessionNoteResource::collection($notes)` directly. Every `SessionNoteTest.php` list-endpoint
assertion (`$response->json()`, expecting a bare array) passed the entire time this was true.
Manually driving the real feature in a browser (creating/editing/deleting a note as
`sarah_johnson` on a live seeded session) surfaced a phantom note row with "Invalid Date" and
"locked" -- the actual HTTP response was `{"data": [...]}`, not a bare array, and my Vue code's
`notes.value = res.data` had assigned the whole `{data: [...]}` object to `notes.value`, which
Vue's `v-for` then iterated as a plain object (one "item" per own-enumerable property -- here,
exactly one: the `data` key itself, itself an array, producing one nonsense row with every field
undefined).

**Root cause**: `Illuminate\Http\Resources\Json\JsonResource::$wrap` is a process-wide *mutable
static*, not fixed at `'data'`. `HandleInertiaRequestsV2` calls `$userResource->withoutWrapping()`
on every Inertia page load in this app, which -- because subclasses don't redeclare `$wrap`, so
every `JsonResource` subclass shares the same underlying storage -- disables wrapping for
*every* resource for the rest of that PHP process's lifetime. Pest runs an entire test file (often
many files, under `--parallel`) inside one shared PHP process per worker, so once any earlier test
in that process rendered an Inertia page, wrapping stayed disabled for every later test in the
same process, including ones that never touch Inertia at all. A real browser request to this
JSON-only `api.php` endpoint (no Inertia middleware in its pipeline) never had wrapping disabled,
so it defaulted to wrapped -- the two environments silently disagreed, and the test suite had no
way to catch it because it was internally consistent with itself.

**Fix**: `index()` now explicitly returns `response()->json(['notes' => SessionNoteResource::
collection($notes)])`, matching `store`/`update`/`destroy`'s existing `['note' => ...]` shape.
Confirmed via reading Laravel's own resource internals (not just re-testing) that nesting a
`ResourceCollection` inside a plain array and passing it through `response()->json()` invokes
`jsonSerialize()` -> `resolve()` directly, which never consults `$wrap` at all -- so this fix is
immune to the same class of bug, not just a different flavor of it. All list-assertions in
`SessionNoteTest.php` updated from `$response->json()` to `$response->json('notes')` and re-verified
passing in isolation (a fresh process, not benefiting from any other test's side effects).

**Why recorded**: a controller method that "returns a resource/collection directly" is idiomatic,
commonly-recommended Laravel style, and was completely correct as *written* -- the bug lived
entirely in an ambient, cross-cutting static someone else's code toggles, not in this method's own
logic, which is exactly the kind of thing a code review reading this file in isolation cannot
catch. Standing rule for this codebase going forward: any *new* JSON-only endpoint (not already
proven by an existing analogous controller) should explicitly wrap its top-level response key
rather than relying on `JsonResource`'s default wrapping behavior, precisely because that default
is not actually a per-request constant here.

**Second, smaller discovery**: `TherapyComponent.vue`'s `computedSelectedItem` (used nearby for
the topic-filter browsing UI) is `null` in the ordinary "already in an active session, no topic
picked" case -- `selectedSession` (a separate ref, kept live by an existing `watchEffect`) is the
one that actually reflects "the session currently being viewed," and is what `SessionNotesPanel`
binds to. Caught the same way: the panel silently never rendered at all until this was fixed,
which no unit/feature test could have caught since it's a frontend-only reactive-binding mistake.

## 2026-09-02 — SCRUM-23 (TT-2.4): cap-setter is the discussion's own creator, race condition fixed inline

**Decision**: `Discussion.max_counsellors` can be set/changed only through the existing
`EnsureCanUpdateDiscussionAction` gate (the discussion's own `addedby`, or a platform admin as
that action's existing bypass already allows) -- no new `isAdmin()`-only restriction was added.
Also, per architect review, fixed a flagged race condition inline rather than deferring it: both
counsellor-attach call sites (`RespondToDiscussionRequestAction`, `PerformDiscussionRequestLinkAction`)
now additionally lock the `Discussion` row itself (`lockForUpdate()`) inside their existing
transactions, in addition to the Request/Link row each already locked for its own prior race fix
(SCRUM-91/SCRUM-101).

**Why**: the ticket's "Admin can cap..." wording was ambiguous between a platform Administrator
and a discussion's own creator/owner. Presented to the user as an explicit fork; the user chose
the creator/existing-update-gate reading (matches how every other discussion field is already
gated). The race condition -- two concurrent accepts on the same discussion each locking a
*different* row (their own Request or Link) and both passing a stale pre-attach count check --
was flagged by architect as reintroducing the exact class of bug SCRUM-91/101 already fixed for a
different pair of rows; since the transaction/lock scaffolding already existed in both files,
adding one more `lockForUpdate()` call was small enough to just do now rather than file as a
follow-up.

---

## 2026-09-02 — SCRUM-23: security review found the race-condition fix was actually ineffective

**Decision**: fixed a real, empirically-verified gap in the earlier `lockForUpdate()` fix for
`RespondToDiscussionRequestAction`. That fix locked the `Discussion` row before the capacity
count, but a redundant `$request->refresh()` a few lines earlier was a plain (non-locking) SELECT
-- the first one in that transaction -- which, under MySQL InnoDB's default REPEATABLE-READ
isolation, pins the transaction's consistent-read snapshot for every *later* plain read in the
same transaction, including the capacity count. Locking the Discussion row still correctly forced
two concurrent accepts to serialize, but the second transaction's capacity count then read its own
stale, pre-first-commit snapshot anyway, silently defeating the cap. `security-engineer`
reproduced this against a real MySQL connection (not just reasoned about it) before reporting it.

Fixed by (1) removing the redundant `refresh()` (an already-`update()`d model doesn't need a
re-fetch) and (2) making the capacity count and both "already attached" exists() checks
(`RespondToDiscussionRequestAction`, `PerformDiscussionRequestLinkAction`) into `lockForUpdate()`
reads themselves, so they're correct independent of whatever else happens earlier in either
transaction, rather than relying on a fragile statement-ordering coincidence.

**Also fixed**: added an upper ceiling (`env('DISCUSSION_MAX_COUNSELLORS_CEILING', 50)`,
independent of the coincidentally-same-named `GroupTherapy.max_counsellors` feature/ceiling) so an
out-of-range value gets a clean 422 instead of an uncaught `QueryException`/500 on the
`unsignedInteger` column, plus the matching `CreateDiscussionRequest` validation rule.

**Filed, not fixed here**: `DiscussionService::getDiscussionCounsellors()` has no participant/
admin check at all (pre-existing, unrelated to this diff) -- any authenticated user can read any
discussion's counsellor roster/count. Filed as SCRUM-204 rather than expanding this PR, since it's
a pre-existing gap this ticket didn't introduce and doesn't itself expose `max_counsellors`.

**Why recorded**: this is the clearest example so far of a subagent's finding needing empirical
verification to be trusted (per the session's established SCRUM-125 discipline) -- but also the
inverse lesson: a subagent claiming a fix *doesn't* work, backed by an actual reproduction against
the real database engine (not just plausible-sounding reasoning), is exactly the kind of finding
that must be taken at face value and fixed immediately, not dismissed as theoretical. A true
concurrent-transaction regression test isn't feasible in this suite's harness (Pest runs against
SQLite `:memory:`, which has neither MySQL's REPEATABLE-READ semantics nor genuinely concurrent
connections against the same in-memory database) -- noted here explicitly rather than adding a
test that would give false confidence.
---

## 2026-09-01 — SCRUM-22 (TT-2.3): message-note editability diverges from the documented reuse plan

**Decision**: message-level notes (`MessageNote`, attached to a single chat `Message`) are
editable/deletable by their author indefinitely, in every chat context (individual therapy, group
therapy, and counsellor discussions) -- **not** gated by `GuardsPrivateNoteEditWindow`'s
live-session-plus-grace-period rule, despite that trait's own docblock (written during SCRUM-197)
explicitly naming TT-2.3 as its intended second consumer, and `documentation/implementation_plan.md`'s
TT-2.2b row stating the same intent.

**Why**: this reverses a documented plan, so it was presented to the user as an explicit fork
(reuse the grace window for Session-owned messages / drop it everywhere) rather than assumed either
way, per the autonomous-execution policy's carve-out for a genuinely consequential product decision.
The user chose indefinite editability everywhere. Consequence: `MessageNote`'s authorization actions
do **not** depend on `GuardsPrivateNoteEditWindow` at all -- author-only check plus participant-of-
`$message->for` check are sufficient, no `ended_at`/grace-window logic needed. This also sidesteps the
`Discussion`-has-no-`ended_at` asymmetry the product-owner pass flagged, uniformly, rather than only
for the `Discussion` branch.

---

## 2026-09-01 — SCRUM-202 (TT-2.3a): `MessageNoteResource` built now, not deferred to SCRUM-203

**Decision**: `app/Http/Resources/MessageNoteResource.php` (id/content/createdAt/updatedAt) was
created as part of SCRUM-202, even though the sub-ticket split assigned "resource" to SCRUM-203
("message note resource + UI").

**Why**: `MessageNoteController`'s store/update/destroy/index actions need to return *something* to
the caller -- `SessionNoteController`'s own precedent (SCRUM-197) already built `SessionNoteResource`
as part of the CRUD ticket, with only eager-loading + the `isEditable` field + UI landing in the
follow-up ticket (SCRUM-198/TT-2.2c). This ticket's split description undersized that same detail;
corrected the same way rather than leaving the CRUD ticket unable to return a usable response.
SCRUM-203 still owns eager-loading this resource into a message-list response and building the
`MessageBadge.vue` UI affordance.

**Also**: message-note routes are registered in `routes/api.php` only, not duplicated into
`routes/web.php` the way SCRUM-197 originally did for session notes -- SCRUM-200 already tracks that
web.php registration as an orphaned duplicate the frontend never actually calls (the UI uses axios
against the api.php routes exclusively). Registering only where the feature will actually be used
avoids repeating that same dead-code pattern here.

---

## 2026-09-02 — SCRUM-202 (TT-2.3a): fixed a soft-delete/unique-index conflict found in security review

**Decision**: `CreateMessageNoteAction` now checks for a trashed `MessageNote` on the same
`(message_id, counsellor_id)` pair and restores + updates it, instead of always inserting a new
row.

**Why**: security-engineer found that `message_notes`' unique index on `(message_id,
counsellor_id)` still counts soft-deleted rows (Eloquent's default query scope excludes them from
`EnsureCanCreateMessageNoteAction`'s duplicate check, but the DB constraint itself does not
exclude them from a fresh `INSERT`). A real, likely sequence -- create a note, delete it, add a
new one -- would pass the app-level duplicate check and then fail with a raw, uncoded
`QueryException` on the unique constraint, surfacing as a generic 500 rather than the intended
clean flow. Restoring the trashed row is also the more correct semantics here: at most one note
has ever existed for a given (message, counsellor) pair, live or trashed, so recreating it is
really un-deleting it with new content, not creating a logically-second note. Added a regression
test exercising exactly this create-delete-recreate sequence, plus a co-counsellor IDOR regression
test for update/delete (security-engineer recommendation, closing a coverage gap on an already-
correct implementation).

---

## 2026-09-02 — SCRUM-206 (TT-2.5a): review found a dangling-request bug and a PII leak, both fixed

**Decision**: `reviewer` and `security-engineer` independently found the same real bug --
`EnsureCanProposeSessionScheduleAction` didn't check that the Therapy actually had an assigned
counsellor before allowing a proposal. Since `Therapy::isParticipant()` returns true for the
client (`addedby`) regardless of whether a counsellor exists yet, a client could propose on a
still-unmatched therapy, `ProposeSessionScheduleAction` would then persist a Request with `to =
null` (no `to`/`to_type` to associate), and `EnsureNoPendingSessionScheduleProposalAction` would
permanently block any future *legitimate* proposal for that therapy (no reject/cancel or expiry
sweep exists yet -- both are TT-2.5b's job). Fixed using the existing `Therapy::doesNotHaveAssistance()`
helper rather than inventing a new check.

`security-engineer` additionally found (a) the "not a participant" rejection message leaked the
therapy's own name to a non-participant -- any authenticated user can hit this endpoint with any
sequential `therapyId`, so this is a real, enumerable PII leak for a private/anonymous therapy,
same class as the SCRUM-124/162 findings already fixed elsewhere in this codebase; fixed by using
a generic message for that specific branch only (the other branches in the same action only fire
once participancy is already confirmed, so they safely keep the therapy's name); and (b) the
`Therapy::lockForUpdate()` call inside the propose transaction discarded its own result and kept
using the pre-lock, potentially-stale `$dto->therapy` for authorization and `from`/`to`
resolution -- fixed by reassigning `$dto->therapy` to the freshly-locked row and moving all
checks (not just the "no pending proposal" one) to run against it, inside the transaction.

`reviewer` also flagged a duplicated `EnsureTherapyExistsAction` (a fourth copy of an action this
codebase already has one canonical, extensible version of, used by three other services) --
deleted the new one and extended the canonical `App\Actions\Therapy\EnsureTherapyExistsAction`'s
DTO union type instead. `type`/`paymentType` validation was tightened to match the sibling
`CreateSessionRequest`'s enum whitelist (this data is persisted into `requests.data` and is what
TT-2.5b's accept step will eventually feed into `CreateSessionAction`), `expiryDays` gained the
same `between:1,30` bound as the compensation-negotiation precedent, and the route gained
`throttle:30,1` matching sibling mutation routes.

Filed SCRUM-210 for the identical, pre-existing PII-leak pattern in `EnsureCanCreateSessionAction`
(a different, older ticket's code) rather than fixing it inline.

**Why recorded**: this is the third time this session a security review has found a real,
previously-unflagged bug during implementation of a new feature that reuses an established
pattern (after SCRUM-198's `selectedSession`/wrapping bugs and SCRUM-23's REPEATABLE-READ
staleness) -- worth continuing to budget for a real security pass on every new feature that
touches authorization or concurrency, not just ones that look novel.
## 2026-09-02 — SCRUM-203 (TT-2.3b): message note UI wired into MessageBadge.vue, live-verified

**Decision**: added the inline note affordance (add/view/edit/delete) to `resources/js/Components/MessageBadge.vue`, the single shared rendering point for both `TherapyComponent.vue` (individual/group therapy chat) and `resources/js/Pages/Discussion/Chat.vue` (discussion chat), per the architect's SCRUM-22 planning confirmation that no second component tree exists. Gated behind a new `isCounsellor` prop (passed through from each page's own existing counsellor-detection), and further gated per-message on `!reply` so the compact reply-preview instances of `MessageBadge` never render it.

`MessageResource` gained a `note` field, populated only when the caller (`MessageService::getSessionMessages`/`getDiscussionMessages`) explicitly eager-loads `notes` scoped to the requesting counsellor's own id -- omitted entirely (Laravel's `MissingValue`) for a client viewer, so their message list never even queries `message_notes`.

**Verified live via Playwright** (not just the automated suite): logged in as `sarah_johnson` on the seeded `/therapies/6/chat`, created a real message via tinker, added a note through the actual UI, reloaded the page and confirmed the note persisted (via the eager-load, not just local component state), then edited/deleted it through the UI. A recurring Playwright quirk from this session reappeared: ref-based `browser_click` on the note toggle/save/delete controls didn't register, while a native `element.click()` via `browser_evaluate` did -- consistent with prior notes on this in earlier tickets this session.

**Found and filed, not fixed here**: writing the N+1 regression test for the new `notes` eager-load surfaced a genuine, pre-existing, unrelated N+1 in `MessageResource` on `$this->files`/`$this->replying` (neither eager-loaded by any of the three message-list methods). Filed as SCRUM-205 rather than expanding this ticket, since it predates this change and the notes eager-load itself is correctly batched (confirmed by scoping the regression test's query count specifically to queries touching `message_notes`, not the total query count).

**Why recorded**: the composer's own send-button click didn't register during manual QA either (traced to the same pre-existing, well-documented Playwright ref-click quirk, not a bug in this PR) -- worth remembering that quirk applies to any click in this codebase's chat UI, not just note-affordance controls, so it shouldn't be mistaken for a real regression on a future ticket.

---

## 2026-09-02 — SCRUM-203: reviewer caught a missed third eager-load site; surfaced an unrelated latent bug

**Decision**: `reviewer` found that the notes eager-load had only been added to `getSessionMessages()`
and `getDiscussionMessages()`, missing the third sibling method, `getTherapyTopicMessages()` --
all three feed the exact same `MessageBadge.vue` rendering path, so a message's own note would
have silently disappeared (and been un-editable) whenever a counsellor switched to the
topic-filtered view. Fixed by adding the identical scoped eager-load to that method too, plus a
regression test.

Writing that regression test surfaced a second, genuinely pre-existing and unrelated bug:
`getTherapyTopicMessages()`'s own `sessionId` filter (`$query->whereSessionId(...)`) resolves to
`where('session_id', ...)`, but `messages` has no such column -- it's a polymorphic
`for_id`/`for_type` model. Confirmed via grep that `TherapyComponent.vue`'s only caller of this
endpoint never sends `sessionId`, so this is latent, not a live 500. Filed as SCRUM-209 rather
than fixing inline (out of scope, and the fix requires a product call on what filtering was
actually intended). The new regression test works around it by acting as an admin (skipping the
branch that would otherwise depend on this same broken filter to resolve `$therapy`), documented
inline so a future reader doesn't mistake the workaround for the thing under test.

**Why recorded**: this is the second time in this epic (after SCRUM-198's `selectedSession`
binding bug) that testing a UI wiring change surfaced a real inconsistency across sibling code
paths that only manual/thorough review catches -- worth remembering that any future field added to
one of these three message-list methods needs to be checked against all three, not just the one
or two call sites a UI change happens to exercise first.

---

## 2026-09-03 — SCRUM-207 (TT-2.5b): "Option C" stale-handling implementation

**Decision**: implemented the user's explicit "Option C" design for accept-time staleness:
`AcceptSessionScheduleProposalAction` re-runs the real `EnsureCanCreateSessionAction`/
`EnsureSessionDataIsValidAction` checks (the same ones `CreateSessionAction` uses) against
*current* data inside a `lockForUpdate()` transaction on both the `Request` and `Therapy` rows.
If a check fails (or the therapy no longer has an assigned counsellor), the request is **not**
auto-rejected and **not** surfaced as a raw error -- it stays `pending` with `data.staleReason`
set, leaving reject / counter-offer / reject-with-a-reason all still available as the counsellor's
three explicit choices, matching the user's own wording verbatim. Per architect review, the
session's actor (`CreateSessionDTO->user`) is always forced to `$therapy->counsellor->user`
regardless of who clicks accept, since `CreateSessionAction` only ever resolves a counsellor or
admin as actor, never a plain client `User`.

**Two retroactive bug fixes to already-merged SCRUM-206 code**, both found by exercising the
accept flow against the real MySQL dev database via `tinker` rather than Pest's SQLite test run
(neither would have been caught by the existing SCRUM-206 test suite, since it never exercised
actual `Session` creation):
1. `requests.type` is a native MySQL enum column; the migration widening it for
   `RequestTypeEnum::sessionScheduleProposal` (added in SCRUM-206) was missed. Added
   `2026_09_03_100000_add_session_schedule_proposal_to_requests_type_enum.php` and migrated the
   dev DB. This is the same class of gap the `RequestTypeEnum` convention explicitly warns about
   (two prior precedents already existed) -- worth flagging since it slipped through review once
   already.
2. `sessions.about` is `NOT NULL`, but `SessionScheduleProposalDTO`/
   `CreateSessionScheduleProposalRequest` never collected it, since SCRUM-206's own scope never
   calls `CreateSessionAction`. Added `about` end-to-end (DTO, form request, both controller
   actions, propose/counter-offer/accept actions) and backfilled the SCRUM-206 test fixtures.

**Other fixes made within this ticket's own diff, not deferred**: the new `counterOffer()`
endpoint was reusing `CreateSessionScheduleProposalRequest`, which makes `about` (and `name`/
`type`/`paymentType`) required -- but `CounterOfferSessionScheduleProposalAction` already falls
back to the current proposal's data for all of them, since a counter-offer is only meant to
require a new time. Split out `CounterOfferSessionScheduleProposalRequest` with those fields
nullable, since this inconsistency was introduced by this ticket's own controller method, not
inherited pre-existing code.

**Found and filed, not fixed here**: writing the "now-stale" accept test surfaced a real gap in
`EnsureSessionDataIsValidAction`'s double-booking check (also used by normal `CreateSessionAction`,
not just this proposal flow) -- it only checks whether the *new* range's start/end (±30min) falls
inside an *existing* session's window, never the reverse, so a new session that fully contains a
shorter existing one goes undetected. Filed as SCRUM-211 rather than fixing inline, since it's
pre-existing shared validation logic outside this ticket's scope; the test itself was adjusted to
use a conflict shape the current check does catch (overlapping start time) so it still exercises
the staleness path Option C depends on.

Also added a generic `reason` field to `RequestResponseDTO` (shared across all `RespondTo*`
actions) to support the "reject with a reason" choice -- every other `RespondTo*RequestAction`
ignores it silently since none of them read it, so this is additive, not a behavior change for
other request types.

**Why recorded**: this is the third ticket in this epic (after SCRUM-23's REPEATABLE-READ bug and
SCRUM-206's dangling-request bug) where a real, previously-invisible production bug only surfaced
via direct testing against the real MySQL dev database -- worth remembering that Pest's SQLite
test runner cannot be trusted alone for anything touching native DB enum columns, NOT NULL
constraints not exercised by existing fixtures, or true transaction-isolation semantics.

---

## 2026-09-01/02 — SCRUM-208 (TT-2.5c): proposal resource + UI, two more retroactive NOT NULL fixes

**Decision**: exposed a session-schedule proposal's negotiation state through a new whitelisted
`RequestResource::proposal` field (mirroring `OrganizationRequestResource`'s `proposedTerms`
precedent exactly -- explicit field list, never a raw `data` spread, `proposedById`/`sessionId`
excluded), plus top-level `round`/`expiresAt`. Added `Therapy::pendingSessionScheduleProposal()`
(scoped by `for`/`type` only, unlike `pendingRequestFor()`'s `to`-a-Counsellor assumption, since
this request's `to` alternates across counter-offer rounds) and threaded it through
`TherapyController::getTherapy` as a new Inertia prop, exactly matching the existing
`pendingRequest` pattern. Built the UI as a new `SessionScheduleProposalSection.vue` (a sibling to
the existing assistance-request banner, not folded into `TherapyInformation.vue`, per architect
review -- the round/stale/counter-offer state here is meaningfully richer), a `ProposeSessionScheduleModal.vue`,
and a `SessionScheduleCounterOfferModal.vue` mirroring `CompensationCounterOfferModal.vue`'s
established shape. The "propose a session time" CTA in `TherapyActions.vue` is gated on
`computedIsParticipant` (not counsellor-only, per `EnsureCanProposeSessionScheduleAction`), no
active session, and no already-pending proposal.

**Two more retroactive NOT NULL fixes, both found via live Playwright browser verification (not
Pest) of the full propose-then-accept round trip** -- the third and fourth such gaps in this
epic's session-schedule-proposal flow (after the `requests.type` enum and `sessions.about` fixes
in SCRUM-207), all following the identical pattern: the propose flow (SCRUM-206) never called
`CreateSessionAction` itself, so a NOT NULL column `CreateSessionRequest` normally guarantees went
unnoticed until an actual accept tried to insert a `Session` row:
1. `sessions.type`/`sessions.payment_type` are both NOT NULL native enum columns with no DB
   default. `CreateSessionScheduleProposalRequest` left both `nullable`, and
   `ProposeSessionScheduleModal.vue` correctly doesn't render their selectors when the therapy
   doesn't allow in-person / isn't paid (mirroring `CreateSessionFormModal.vue`'s own conditional
   rendering) -- but unlike that direct-create modal, nothing defaulted the omitted values before
   they reached the DB at accept-time, and a plain `??` fallback in `AcceptSessionScheduleProposalAction`
   doesn't catch an empty string. Fixed server-side (the authoritative fix, not reliant on any one
   frontend): `ProposeSessionScheduleAction` now defaults `type` to `ONLINE` and `paymentType` to
   `FREE` when omitted (via `?:`, which does catch empty string), and `CreateSessionScheduleProposalRequest`
   now conditionally requires `paymentType` when the therapy is PAID (no sensible default exists
   there -- silently defaulting to FREE would bypass billing). `CounterOfferSessionScheduleProposalAction`'s
   equivalent fallback was hardened from `??` to `?:` for the same reason. `ProposeSessionScheduleModal.vue`
   was also updated to always send a resolved value, for consistency with the direct-create modal.
2. `sessions.name` is also NOT NULL with no default, exactly like `about`'s already-fixed gap, but
   `CreateSessionScheduleProposalRequest` still had it `nullable` (`CreateSessionRequest` requires
   it, no default, since a session's name has no sensible fallback and participates in a
   same-therapy uniqueness check). Found by a regression test written for finding #1 above that
   happened to omit `name` too. Fixed by making it `required`, matching `about`.

**Why recorded**: this is now the fourth and fifth time in this epic that Pest's SQLite suite
missed a real NOT NULL bug that only live/thorough testing caught -- worth restating the pattern
explicitly for whoever builds the next feature that defers a real side effect (here, session
creation) to a later step: enumerate every NOT NULL column on the eventual target table against
every field the earlier step actually collects, don't assume "it validated at propose-time" means
"it's safe at accept-time" just because no test happened to omit the same field twice.

---

## 2026-09-02 — SCRUM-208: two High security findings from the pre-PR review, fixed before merge

**Finding 1 -- enumeration oracle via a DB-dependent FormRequest rule (High)**: the first version
of `CreateSessionScheduleProposalRequest`'s `paymentType` rule used
`Rule::requiredIf(fn () => optional(Therapy::find($this->route('therapyId')))->payment_type === ...)`
to require it only for a PAID therapy. Laravel resolves `FormRequest` validation *before* the
controller body runs, so this closure executed -- and could leak, via the presence/absence of a
"paymentType is required" validation error -- whether an arbitrary `therapyId` (including a
private/anonymous therapy the caller has no relationship to) is PAID, for ANY authenticated user,
before `EnsureCanProposeSessionScheduleAction`'s participancy check ever got a chance to run. This
is the exact PII/business-data-enumeration class already fixed twice before for this same request
family (SCRUM-124/162/206), reintroduced via a new code path (FormRequest validation ordering)
those earlier fixes didn't anticipate. Fixed by reverting the rule to a plain, therapy-independent
`nullable`/`Rule::in(...)`, and moving the actual required-for-PAID enforcement into
`EnsureSessionScheduleProposalDataIsValidAction`, which runs strictly after
`EnsureCanProposeSessionScheduleAction` inside the service/transaction -- so by the time payment
type is checked, participancy is already confirmed and there's no privileged data to leak.

**Finding 2 -- a client could under-report a PAID therapy's session as FREE (High)**: TT-2.5's own
design change is what created this risk -- `EnsureCanProposeSessionScheduleAction` lets *either*
participant propose, unlike the pre-existing direct-create flow
(`EnsureCanCreateSessionAction`/`CreateSessionRequest`), which only ever trusted a counsellor or
admin with the `paymentType` field. Once an ordinary client (who has a direct financial incentive
to under-report) can set this field too, nothing previously stopped them proposing `paymentType:
FREE` for a PAID therapy, and `AcceptSessionScheduleProposalAction` forces the session's actor to
the counsellor regardless of who clicks accept -- so a single accept click (without the counsellor
separately re-verifying every negotiated field) would create a real, incorrectly-priced `Session`.
Fixed in the same `EnsureSessionScheduleProposalDataIsValidAction` check added for Finding 1: any
client-supplied `paymentType` must equal the therapy's own `payment_type` exactly (covers both
directions -- FREE-for-PAID and the pre-existing PAID-for-FREE case), enforced identically for
both the initial propose and every counter-offer round (a countering party could otherwise
reintroduce the same bypass one round later).

**Why recorded**: both were caught by the `security-engineer` review dispatched before this PR,
not by the extensive manual Playwright QA done earlier in this ticket (which exercised only
happy-path FREE-therapy flows) -- worth remembering that a capability change ("who is trusted with
this field") can silently invalidate an assumption a shared validation path was built on, even
when the new code around it is otherwise a faithful copy of an existing, already-reviewed pattern.
Also worth noting explicitly: Finding 3 from that same review (a `public` FREE therapy's pending
proposal, including its free-text `name`/`about`, is visible to unauthenticated visitors of the
therapy page) was assessed as **not a new gap introduced by this ticket** -- it's the same
existing exposure `pendingRequest`/`recentSessions`/`recentTopics` already have on that page for
any `public` therapy, and this ticket's new prop was deliberately made to match that established
pattern rather than invent a narrower one. Left as-is rather than unilaterally restricting only
the new field; flagged in the SCRUM-208 Jira comment for explicit product sign-off on whether
"public therapy" is intended to mean "negotiation content is public too," since that's a product
question about the `public` flag's existing semantics, not something scoped to this ticket to
decide alone.

---

## 2026-09-02 — SCRUM-212 (TT-2.6a): counsellor calendar session aggregation

**Decision**: implemented `GetCounsellorCalendarSessionsAction` (new, under `app/Actions/Session/`)
unioning `Session` rows across a counsellor's `Therapy` set (single `counsellor_id`) and
`GroupTherapy` set (`counsellors()` pivot with `state = active`, **plus** a `Counsellor` `addedby`
-- mirrors `GroupTherapy::isCounsellor()`'s full definition exactly, not just the pivot half of
it), date-range bounded via a new `Timeable::scopeWhereWithinRange()` overlap check (catches a
session spanning into/out of the window, not just one whose start falls inside it). Wired through
a new `SessionService::getCounsellorCalendarSessions()` method (not an overload of the existing
single-therapy `getSessions()`) and a dedicated `EnsureCanViewCounsellorCalendarAction` with no
admin bypass (self-scoped only, per acceptance criteria).

**Anonymity-masking extraction** (architect-directed, part of this ticket's own scope): the
addedby-anonymity ternary duplicated across `TherapyResource`, `GroupTherapyResource`,
`TherapyMiniResource`, `GroupTherapyMiniResource` is now one shared `TherapyTrait::addedByUserIsMaskedFor()`
method, consumed by all four (behavior-preserving -- confirmed via the existing `AnonymityMaskingTest`/
`BroadcastChannelAnonymityTest`/`GroupTherapyMembershipTest` suites, all still green). `SessionResource`
gained an optional `for` field (`whenLoaded`, omitted for every existing single-parent caller) so
the calendar's per-event therapy/group name reuses `TherapyMiniResource`/`GroupTherapyMiniResource`
directly rather than a fifth masking copy -- this also means the counsellor's own view of an
anonymous therapy's calendar entry is correctly masked the same way the therapy page itself
already is (no new masking logic needed, confirmed by a dedicated test).

**A real Eloquent gotcha found while building the group-therapy union**: `wherePivot()` does not
work inside a `whereHas()` closure -- it isn't aware of the pivot join in that context and silently
produces an invalid `pivot = state` clause (caught immediately by a real-MySQL tinker reproduction,
not by Pest's SQLite run, which would likely have surfaced it as a different, more confusing
error). Fixed by referencing the actual pivot table/column (`counsellor_group_therapy.state`)
directly inside the closure instead.

**N+1, found by this ticket's own regression test, not by manual review**: `SessionResource` had
never before been rendered in bulk (every other caller shows one session, or one therapy's own
paginated list) -- `topics`/`cases`/`therapyTopicSessions` (backing the `currentTopic` accessor)/
`addedby` were never eager-loaded anywhere, silently fine at the small scale those callers render
at. Fixed via eager-loading in the new Action, plus a small, safe, backward-compatible tweak to
`Session::getCurrentTopicAttribute()` to use an already-loaded `therapyTopicSessions` relation when
present. Separately, `TherapyTrait::getSessionsHeldAttribute()`/`GroupTherapy`'s (new)
`counsellorsCount` accessor were re-running their own COUNT query on every access even though
Eloquent's morphTo eager loading shares one model instance across every sibling session
referencing the same parent (confirmed via tinker: `$session1->for === $session2->for`) --
memoized both on the shared instance, fixing the *systemic* version of this bug (any future caller
rendering the same parent multiple times in one request benefits), not just this endpoint's case.

**Why recorded**: the `wherePivot`-in-`whereHas` gotcha is worth remembering codebase-wide anytime
a new BelongsToMany-with-pivot-state query is written via `whereHas`, not just here. The
accessor-memoization fix is a rare case in this session where a *shared* fix (touching model code
used everywhere) was the right call rather than filing a narrow follow-up, specifically because
Eloquent's own object-sharing behavior meant the fix was small, safe, and universally beneficial
rather than a targeted patch for one new endpoint.

---

## 2026-09-02 — SCRUM-212: review findings applied before merge

Both `reviewer` and `security-engineer` approved the diff with no blocking findings. Applied their
non-blocking suggestions before opening the PR:
- Completed the anonymity-masking extraction properly: `PublicTherapyResource.php` had a fifth
  independent copy of the same ternary (missed when scoping the original four), now using
  `TherapyTrait::addedByUserIsMaskedFor()` too, plus its own inline `counsellorsCount` query
  replaced with `GroupTherapy::getCounsellorsCountAttribute()`.
- Restored a null-safe guard on `addedby` in `addedByUserIsMaskedFor()` (defense-in-depth against
  a future hard-delete path on `User` -- not reachable today, per the security review, since only
  `Counsellor` is ever force-deleted in this codebase, but cheap to keep).
- Added a max date-range bound (93 days) to `GetCounsellorCalendarSessionsRequest` so the endpoint
  can't be asked to union/eager-load an unbounded number of sessions in one request. Learned along
  the way that Laravel's `before_or_equal:field +N days` syntax does **not** work the way it reads
  -- `getDateTimestamp()` tries `strtotime()` on the whole literal string first, fails, then tries
  to resolve it as a field name (also fails), so the comparison silently degrades to "always
  invalid" rather than throwing or ignoring the modifier. Implemented via a manual `withValidator()`
  check instead.
- Added a second anonymity-masking test exercising the GroupTherapy leg specifically (the existing
  one only covered the Therapy leg, rendered through a different mini resource).
## 2026-09-02 — TT-2.6 (SCRUM-25): split into TT-2.6a/b after `/start-feature` review

**Decision**: TT-2.6 ("Counsellor calendar view of their sessions") was originally a single
5-point ticket. `product-owner`/`project-manager`/`architect` review found it undersized for the
same reason as TT-2.2/TT-6.3/TT-7.2 -- no cross-therapy/group-therapy session aggregation query
exists today, and it's a genuinely separable concern from whatever calendar UI ships. Split into
TT-2.6a (SCRUM-212, backend aggregation, 8 points, no dependency -- TT-2.5 is done) → TT-2.6b
(SCRUM-213, frontend calendar UI, 8 points, depends on 2.6a), 16 points total.

**Calendar UI: build, not buy**. `package.json` has zero calendar/scheduling dependency, and this
codebase has deliberately avoided heavy UI kits throughout (its only comparable dependencies,
`vue-select`/`@popperjs/core`, are small and single-purpose). `date-fns`/`date-fns-tz` are already
dependencies and sufficient for all the date math a week/month grid needs. TT-2.6b's scope is
explicitly drill-through-only (no drag-and-drop rescheduling, no inline editing, no recurring
views) -- exactly the case where a library's added bundle/maintenance/theming cost isn't justified.
Revisit only if a future ticket adds recurring series, drag-and-drop, or true multi-timezone
editing to the calendar.

**Anonymity-masking extraction folded into TT-2.6a**: architect review found the addedby-anonymity
masking ternary (`$addedbyUser?->is($user) || ! $isAnonymous`, built on `Therapy`/
`GroupTherapy::isAnonymousFor()`) already independently re-implemented in four places
(`TherapyResource`, `GroupTherapyResource`, `TherapyMiniResource`, `GroupTherapyMiniResource`).
Since TT-2.6a's calendar payload needs the same masking for its per-event therapy/group name (the
first cross-therapy aggregate surface in the app), a fifth inline copy would only grow the
duplication -- TT-2.6a extracts a single shared helper instead, consumed by the calendar payload
and, ideally, backfilled into the four existing call sites.

**Confirmed with the user rather than assumed**: a `GroupTherapy` with multiple active counsellors
means each independently sees that group's sessions on their own calendar -- there is no "primary
counsellor" concept in the data model, and inventing one wasn't warranted just for this ticket.

**Why recorded**: fourth ticket in this backlog (after TT-2.2, TT-6.3, TT-7.2) to be undersized at
the single-ticket-estimate stage and require a `/start-feature`-time split rather than a mid-
implementation discovery -- worth noting that "New (4.x)" or "Existing" backlog-conversion rows
with terse one-line descriptions are the ones consistently hitting this, not tickets that already
went through a prior planning pass.

---

## 2026-09-02 — SCRUM-213 (TT-2.6b): counsellor calendar UI

**Decision**: implemented a custom-built (no calendar library, per the architect's earlier
build-vs-buy call) week/month calendar at a new `/counsellor/calendar` page --
`Pages/Counsellor/Calendar.vue` composing `CalendarWeekView.vue`/`CalendarMonthView.vue`/
`CalendarEvent.vue`, backed by a new `useCalendar.js` composable wrapping `date-fns` (no other
component in this codebase did week/month bucketing before this). Fetches its own range-scoped
data client-side from TT-2.6a's endpoint on every range change -- never the counsellor's entire
session history in one payload, per the ticket's own requirement. Drill-through only: clicking an
event navigates to the underlying `Therapy`/`GroupTherapy` page; no session-mutation action exists
on the calendar itself.

**A real routing bug found via live browser testing, not by reading the code**: the new
`/counsellor/calendar` route silently 302-redirected to home even for an actual counsellor,
because the pre-existing `/counsellor/{counsellorId}` route (registered earlier in `routes/web.php`,
outside the auth group) matched first and treated `"calendar"` as a `counsellorId` route parameter
-- Laravel resolves competing route patterns in registration order, not specificity. Fixed by
registering the new route immediately before the colliding one, with a comment explaining why the
order matters here specifically.

**A real UX bug found via live browser testing**: the initial month view didn't pad to full weeks
(`startOfMonth`/`endOfMonth` only), so the grid's weekday-column headers didn't actually correspond
to the day-of-week of the cells beneath them for any month not starting on a Sunday -- looked
broken, not just visually rough. Fixed by padding `monthRange()` to `startOfWeek(startOfMonth(...))`
.. `endOfWeek(endOfMonth(...))`, matching how every real calendar UI grids a month.

**A `Select.vue` gotcha avoided rather than hit**: that shared component unconditionally
uppercases its bound `v-model` value (built for this codebase's backend enum values, e.g.
'ONLINE'/'PAID') -- noticed before wiring it up for this page's client-side-only lowercase filter
state (`'individual'`/`'group'`/`'upcoming'`/`'past'`), which would have silently broken every
filter comparison. Used plain native `<select>` elements instead, which was the actually-correct
tool for genuinely local UI state rather than a backend-enum-backed field.

**Why recorded**: the two real bugs above were both browser-verification catches, not code-review
catches -- worth restating (again) that "reads correctly" and "renders correctly" are different
claims for anything involving route registration order or date-grid math, neither of which a
type-checker or a Pest request test would have caught (Pest's route dispatch works the same way
in both the buggy and fixed state; the bug was in *how a human reads the resulting grid*, not in
any assertable response shape).

---

## 2026-09-02 — SCRUM-213: reviewer findings applied before merge

`reviewer` requested changes; `security-engineer` approved with no blocking findings (two minor,
non-blocking style suggestions, not applied -- route-group placement and a `console.log`
verbosity note, both explicitly called out as optional).

**Real correctness bug (required change)**: `useCalendar.js`'s `toApiDate()` used `date-fns`'
`format()`, which reads a `Date` object's *local* wall-clock getters -- not the UTC conversion the
backend expects (`Session.start_time`/`end_time` are stored and queried in UTC throughout this
app). For any counsellor whose browser timezone isn't UTC, the requested week/month range would
have been silently shifted by their local UTC offset, dropping or mis-bucketing sessions near a
day/week boundary -- exactly the kind of bug that a dev/CI environment running in UTC (as this
one does) can't surface, which is why it wasn't caught by the live Playwright verification done
earlier in this ticket. Fixed by switching to `.toISOString()`, matching the identical
local-Date-to-backend-datetime precedent already established by `ProposeSessionScheduleModal.vue`/
`SessionScheduleCounterOfferModal.vue`.

**Deduplication (required change)**: the UTC-string-normalization one-liner (`dateTime.includes('T')
? dateTime : ...`) was independently copied in both `useCalendar.js` and
`SessionScheduleProposalSection.vue`. Extracted into the existing shared `useLocalDateTime.js`
composable (new `toLocalDate` export) and had both call sites use it instead.

**Suggested improvements also applied**: deduplicated a repeated `for_type === Therapy::class`
comparison in `SessionResource::toArray()` into one local variable; removed a defensive
`res.data.sessions.data ?? res.data.sessions` fallback in `Calendar.vue` that guarded against a
paginated-response shape this particular endpoint never actually returns (confirmed via the
existing passing tests asserting `sessions.0.id` directly).

**Why recorded**: the local-vs-UTC bug is the third distinct "looks right in this UTC dev
environment, would be wrong for a real user elsewhere" class of issue found in this session
(alongside the two live-browser-verification catches earlier in this same ticket) -- worth noting
that neither Pest nor a UTC-timezone dev container can ever catch this specific class of bug;
only an explicit code-review comparison against this codebase's own established
local-Date-to-UTC-string precedent caught it here.

---

## 2026-09-01 — TT-1 (SCRUM-5) and TT-2 (SCRUM-6) epics closed; TT-6/TT-7 picked as next area

**Decision**: with TT-1's last remaining item (SCRUM-59) merged, transitioned epic TT-1 to Done.
TT-2 was already fully closed in the prior session. Rather than autonomously guessing which of
the many independent, unstarted-or-partial epics (TT-3/4/5/6/7/8/9) to pick up next, asked the
user directly -- a genuine fork per CLAUDE.md's autonomy rules, since no dependency chain
determined the next unit of work. User chose to finish the already-substantially-built TT-6
(Organizations) and TT-7 (Payments) epics over starting a brand-new one.

**Why**: TT-6/TT-7 turned out to have only one actual remaining ticketed item (SCRUM-169/
TT-6.5c2) once checked against live Jira state -- everything else under TT-6 (TT-6.6a-e, TT-6.7,
TT-6.5a/a2/b/c) was already Done, and TT-7's remaining line items (TT-7.5/7.6/7.7/7.9) have no
Jira tickets yet and would need their own `/start-feature` pass rather than being pickable
directly. `documentation/implementation_plan.md`'s own row-level ✅ markers are stale/incomplete
for TT-6's later rows -- Jira, not the plan doc, is the authoritative source for what's actually
done.

---

## 2026-09-01 — SCRUM-169 (TT-6.5c2): cross-ticket link-generation gap found, not absorbed here

**Decision**: while implementing SCRUM-169 (member self-apply directory browse + apply UI),
found that TT-6.7 (SCRUM-164, Done) explicitly deferred "generate a self-apply link" UI to
TT-6.5a (SCRUM-165, Done) -- but SCRUM-165's own scope/acceptance criteria never actually
included it, and a full-repo grep confirms zero frontend code references
`organizationSelfApply`/self-apply links anywhere. Filed a new follow-up ticket (SCRUM-214)
for the missing org-admin "generate link" UI rather than building it inside SCRUM-169.

**Why**: SCRUM-169's own scope and acceptance criteria are member-facing only (browse the
directory, apply, follow an existing link) -- they say nothing about admins generating links.
Absorbing an unrelated ticket's dropped scope into a different, unrelated, already-small ticket
would be unrequested scope creep; a dedicated follow-up keeps the gap visible and separately
prioritizable instead of silently folding it in.

---

## 2026-09-02 — SCRUM-215 (TT-7.5): payment-gated access, planning decisions

**Decision**: after product-owner review surfaced genuinely safety-critical open questions (what
exactly gets blocked, whether a later refund can retroactively revoke access, GroupTherapy
scope), asked the user directly rather than guessing on a mental-health platform's payment gate.
Four decisions came back: (1) gate whichever model actually carries the payment obligation
(Therapy or Session, following the existing `payment_data->per` PER_THERAPY/PER_SESSION split),
not a blanket whole-therapy block; (2) the gate is evaluated ONCE at first access and never
re-evaluated -- a later refund never retroactively locks an admitted client out; (3) GroupTherapy
gating is wanted eventually but is explicitly blocked on TT-7.4d (per-member group payment,
currently unscoped) landing first; (4) public/non-participant visibility stays untouched.

Project-manager then split the buildable-now portion into TT-7.5a (SCRUM-217–221, 5 sub-tickets,
~26 points provisional) and filed TT-7.5b (SCRUM-216) as a separate, unscheduled sibling ticket
blocked on TT-7.4d. Architect recommended: store the new flag as `payment_data->strictPaymentGate`
(not a new `therapies` column, matching the `per`/`amount`/`currency` convention); a new, narrow
`payment_access_grants` table for the "granted once" state (deliberately NOT derived from
`Transaction.status` at read time, since TT-7.7's future refund handling will start mutating that
field); a new `PaymentRequiredException` for controller/page-load call sites only, with
`MessageService`'s 4 duplicated authorization call sites getting a plain boolean check instead
(matching their existing idiom, not forcing a new one on them).

**Why**: this is a payment-and-access feature on a mental-health platform -- guessing wrong on
"can a refund cut someone off mid-relationship" would be a real harm event, not just a UX misstep,
so it was treated as a genuine "pause and ask" fork per CLAUDE.md rather than a judgment call to
make silently. The architect's `payment_access_grants` table recommendation exists specifically
to prevent a *future* ticket (TT-7.7 refunds) from silently breaking *this* ticket's core safety
invariant -- a cross-ticket coupling risk worth designing around now rather than discovering later.

**Follow-up decision (2026-09-02, user-confirmed)**: SCRUM-220 WILL consolidate `MessageService`'s
4 pre-existing, independently-duplicated authorization checks (`getSessionMessages`,
`getDiscussionMessages`, `getTherapyTopicMessages`, `getMessageReplies`) onto the one new shared
gate-satisfaction check, rather than bolting the payment condition on next to 4 separate copies.

**Why**: since all 4 methods need to be touched anyway to add the new payment-gate condition,
fixing the pre-existing duplication in the same pass is cheaper than doing it as a separate
follow-up later (avoids re-touching the same 4 call sites twice) and removes a real, already-
identified wart while the context is fresh, rather than letting a fifth divergent copy of "is
this user allowed to see this" logic risk accumulating before the follow-up ever gets picked up.

---

## 2026-09-02 — SCRUM-219 (TT-7.5a 3/5): closed a HIGH-severity self-disable gap before merge

**Decision**: while implementing page-load enforcement of the strict payment gate,
security-engineer found that the therapy's own `addedby` (the paying client) could call the
existing `updateTherapy` endpoint to flip `strictPaymentGate` back to `false` (or switch `per` to
`PER_SESSION`) at will, completely defeating the gate this ticket exists to enforce --
`EnsureCanUpdateTherapyAction` lets the client update any field with no restriction. Added a new
`EnsureCanSetStrictPaymentGateAction`: only an admin or the therapy's *assigned* counsellor may
change `strictPaymentGate` once the therapy already exists; the creating client still sets its
initial value at CREATE time, since this app has no counsellor assigned to a therapy until one
accepts an invite/application afterward (there's no one else to set it at that point). Also
tightened `per`'s validation to `Rule::in(TherapyPerPaymentEnum::values())` on both Create/Update
requests (was a bare `'string'` rule) and fixed a sibling inconsistency `reviewer` found:
`TherapyController::chat()` shares `EnsureUserHasAccessToTherapyAction` with `getTherapy()` but
wasn't handling `PaymentRequiredException` the same way -- both now share one
`redirectForPaymentRequired()` helper.

**Deliberately NOT fixed here, deferred to SCRUM-220** (flagged in that ticket's own comments):
a client can still switch an existing `PER_THERAPY` strict-gated therapy's `per` to `PER_SESSION`
to escape this ticket's gate entirely, since PER_SESSION gating doesn't exist until SCRUM-220
ships. A second follow-up-verification security pass confirmed this is a pre-existing scope
boundary (not a new hole this fix introduces) and is now explicitly documented and test-covered
rather than silently present.

**Why**: this is the first ticket where the strict payment gate actually denies real access, so a
security-engineer pass was run before committing per CLAUDE.md's mandatory-security-for-payment-
and-mental-health-platform rule, not just at PR time -- catching this kind of self-disable bypass
before merge is exactly the point of that gate, especially given `payment_access_grants` rows are
permanent by design (SCRUM-215 decision #3): a bug here wouldn't just be wrong once, it would let
a client bypass payment indefinitely with no self-correcting mechanism.

---

## 2026-09-02 — SCRUM-220 (TT-7.5a 4/5): consolidated 3 of 4 flagged MessageService checks, not 4

**Decision**: this ticket's own text (and the 2026-09-02 "consolidate MessageService now" decision
above) named all 4 of `MessageService`'s independently-duplicated authorization checks
(`getSessionMessages`, `getDiscussionMessages`, `getTherapyTopicMessages`, `getMessageReplies`) as
in scope for consolidation onto one shared check. The actual implementation consolidates only 3 --
`getDiscussionMessages()` is deliberately left untouched.

**Why**: `Discussion::isParticipant(?Counsellor $counsellor)` always returns `false` for a null
`Counsellor`, and `getDiscussionMessages()` passes `$user->counsellor` (never `$user` itself) --
so a plain client (the only party the payment gate ever applies to) can never be a Discussion
participant at all, regardless of payment status. There is no client-payment-gating scenario to
consolidate there; forcing it onto the same shared check would be an artificial unification of
two genuinely different authorization shapes (`isParticipant(User)` vs `isParticipant(?Counsellor)`)
for a case that can never actually exercise the new logic. Verified via a `security-engineer`
pass specifically re-checking this exclusion for safety (not just convenience) before merge --
confirmed no path (including a user who holds both a `User` and `Counsellor` identity) lets a
paying client reach Discussion content, since the check only ever evaluates their `Counsellor`
side there.

Also fixed, found via my own test suite before either review pass: the first implementation of
the new shared `EnsureStrictPaymentGateSatisfiedAction` only checked the PER_THERAPY case when NO
session was passed in -- meaning `MessageService::getSessionMessages()` (which always has a
session in context) never actually gated a PER_THERAPY-payable strict-gated therapy's chat at
all, leaving open the exact "still reachable" hole this ticket exists to close. Fixed by checking
PER_THERAPY unconditionally, before even considering whether a session was passed.

---

## 2026-09-02 — SCRUM-222 (TT-7.4-retry): scope decided, mechanism already worked

**Decision**: TT-7.4's original "retry-on-failure" clause was carved out during SCRUM-118's split
(2026-08-29) as its own small follow-up, tracked but unscoped. Before filing SCRUM-222, I verified
the underlying retry *mechanism* already works today: `EnsureCanInitiateChargeAction` only blocks
a further charge once a SUCCESS transaction exists (a FAILED/ABANDONED one does not block retry),
and `usePayment.js`'s `canPayForTherapy`/`canPayForSession` already gate purely on
`paymentStatus !== 'SUCCESS'`, so the pay button already reappears after a failed attempt with
`STATUS_MESSAGES.FAILED` already reading "Your payment failed. Please try again."

So the real open question was not "can a client retry" but "does the UI communicate that a retry
is happening" — the pay button looked identical (same "pay now" label) on a first attempt and a
retry. product-owner review surfaced this as a genuine product fork rather than guessing: (a)
distinct retry wording/styling (frontend-only), (b) persistent failure visibility across reloads
(frontend-only, reads existing `latestTransaction` data), (c) a full attempt-history list (needs
new backend — no transaction-history endpoint exists today), or (d) close as already-satisfied
with no new UI. User chose **(a)**: distinct "try payment again" wording/styling, keyed off the
already-persistent `paymentStatus` field (not the one-shot `transactionStatus` flash), so it
survives a reload without building (b)'s full persistent-banner feature or (c)'s new backend
surface.

Architect recommended centralizing the FAILED/ABANDONED check as a shared `isRetryStatus()` helper
in `usePayment.js` (mirroring the existing `paymentStatusLabel()` pattern) rather than inlining an
OR-check independently in both `TherapyPaymentDetails.vue` and `UnifiedTherapy.vue` — both already
destructure from this composable for identical status-to-copy logic, so this was the minimal-diff
option, not the heavier one. project-manager confirmed this as a single, correctly-small 2-point
ticket needing no split, unlike the TT-6.3/TT-7.2/TT-2.6/TT-2.2 undersizing pattern.

**Why**: the "what should this ticket actually deliver" question was a genuine product trade-off
(scope ranged from a one-line copy change to a new backend feature) that couldn't be resolved by
guessing a default without risking either under-delivering (leaving real user-facing ambiguity
during a stressful moment — payment failure — for a mental-health platform's users) or
over-delivering (building unwanted backend surface for a ticket explicitly sized small). Verified
end-to-end via live Playwright QA (seeded FAILED transactions on both the PER_THERAPY and
PER_SESSION demo therapies, confirmed both button/label/styling changes, cleaned up test data
afterward) since no JS/Vue test framework exists in this codebase to write automated coverage
instead. `reviewer` and `security-engineer` both approved with no required changes.

---

## 2026-09-03 — SCRUM-225 (TT-7.6a): payout-destination onboarding via Paystack Transfer Recipients

**Decision**: implemented TT-7.6a per the product-owner/project-manager/architect plan from
SCRUM-224's review (see that entry). This is this codebase's first Paystack API surface beyond
`/transaction/initialize`/`/transaction/verify` -- added `PaystackClient::resolveAccountNumber()`
(GET `/bank/resolve`) and `createTransferRecipient()` (POST `/transferrecipient`), following the
existing thin-wrapper convention exactly. New `counsellor_payout_accounts` table (one row per
counsellor, unique constraint -- changing a destination replaces the row in place rather than
accumulating history, unlike the earnings ledger, since no later ticket needs to query past
destinations) stores only a Paystack `recipient_code` + a masked account number; the raw account
number is sent to Paystack transiently and never persisted. Onboarding gated on
`Counsellor::isVerified()` (existing platform verification) -- no new identity-verification
subsystem, per the user's decision during SCRUM-224 review. A destination-change email fires only
when replacing an EXISTING destination, never on first-time setup.

**A real bug found and fixed during my own implementation** (not by review, though review verified
the fix): the "is this a first-time onboarding or a replacement" check originally read
`$counsellor->payoutAccount` (the Eloquent relation) -- but a null-result relation is cached on
the model instance, so a caller reusing the same in-memory `$counsellor` object across two
separate onboarding calls (exactly what a "does replacing send a notification" test needs to
exercise) would see a stale "no existing destination" even on a genuine replacement, silently
skipping the security-relevant account-takeover notification. Fixed by replacing it with a direct
`CounsellorPayoutAccount::query()->where('counsellor_id', $counsellor->id)->exists()` check.
`reviewer`'s re-review confirmed this was the only instance of the trap in the diff.

**Security-engineer findings, both addressed**:
- **Fixed (defense in depth)**: `CreateCounsellorPayoutDestinationAction` had no independent
  authorization check of its own -- its safety depended entirely on `PayoutService::onboardDestination()`
  (the only current caller) remembering to run `EnsureCanOnboardPayoutDestinationAction` first.
  Not exploitable today (no controller/route exists yet to reach this action any other way), but
  fixed immediately anyway by having the action re-run the same ensure-check internally, so it
  stays safe even if a future ticket ever calls it directly by mistake.
- **Deferred to TT-7.6d (the controller ticket), logged as a Jira comment on that ticket, not
  fixed here**: (1) the future controller must build `PayoutDestinationDTO->user` strictly from
  the authenticated user, never request input, and call only `PayoutService::onboardDestination()`;
  (2) no request validation exists yet on `accountNumber`/`bankCode`/`currency`/`type` -- TT-7.6d
  needs a `FormRequest`; (3) `PayoutException`'s constructor status-code argument is currently
  inert (nothing maps `getCode()` to an HTTP response) -- TT-7.6d needs to wire this the way
  `TransactionException` already is via `ResolvesExceptionResponse`. All three are explicitly
  scoped to the not-yet-built HTTP layer and confirmed by security-engineer as not exploitable in
  this ticket's actual diff.

**Why**: the relation-caching bug was a genuine correctness gap for a security-relevant
notification (an account-takeover mitigation that would have silently failed to fire on exactly
the scenario it exists for), so fixed immediately rather than deferred. The authorization
defense-in-depth fix was cheap and closes a real (if currently unreachable) gap the same way the
TT-7.7a/TT-7.6b review passes already established a pattern for in this epic. The three
controller-layer findings were correctly deferred rather than built speculatively now, since
building validation/HTTP-status-mapping for a route that doesn't exist yet would be exactly the
kind of premature abstraction this project's rules caution against -- logging them on TT-7.6d's
own ticket instead ensures they aren't silently lost.

---
## 2026-09-03 — SCRUM-226 (TT-7.6b): earnings ledger + platform settings mechanism

**Decision**: implemented TT-7.6b per the product-owner/project-manager/architect plan from
SCRUM-224's review (see that entry). Two implementation-time judgment calls, made without a
further round of user questions since neither is a product fork -- both are technical
interpretations of already-approved scope, logged here per CLAUDE.md rather than escalated:

1. **GroupTherapy `sharePercentage` semantics**: this codebase has never actually computed a
   payout from `payment_data->shareEqually`/`sharePercentage` before (grep confirmed: only ever
   validated in `EnsureTherapyDataIsValidAction` and displayed in `TherapyPaymentDetails.vue`,
   never used in a calculation) -- so this ticket had to define that behavior for the first time,
   not just "reuse the existing logic" as the plan text implied. Read `GroupTherapyFormModal.vue`'s
   actual field labels ("How do you want earnings shared?" / "What percentage will you give to
   the participating counsellors?") as the closest thing to a spec: `sharePercentage` is the % of
   the WHOLE transaction allocated to the counsellor pool collectively (not one named
   counsellor's own cut), and — since nothing in this codebase's schema or UI has ever modeled a
   per-counsellor split within that pool — that pool is divided EQUALLY among all currently-active
   counsellors, whether `shareEqually` is true (100% pool) or a specific `sharePercentage` is set
   (that %, as the pool). A leftover minor-unit remainder from an uneven equal split is assigned
   to the first counsellor rather than dropped.
2. Everything else (settings mechanism shape, ledger schema, idempotency, webhook placement) followed
   the architect's explicit, decisive recommendations from SCRUM-224's review verbatim -- no
   further judgment calls needed there.

**Review findings and fixes** (both `reviewer` and `security-engineer` ran twice -- once on the
initial implementation, once to verify fixes):
- **Reviewer, required**: `GenerateCounsellorEarningsAction` was called from inside
  `RecordTransactionStatusAction` AFTER that action's status update and status-history insert had
  already committed independently -- a failure in earnings generation (DB hiccup, unexpected
  state) would leave a transaction permanently stuck as `success` with no `CounsellorEarning` row
  and no retriable path back to it (the terminal-status guard means a later identical webhook
  replay just short-circuits). Fixed by wrapping the status update, status-history insert, AND
  the earnings-generation call in one outer `DB::transaction()`, so a throw anywhere rolls
  everything back, keeping the transaction in a genuinely retriable non-terminal state. Verified
  (via the re-review pass) that `GenerateCounsellorEarningsAction`'s own inner
  `DB::transaction()`+`lockForUpdate()` correctly runs as a savepoint of the outer transaction
  rather than conflicting with it.
- **Reviewer, required**: the arithmetic was only unit-tested by calling
  `GenerateCounsellorEarningsAction` directly -- nothing proved `RecordTransactionStatusAction`'s
  real callers (the Paystack webhook, the verify-callback fallback) actually reach it. Fixed by
  adding an end-to-end test in `tests/Feature/PaystackWebhookTest.php` that posts a real signed
  `charge.success` webhook through the HTTP boundary and asserts a `CounsellorEarning` row exists
  afterward.
- **Reviewer, suggested, applied**: the fee calculation multiplied a `float` percentage directly
  against a money amount (`floor($grossAmount * $feePercentage / 100)`) -- safe for whole-number
  percentages (the only value ever set today) but a latent drift risk once a fractional fee (e.g.
  12.5%) is set via the new admin-configurable mechanism. Fixed by converting the percentage to
  integer basis points once, up front, then doing pure integer division
  (`intdiv($grossAmount * $feeBasisPoints, 10000)`), keeping the whole file in the same
  integer/minor-unit space its GroupTherapy-split math already used.
- **Reviewer, suggested, applied then corrected further**: added a comment to
  `SettingsService::get()` documenting that an empty-string setting value is treated as unset --
  but the re-review pass caught that the actual code (`$value ?? $default`) didn't match that
  comment (`??` only substitutes on `null`, not `''`). Rather than just fixing the comment to
  match the (wrong) behavior, changed the code itself to match the originally-intended contract
  (`$value === null || $value === '' ? $default : $value`), since the wrong behavior was a real,
  if narrow, money-correctness gap: an empty string ever persisted for `platformFeePercentage`
  (e.g. a future admin-UI bug submitting a blank field) would have silently computed a 0% fee via
  `(float) ''`, rather than falling back to the intended default. Added a regression test.
- **Security-engineer, required**: `poolPercentage` (the GroupTherapy counsellor-pool %) was
  derived from `payment_data` and used directly in money arithmetic with no defensive bound of
  its own -- it relied entirely on `EnsureTherapyDataIsValidAction` (a different file, the only
  current writer of `payment_data`) for the 40–100/70–100 invariant. Fixed with an explicit
  `max(0, min(100, $poolPercentage))` clamp in the action itself, plus a regression test
  simulating a bypass (`sharePercentage = 150` written directly, as a future admin tool or
  migration might).
- **Security-engineer, flagged, explicitly deferred (not fixed here)**: `transactions.organization_id`
  uses `nullOnDelete()` against a soft-deletable `Organization` — if an `Organization` were ever
  *force*-deleted while it had an in-flight, not-yet-successful transaction, that transaction's
  `organization_id` would be nulled out before `GenerateCounsellorEarningsAction`'s
  `organization_id !== null` check runs, misclassifying an org-financed payment as personal and
  paying it out as counsellor earnings instead of reserving it for TT-7.3b's org-split. Confirmed
  by security-engineer as currently unreachable (no `forceDelete()` call on `Organization` exists
  anywhere in the codebase today) and correctly out of this ticket's scope — logged here as a
  design constraint TT-7.3b must account for before it starts relying on `organization_id`'s
  permanence, per the security-engineer's explicit recommendation, rather than pre-emptively
  building a guard against a scenario that doesn't exist yet.
- **Security-engineer, flagged, explicitly deferred (not fixed here)**: `getPlatformFeePercentage()`
  has no equivalent clamp to the `poolPercentage` fix above — an out-of-range value stored via
  `UpdateSettingAction` would flow unclamped into the same fee arithmetic. Not fixed here because
  `UpdateSettingAction` has no controller/route yet (TT-7.6e builds that) and is therefore not
  reachable by anything other than a direct Service call in a test today. Logged as a requirement
  for TT-7.6e: add validation (in `SettingDTO`/`UpdateSettingAction`) or a defensive clamp in
  `SettingsService::getPlatformFeePercentage()` bounding it to 0–100 when that write path is
  actually exposed.

**Why**: both the GroupTherapy-split interpretation and the review fixes were genuinely
consequential correctness questions for a feature whose entire purpose is handling real money
(even though this particular sub-ticket makes no external API calls yet) — logged rather than
silently absorbed so the next engineer (starting with TT-7.6c, which builds directly on this
ledger) has the full reasoning, not just the resulting code.

---
## 2026-09-02 — SCRUM-223 (TT-7.7): refund handling scoped, TT-7.6/TT-7.3b resequenced ahead of it

**Decision**: SCRUM-223 ("TT-7.7: Refund handling") went through product-owner review and, like
every other TT-7 sub-story reviewed so far this session (TT-6.3, TT-7.2, TT-2.2, TT-2.6, TT-7.5a),
was found significantly undersized at its original 5-point estimate. Product-owner surfaced three
genuine product forks rather than guessing:

1. **Refund initiation**: admin-direct-action (smaller) vs. client-request/admin-approval
   workflow (larger, new state machine). **User chose the workflow** — a client requests a
   refund with a reason, an admin reviews and approves/rejects, matching TT-6.4c's negotiation-
   flow shape rather than a single admin action.
2. **Refund execution**: internal record-keeping only (smaller, lower risk) vs. a real Paystack
   refund API call with webhook confirmation (larger, real money movement). **User chose the
   real API call** — a record-keeping-only refund would leave the app claiming "REFUNDED" while
   no money had actually moved, judged worse for trust on a platform where clients may already
   carry cost anxiety about care than not building the feature yet.
3. **Org-paid transaction eligibility** (surfaced during project-manager review, not the initial
   product-owner pass): should a transaction with `transactions.organization_id` set (TT-7.3a)
   be refund-eligible under this ticket, given TT-7.3b (org billing reconciliation — notifying
   the org admin, adjusting compensation) hasn't shipped? Refunding one today would give the org
   zero visibility. Two sub-options were offered: block org-paid refunds until TT-7.3b ships
   (smaller), or allow now with the gap documented as debt (simpler check, but a real org could
   lose money silently). **User rejected both** and asked for a third path: keep org-paid
   transactions in TT-7.7's scope, but require TT-7.3b's reconciliation piece to actually exist
   FIRST, i.e. resequence the roadmap so TT-7.7's implementation is blocked on TT-7.3b rather than
   deferring org-paid handling to an unscoped future ticket. When asked to clarify how much of
   TT-7.3b's scope that implied — a narrow "notify org admin" step (~2-3pts) vs. the *full*
   payout/compensation-adjustment reconciliation TT-7.3b already covers (8pts, which itself
   depends on TT-7.6 payout, 13pts, since there's nothing to reconcile against without a payout
   system) — **the user chose the full path**: TT-7.6 → TT-7.3b → TT-7.7, in that order.

**Net effect**: TT-7.3b's dependency direction reverses. It previously depended on TT-7.7
(`documentation/implementation_plan.md` line 218's old "Depends on: TT-7.3a, TT-7.6, TT-7.7").
Now TT-7.7 depends on TT-7.3b, which depends on TT-7.6. TT-7.6 (counsellor payout via Paystack
Transfers, previously "own Epic once scheduled, not a TT-7 sub-story," 13 points provisional) is
promoted to the active next unit of work as a direct consequence — it needs its own
`/start-feature` pass (Jira ticket, product-owner/project-manager/architect review) before either
TT-7.3b or TT-7.7 can start. This is a genuinely large scope escalation from what began as a
5-point fast-follow ticket: TT-7.6 (13pts, provisional) → TT-7.3b (8pts) → TT-7.7a-e (34pts) is
55+ points of prerequisite-and-feature work now queued ahead of what was originally framed as a
quick refund fast-follow.

**Sub-ticket split** (TT-7.7a → b → c → d → e, sequential, 34 points total — see
`documentation/implementation_plan.md`'s TT-7 table for full per-ticket scope): architect
recommended (1) a separate `refunds` table for the audit trail rather than overloading
`TransactionStatusEnum` with a `refunded` case, since `status` already means "did the charge
succeed" for existing consumers (webhook amount/currency verification, `isSuccessful()`) and
refunds are naturally 1:many per transaction (partial-refund-friendly), not 1:1; (2) the
client-request/admin-approval ask-and-approve step reuses the existing polymorphic `Request`
model (new `RequestTypeEnum::refund` case, mirroring the `organizationCounsellorCompensationChange`
precedent) for consistency, but the actual Paystack API call is deliberately isolated in its own
queued job fired only after `RespondToRequestAction` flips the request to accepted — never called
inline from within that shared dispatcher, which is already on record (SCRUM-119/120) as growing
debt from its linear per-type `if`-chain, and whose only tested idempotency guarantee (safe no-op
on double-respond, `RespondToRequestActionIdempotencyTest`) was built for simple internal-state
flips, not for "did we already call a third-party payment API for this."

**Hard, non-negotiable constraint carried through every sub-ticket**: refund handling must never
read from, write to, or otherwise reference `payment_access_grants` (TT-7.5a's permanent,
non-revocable first-access-grant table, see this file's 2026-09-02 SCRUM-215 entries) — a
client's platform access must stay completely unaffected by a refund's outcome. TT-7.7d (the
sub-ticket that actually calls Paystack) carries an explicit regression test proving this.

**Why**: all three forks were genuine product/architecture trade-offs where guessing wrong would
have been expensive to reverse (a workflow bolted onto a direct-action design later means a new
state machine, not a patch; a real API call retrofitted onto record-keeping-only means re-touching
every place that assumed "REFUNDED" already meant money moved) — consistent with this session's
standing practice of surfacing genuine forks to the user rather than defaulting to the smaller
option. The org-paid question in particular was raised by project-manager mid-review, not
anticipated by product-owner's first pass, and needed a clarifying round (the user's first answer,
"add it to scope with blockers implemented first," was itself ambiguous between a narrow
notification-only blocker and the full TT-7.3b/TT-7.6 chain — resolved by presenting both
interpretations side by side rather than guessing which one "blockers" meant).

---

## 2026-09-02 — SCRUM-224 (TT-7.6): counsellor payout scoped, split into 5 sub-tickets (47 points)

**Decision**: TT-7.6 ("Counsellor payout via Paystack Transfers") was promoted from "own epic,
unscheduled" to the active next unit of work as a direct consequence of the SCRUM-223 decision
above (TT-7.6 → TT-7.3b → TT-7.7). Went through the full product-owner → project-manager →
architect `/start-feature` sequence and, like every other TT-7 sub-story reviewed this session
(the 8th in a row), was found significantly undersized at its 13-point provisional estimate —
final split: TT-7.6a-e, 47 points total (see `documentation/implementation_plan.md`'s TT-7 table).

**Product-owner surfaced four genuine forks**, all resolved by the user:

1. **Platform fee**: not a fixed percentage. The user explicitly rejected both preset options
   (a specific hardcoded %) and instead specified: "this should be a setting platform super admin
   should be able to set with a fallback being a configurable env variable." This is genuinely new
   infrastructure — no settings mechanism exists anywhere in this codebase today (`SettingsEnum`
   was an empty stub). Disclosed to the counsellor as an itemized gross/fee/net breakdown,
   matching TT-6.4c's existing compensation-transparency precedent.
2. **Payout methods**: both bank account AND Ghanaian mobile money as Paystack Transfer Recipient
   destinations, not bank-only — product-owner flagged that Ghana (this platform's primary
   market, per `config/currencies.php`'s USD/GHS default) has strong mobile-money payout
   preference, and bank-only would materially reduce counsellor usability.
3. **Minimum payout threshold**: GHS 50 / USD 10 (fixed values), chosen over a higher GHS 100 /
   USD 20 option — small enough to not make counsellors wait too long for small earnings, large
   enough to comfortably clear Paystack's own per-transfer fee.
4. **KYC depth**: Paystack's own account-name-match check at recipient creation, plus the
   counsellor's EXISTING platform verification (`Counsellor::isVerified()`) — no new
   identity-verification subsystem (no document upload, no BVN linkage). User confirmed
   product-owner's own recommendation here rather than requesting something more rigorous.

**Scope boundary (settled by product-owner, not re-litigated)**: TT-7.6 covers ONLY
personally-financed transactions (`transactions.organization_id IS NULL`). Org-financed payout
splitting is explicitly excluded and deferred to TT-7.3b, layered on top of TT-7.6's payout rails
once both exist — avoids the mistake of paying a counsellor 100% of an org-financed transaction's
share when `shareEqually`/`sharePercentage` only governs same-therapy multi-counsellor splits, not
org-vs-counsellor splits.

**Two project-manager judgment calls, both architect-endorsed, made without a further round of
user questions** (assessed as reasonable, low-risk, reversible defaults rather than genuine
forks):
- The GHS 50 / USD 10 threshold reuses the SAME settings mechanism built for the platform fee
  (decision #1), rather than being hardcoded separately — near-zero marginal cost once that
  mechanism exists for one setting.
- Admin-triggered payout does NOT bypass the minimum threshold — avoids two divergent enforcement
  paths for the same money-movement operation. A future explicit override, if ever needed, should
  be its own separately-ticketed, separately-audited exception, not a quiet default here.

**Architect design decisions** (five points, all decisive, no further forks): (1) new generic
`platform_settings` key-value table (`SettingsEnum` confirmed as the intended key namespace, now
gains `platformFeePercentage`/`minimumPayoutAmount` cases), read via `SettingsService::get()` with
env-backed config fallback — not a dedicated payout-only settings mechanism, since decision #3
above explicitly requires reuse; (2) `counsellor_earnings` uses typed snapshot columns (mirroring
`organization_counsellor_compensations`'s established convention for this exact kind of
snapshotted business fact), not a JSON blob, since TT-7.3b needs to query/aggregate these later;
(3) idempotency combines `lockForUpdate()` + claim-by-status-flip (mirrors `RespondToRequestAction`'s
existing lock-then-mutate idiom) with a unique `reference` column (mirrors `transactions.reference`)
as a second layer — flagged as the highest-risk piece of the whole ticket by both prior reviews;
(4) payout webhooks (`transfer.success`/`transfer.failed`/`transfer.reversed`) extend the EXISTING
`ProcessPaystackWebhookJob`'s `match($event)` rather than a second controller/route — Paystack
only supports one webhook URL per integration, so a second route buys no isolation; (5) the exact
Paystack Transfer Recipient API shape for `nuban` vs. `mobile_money` types is flagged as a genuine
engineering spike (no existing `PaystackClient` precedent beyond `/transaction/initialize`/
`/verify`) — the first task inside TT-7.6a, not assumed ahead of time.

**Why**: all four product-owner forks were genuine business/product trade-offs (a hardcoded fee
percentage would need a deploy to change; bank-only payout would exclude a real chunk of this
platform's Ghana-based counsellors) where guessing wrong would be either expensive to reverse or
simply wrong for the target market — consistent with this session's standing practice of
surfacing genuine forks rather than defaulting to the smaller option. The two project-manager
judgment calls and five architect decisions were kept in-flow without a further AskUserQuestion
round because they were reasonable, low-risk, and cheaply reversible engineering defaults, not
consequential product forks — asking about every such call would have been excessive-questions
overreach for decisions an engineering review can responsibly make on its own.
