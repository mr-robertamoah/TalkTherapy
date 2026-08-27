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