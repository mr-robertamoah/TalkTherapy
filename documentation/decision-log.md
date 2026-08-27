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
