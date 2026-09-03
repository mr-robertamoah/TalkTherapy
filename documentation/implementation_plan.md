# TalkTherapy Implementation Plan (Reconciled)

This supersedes `project_plan.md` / `sprint_plan.md` as the working backlog. It folds the
existing Epics/Stories into a Jira-style structure, tags each item's origin (`Existing` = was
already in the old plan, `New` = introduced from the codebase review), and re-sequences
sprints where a new item changes priority. The old docs are left in place as historical record.

Fields: **Key** (Epic-Story) · **Type** · **Priority** (Critical/High/Medium/Low) · **Points**
(rough Fibonacci sizing, not a commitment) · **Source** · **Depends on**

---

## Epic TT-1: Platform Stability & Real-Time Reliability
*Goal: everything below is a pre-existing defect. Nothing new ships until this is dealt with, because it undermines trust in every other feature.*

| Key | Story | Type | Priority | Points | Source | Depends on |
|---|---|---|---|---|---|---|
| TT-1.1 | `UnifiedTherapy.vue` handles Individual + Group edge cases with no residual duplication | Task | High | 8 | Existing (3.1) | — |
| TT-1.2 | Reverb sends duplicate broadcasts/notifications | Bug | Critical | 5 | Existing (2.2) | — |
| TT-1.3 | 502 Bad Gateway when exiting a profile page | Bug | Critical | 3 | Existing (7.3) | — |
| TT-1.4 | Home page post section not centered on all viewports | Bug | Low | 1 | Existing (3.2) | — |
| TT-1.5 | Anonymous-therapy PII leaks into outgoing emails | Bug | Critical | 3 | Existing (5.3) | — |
| TT-1.6 | Rescheduling to an earlier free slot throws a false "prohibition error" | Bug | High | 2 | Existing (4.3) | — |
| TT-1.10 | Remove dead PrivateChannel broadcast in DiscussionUpdatedEvent | Task | Low | — | New (SCRUM-15 follow-up) | — |
| TT-1.11 | Local Docker test runs can silently hit the real dev MySQL database instead of an isolated test DB | Bug | High | — | New (SCRUM-16 follow-up, SCRUM-60) | — |
| TT-1.12 | Several Resources assume a non-deleted related user/counsellor (null-unsafe access pattern) | Task | Low | — | New (SCRUM-16 follow-up, SCRUM-61) | — |

> Note: TT-1.3 is a **different bug** from the infra-level 502 we already fixed this week (that
> one was nginx caching a stale upstream IP after container recreation — an environment issue,
> not application code). This story is almost certainly an actual PHP exception thrown when
> leaving the profile page; it needs its own repro and log dive.

**Sprint:** 1 (unchanged from original plan)

---

## Epic TT-2: Real-Time Discussions & Session Tooling
*Goal: make an active therapy relationship (chat + scheduling) fully usable end-to-end.*

| Key | Story | Type | Priority | Points | Source | Depends on |
|---|---|---|---|---|---|---|
| TT-2.1 | Live chat: sending, receiving, typing indicators, no errors | Story | Critical | 8 | Existing (2.1) | TT-1.2 |
| TT-2.2a | `session_notes` data model — direct `session_id` FK (mirrors `Discussion::session()`'s existing precedent, not polymorphic), `counsellor_id` (author), `content`, soft delete. Deliberately not reusing `Message` (its confidentiality is an opt-in per-message toggle, and was found during review to leak in full over Reverb broadcast regardless — tracked separately as SCRUM-195). | Story | Medium | 2 | New split of existing TT-2.2 (SCRUM-21 `/start-feature` review) | TT-2.1 |
| TT-2.2b | Session note authorization (`Ensure*Action` convention, no admin bypass — confirmed 2026-09-01 this platform's clinical notes are never admin-readable, unlike every other session/therapy action) + CRUD actions/controller/routes. Cross-counsellor isolation on a shared `GroupTherapy` session is the core test target. Post-session editing uses a configurable grace window (`config('session-notes.edit_grace_minutes')`), not instant lock. Extracts the "author-only + live-window" logic into a shared concern for TT-2.3 to reuse later. | Story | Medium | 6 | New split of existing TT-2.2 (SCRUM-21 `/start-feature` review) | TT-2.2a |
| TT-2.2c | `SessionNoteResource` (kept structurally separate from `SessionResource`) + counsellor-facing notes panel, fetch-on-load (no Reverb broadcast — explicit negative-path test coverage required). | Story | Medium | 5 | New split of existing TT-2.2 (SCRUM-21 `/start-feature` review) | TT-2.2b |
| TT-2.3 | Counsellor can annotate a specific chat message with a timestamped note | Story | Medium | 5 | Existing (2.3) | TT-2.1 |
| TT-2.4 | Admin can cap counsellors per discussion | Story | Low | 2 | Existing (2.4) | — |
| TT-2.5 | User can propose a session day/time for counsellor accept/modify | Story | High | 5 | Existing (4.1) | — |
| TT-2.6a | Counsellor-scoped, date-range-bounded session aggregation across `Therapy` + `GroupTherapy` (new `GetCounsellorCalendarSessionsAction`, `SessionResource`/`SessionStatusEnum` reuse, N+1-safe eager loading on both legs of the union, extracts the duplicated addedby-anonymity-masking ternary into a shared helper as the first cross-therapy aggregate surface, self-scoped authorization, reassignment-correct scoping) | Story | High | 8 | New split of existing TT-2.6 (SCRUM-25 `/start-feature` review) | TT-2.5 |
| TT-2.6b | Counsellor calendar UI: custom-built (no calendar library — architect decision) week/month view, event rendering (time/status/individual-vs-group/therapy name), drill-through only (no in-place mutation), individual-vs-group and upcoming-vs-past filtering, empty state mirroring `TherapyRecentSections.vue` | Story | High | 8 | New split of existing TT-2.6 (SCRUM-25 `/start-feature` review) | TT-2.6a |
| TT-2.7 | Fix `groupTherapies` channel-name casing mismatch — group-therapy real-time updates may be silently broken | Bug | High | — | New (SCRUM-15 follow-up) | — |

**Sprint:** 2 (unchanged from original plan).

**TT-2.5 status (2026-09-02)**: Done — split into TT-2.5a/b/c (SCRUM-206/207/208), all merged.
See `documentation/features/scrum-24-session-schedule-proposal.md`.

**TT-2.6 status (2026-09-02)**: went through `/start-feature` (product-owner/project-manager/
architect) and was found undersized at its original 5 points — same pattern as TT-2.2/TT-6.3/
TT-7.2. Split into TT-2.6a (SCRUM-212, backend aggregation) → TT-2.6b (SCRUM-213, frontend
calendar UI), 16 points total. Architect called for a custom-built calendar (no new library —
`package.json` has none today, and this feature's drill-through-only scope doesn't warrant one)
using the existing `date-fns` dependency. Confirmed product decision: a `GroupTherapy` with
multiple active counsellors means each sees that session independently on their own calendar.

**TT-2.7 status (2026-09-02)**: this row and the "should likely be pulled forward" note above were
stale — SCRUM-58 was already fixed and merged (PR #20) and marked Done on 2026-08-29, before this
row was last touched. No further action needed.

**TT-2.2 status (2026-09-01)**: went through `/start-feature` (product-owner/project-manager/
architect) and was found significantly undersized at its original 5 points — same pattern already
seen on TT-6.3/TT-7.2. Split into TT-2.2a (SCRUM-196) → TT-2.2b (SCRUM-197) → TT-2.2c (SCRUM-198),
filed as Jira subtasks of SCRUM-21, ~13 points total. Architect review also surfaced a genuine
pre-existing, unrelated bug — `Message.confidential` is honored on page load but not on Reverb
broadcast — filed separately as SCRUM-195, not folded into this ticket's scope.

---

## Epic TT-3: Video & Audio Sessions — *New*
*Goal: close the single biggest product gap. Sessions today are text-only; a therapy platform without video is a hard sell against any competitor.*

| Key | Story | Type | Priority | Points | Source | Depends on |
|---|---|---|---|---|---|---|
| TT-3.1 | 1:1 video/audio session (WebRTC, signaling over existing Reverb channel) | Story | Critical | 13 | New | TT-1.2, TT-2.1 |
| TT-3.2 | Group therapy video rooms (multi-party) | Story | High | 13 | New | TT-3.1 |
| TT-3.3 | Graceful degrade to audio-only / reconnect on poor bandwidth | Story | Medium | 5 | New | TT-3.1 |
| TT-3.4 | Session recording with explicit two-party consent capture | Story | Low | 8 | New | TT-3.1 |

**Sprint:** 3 — inserted here, ahead of Organizations. Rationale below.

---

## Epic TT-4: Trust, Safety & Crisis Response
*Goal: the epic with the highest ethical stakes in the whole backlog — a mental-health product's safety net has to be real, not aspirational.*

| Key | Story | Type | Priority | Points | Source | Depends on |
|---|---|---|---|---|---|---|
| TT-4.1 | ID Verification badge (or unverified warning) on counsellor profile | Story | High | 3 | Existing (5.1) | — |
| TT-4.2 | Periodic counsellor re-verification / license audit trail | Task | Medium | 5 | New | TT-4.1 |
| TT-4.3 | Counsellor can flag "institutionalisation" recommendation, triggering high-priority alert flow | Story | Critical | 8 | Existing (5.2) | — |
| TT-4.4 | "I need help now" quick-access to crisis hotlines/emergency contacts, visible from any page | Story | Critical | 3 | New | — |
| TT-4.5 | Two-Factor Authentication | Story | Medium | 5 | Existing (5.4) | — |
| TT-4.6 | Password policy enforcement + weak-password profile warning | Story | Medium | 3 | Existing (5.5) | — |
| TT-4.7 | Make Sent-notification counsellor/user type-check explicit instead of implicit recipient-type invariant | Task | Low | — | New (SCRUM-18 follow-up) | — |
| TT-4.8 | Audit per-user group-therapy anonymity (`group_therapy_user.anonymous`) once it's actually read anywhere | Task | Low | — | New (SCRUM-18 follow-up) | — |
| TT-4.9 | Add regression tests asserting anonymous-therapy notifications never leak real names | Task | Medium | — | New (SCRUM-18 follow-up) | — |

> TT-4.4 is deliberately **not** the same as the icebox "AI Emergency" item (see TT-9.3). A
> static hotline/quick-contact button ships in a sprint; an AI-mediated emergency flow is a
> multi-sprint effort with real liability considerations. Ship the simple version first — it's
> the safety net that matters most, and it's a prerequisite for the AI version anyway.

**Sprint:** 4 — TT-4.3 and TT-4.4 pulled forward ahead of TT-4.1/4.2/4.5/4.6 within the sprint;
the rest follows the original plan's timing.

---

## Epic TT-5: Clinical Progress & Personal Tools
*Goal: give users and counsellors a reason to come back beyond "the chat is open" — visible progress and control over their own data.*

| Key | Story | Type | Priority | Points | Source | Depends on |
|---|---|---|---|---|---|---|
| TT-5.1 | Private user journal entries | Story | Medium | 5 | Existing (6.1) | — |
| TT-5.2 | Standardized outcome check-ins (e.g. PHQ-9/GAD-7-style) attached to a `TherapyCase` | Story | High | 8 | New | — |
| TT-5.3 | Export journal / session notes as PDF | Task | Low | 3 | New | TT-5.1 |
| TT-5.4 | In-app notification center (dropdown of DB notifications) | Story | Medium | 5 | Existing (6.2) | — |
| TT-5.5 | Per-event notification channel preferences (email vs in-app) | Story | Low | 3 | Existing (6.3) | TT-5.4 |
| TT-5.6 | More prominent email-verification UX | Task | Low | 2 | Existing (6.4) | — |

**Sprint:** 6

---

## Epic TT-6: Organizations & Membership
*Goal: unchanged from the original plan — the B2B growth lever.*

Full `/start-feature` planning done for SCRUM-111 (product-owner → project-manager → architect,
plus a clarifying round with the requester). TT-6.4 is split into 6.4a/6.4b (was undersized for
all-3-affiliation-paths + versioned compensation history in one story); a new TT-6.5 row tracks
the frontend, split a/b/c so it ships as independently-reviewable PRs.

| Key | Story | Type | Priority | Points | Source | Depends on |
|---|---|---|---|---|---|---|
| TT-6.1 | ✅ **Implemented** (SCRUM-119, PR #57, merged). Organization entity (name, logo, description, contact info) + admin CRUD. `is_provider`/`is_consumer` capability flags, "at least one true" enforced via a DB-level CHECK constraint. Organization verification workflow (registered-org details submitted, platform-admin-approved before the org can transact) — mirrors counsellor licensing (TT-4.1). | Story | High | 8 (revised from 5) | Existing (1.1), revised (SCRUM-111) | — |
| TT-6.2 | ✅ **Implemented** (SCRUM-120, PR #58, merged). Narrowed to the 2 provider-side flows (org→counsellor invite, counsellor→org apply) — the 2 member-side flows moved to TT-6.3 below, since they need `organization_members`, which didn't exist yet. Reuses the existing polymorphic `Request` model. Grows `Request`'s existing per-type dispatch chain (`RespondToRequestAction`/`GetRequestResourceAction`/`EnsureUserCanRespondToRequestAction`) — accepted, tracked debt; a type→handler-map extraction is a worthwhile independent follow-up. | Story | High | 8 | Existing (1.2), revised (SCRUM-111) | TT-6.1 |
| TT-6.4a | ✅ **Implemented** (SCRUM-121, PR #59, merged). Provider-org counsellor affiliation. "(c) direct non-exclusive contracting" turned out not to be a third creation path — it's the guarantee that already holds for TT-6.2's two request flows. `organization_counsellors` row created (`status = pending`) when either request is accepted; counsellor's own platform-verification re-checked at accept time. Self-dealing (a dual-role org paying itself for its own affiliated counsellors) is explicitly allowed — the platform fee still applies regardless of payer. | Story | Medium | 8 | New split of existing TT-6.4 (SCRUM-111) | TT-6.2 |
| TT-6.4b | ✅ **Implemented** (SCRUM-122, PR #61, open). Compensation-terms capture + history: fixed / percentage / free, percentage basis (counsellor's own listed rate, or a rate negotiated at affiliation time) — versioned via a dedicated effective-dated `organization_counsellor_compensations` table, not a reuse of `TransactionStatusHistory`. First terms row activates a `pending` affiliation. Split-calculation/payout math itself is TT-7.6 scope, not built here. Follow-up filed (SCRUM-123): counsellor-side visibility/accountability trail for compensation changes — currently the org admin sets terms unilaterally with zero counsellor-side visibility. | Story | Medium | 5 | New split of existing TT-6.4 (SCRUM-111) | TT-6.4a |
| TT-6.4c | 🔧 **In progress, final sub-ticket implemented** (SCRUM-131, split into 5 sub-tickets SCRUM-146–150). Converts TT-6.4b's unilateral compensation write into a two-party negotiation reusing the existing `Request` accept/reject infrastructure — zero schema changes to `organization_counsellor_compensations` (proposed-but-unaccepted terms live in `Request.data`/new `expires_at`/`round`/`reminder_sent_at` columns on `requests`). Adds a counter-offer capability (not just accept/reject) and a configurable reminder/expiry mechanism (default 7-day window, 5-round cap, both config-driven). Sub-tickets: 1/5 proposal creation ✅ (SCRUM-146, merged), 2/5 accept/reject ✅ (SCRUM-147, merged), 3/5 counter-offer ✅ (SCRUM-148, merged), 4/5 reminder+expiry 🔧 (SCRUM-149, merged, race-safety follow-up PR pending), 5/5 admin-facing negotiation-state API 🔧 (SCRUM-150, implemented, PR pending — backend only, TT-6.5a doesn't exist yet). | Story | Medium | ~27–28 (across 5 sub-tickets) | Follow-up from SCRUM-123 (SCRUM-131) | TT-6.4b |
| TT-6.3a | ✅ **Implemented** (SCRUM-124, PR #62, merged). Consumer-org membership request flows (org→member invite, member→org self-apply, the latter gated on a new `self_apply_enabled` org flag) — the member-side mirror of TT-6.2, split out the same way once M2 planning started. Membership activates immediately on acceptance (no compensation-style gate). | Story | High | 8 (new estimate, split from original TT-6.3) | New split of existing TT-6.3 (SCRUM-111) | TT-6.1 |
| TT-6.3b | ✅ **Implemented** (SCRUM-125, PR #63, open). Billing-mode config per member: retainer (fixed periodic amount regardless of usage) or pay-per-use (per-session/per-therapy, reusing `TherapyPerPaymentEnum`), plus a group-therapy include/exclude flag — mixable per member within one org, effective-dated/append-only (mirrors TT-6.4b). Excluding group therapies blocks that booking under the org context entirely (member can still book independently, outside the org, paying personally) — enforcement itself is TT-7.3a's job, not built here. A member belonging to multiple orgs that share a counsellor must explicitly pick which org an engagement bills through (feeds TT-7.3a). Org-admin dashboard visibility into member activity is scoped to session metadata (dates, frequency, type/category) and invoices only — never clinical notes or the private journal (TT-5.1). Config only here — no live charge execution. | Story | High | 8 (new estimate, split from original TT-6.3) | New split of existing TT-6.3 (SCRUM-111) | TT-6.3a |
| TT-6.6a | Org-scoped list endpoints: an org's own members (+ billing config/history) and affiliated counsellors (+ compensation status/history). Requires converting `OrganizationCounsellor::currentCompensation()`/`OrganizationMember::currentBillingConfig()` from plain `hasMany()->first()` to `latestOfMany()` first (architect-verified: first time either is called across a collection — naive form would N+1). | Story | High | 5 | New split of existing TT-6.5 (SCRUM-111 product-owner/architect review); filed as SCRUM-159 | TT-6.1, TT-6.3a, TT-6.3b, TT-6.4a, TT-6.4b |
| TT-6.6b | "My organizations" list endpoints: counsellor's own `organizationCounsellors()`, user's own `organizationMemberships()`/`administeredOrganizations()`. Self-scoped relation listing. | Story | High | 3 | New split of existing TT-6.5 (SCRUM-111 product-owner/architect review); filed as SCRUM-160 | TT-6.4a, TT-6.3a |
| TT-6.6c | Organization directory/browse endpoint — verified-only (decided 2026-08-29), curated field exposure per this codebase's existing enumeration-oracle discipline. Currently the only way a counsellor/member could discover an org id to apply to at all. Highest-leverage ticket in this batch — blocks both TT-6.5b and TT-6.5c2. | Story | High | 5 | New split of existing TT-6.5 (SCRUM-111 product-owner/architect review); filed as SCRUM-161 | TT-6.1 |
| TT-6.6d | Org-scoped request queue: additive `orWhere` block on `RequestService::getRequests` matching `$user->administeredOrganizations()` (mirrors the existing `$counsellor` block) — architect-mandated to stay small, explicitly NOT bundling the `RespondToRequestAction` type→handler-map extraction TT-6.2 already deferred as separate debt. | Story | Medium | 5 | New split of existing TT-6.5 (SCRUM-111 product-owner/architect review); filed as SCRUM-162 | TT-6.2, TT-6.3a, TT-6.4a/b/c |
| TT-6.6e | Co-admin management + real owner-vs-admin enforcement (decided 2026-08-29: enforce now, not display-only). New `EnsureUserIsOrganizationOwnerAction` + direct add/remove/promote/demote actions on `organization_admins` (architect-recommended: not the Request/respond pattern, no second-party consent involved). Last-owner-remaining invariant enforced. | Story | Medium | 5 | New split of existing TT-6.5 (SCRUM-111 product-owner/architect review); filed as SCRUM-163 | TT-6.1 |
| TT-6.7 | Member self-apply via a shareable, admin-generated organization link — new `LinkTypeEnum` case + `PerformLinkAction` branch, mirroring the existing discussion/guardianship/therapy-counsellor Link pattern. Additive to TT-6.6c's directory (decided 2026-08-29: build both, not either/or — architect confirmed they solve different problems: curated browse vs. targeted single-use grant). | Story | Medium | 5 | New split of existing TT-6.5 (SCRUM-111 product-owner/architect review); filed as SCRUM-164 | TT-6.3a |
| TT-6.5a | Org admin dashboard: profile, member/counsellor list tables (TT-6.6a), request queue (TT-6.6d) — new dedicated `resources/js/Pages/Organization/` page tree, following `resources/js/Pages/Profile/Counsellor/`'s structure rather than `Admin.vue`'s tab/dispatch-table pattern (already flagged as ad-hoc debt). Both provider- and consumer-side sections coexist when an org has both flags set (DB constraint is OR, not XOR). Revised up from the original 5-point estimate given the added list/queue scope. | Story | Medium | 8 (was 5) | New split of existing TT-6.5 (SCRUM-111 product-owner/architect review); filed as SCRUM-165 | TT-6.6a, TT-6.6d, TT-6.1, TT-6.2, TT-6.4a, TT-6.4b/c |
| TT-6.5a2 | Org co-admin management UI (invite/remove/promote screen), split out of TT-6.5a since it depends on TT-6.6e's real owner-vs-admin enforcement. | Story | Low | 3 | New split of existing TT-6.5 (SCRUM-111 product-owner/architect review); filed as SCRUM-166 | TT-6.6e, TT-6.5a |
| TT-6.5b | Counsellor "my organizations" view + apply-to-provider-org flow. Corrected wizard reference: reuses `BecomeCounsellorButton.vue` (modal) + `CounsellorCreationSteps.vue` (progress indicator) — `BecomeCounsellorForm.vue` doesn't exist in this codebase. | Story | Medium | 5 | New split of existing TT-6.5 (SCRUM-111 product-owner/architect review); filed as SCRUM-167 | TT-6.6b, TT-6.6c, TT-6.4a |
| TT-6.5c | Member "your organization" view — read-only: list memberships, org profile, billing-mode display. Narrowed from the original scope; the booking-time "which org pays" selection step is relocated to TT-7.3a's (not yet filed) frontend counterpart, since it's a charge-time concern, not a "view my memberships" one. | Story | Medium | 3 | New split of existing TT-6.5 (SCRUM-111 product-owner/architect review); filed as SCRUM-168 | TT-6.6b, TT-6.3a |
| TT-6.5c2 | ✅ **Implemented** (SCRUM-169, PR #139, merged). Member self-apply UI (browse directory + apply, or follow a shareable org link) — split out of TT-6.5c since its dependency (TT-6.6c and TT-6.7, both being built) wasn't resolved until the 2026-08-29 decision round. Playwright-verified golden path for both the directory-apply and follow-a-link flows. Found while testing: org admins have no UI to actually generate a TT-6.7 self-apply link (backend fully supports it, tested, just no "generate" button anywhere) — filed as SCRUM-214, not fixed here. | Story | Low | 3–5 | New split of existing TT-6.5 (SCRUM-111 product-owner/architect review); filed as SCRUM-169 | TT-6.6c, TT-6.7 |

**Epic status (2026-09-01): TT-6 fully implemented.** All rows above (TT-6.1 through TT-6.5c2)
are merged; epic SCRUM-10 transitioned to Done. One known gap tracked separately (SCRUM-214,
org-admin self-apply link generation UI) — doesn't block epic closure since the backend it's
missing a button for was itself never part of any TT-6 row's acceptance criteria.

**M1 status (2026-08-27): complete.** TT-6.1/6.2/6.4a/6.4b (SCRUM-119–122) all merged.

**M2 status (2026-08-27): complete.** TT-6.3 was split into TT-6.3a (SCRUM-124, membership
request flows) and TT-6.3b (SCRUM-125, billing-mode config) before implementation started,
mirroring the TT-6.2/6.4a/6.4b split on the provider side. SCRUM-124 merged (PR #62); SCRUM-125
(PR #63) open awaiting review/merge.

**M4 status (2026-08-29): restructured.** Went through a full `/start-feature` product-owner/
project-manager/architect pass. What was scoped as a 13-point frontend-only ticket (TT-6.5a/b/c)
turned out to need real new backend work first — no list endpoint for an org's own members/
counsellors, no "my organizations" endpoint, no organization directory (so a counsellor/member
had no way to discover an org id to apply to at all), no org-scoped request queue for admins, no
co-admin management. Split into **M4a** (TT-6.6a–e, new backend enablement, ~23 pts) which blocks
**M4b** (TT-6.5a/a2/b/c/c2, restructured frontend, ~22–24 pts) per-ticket rather than
milestone-wide, plus **TT-6.7** (shareable self-apply link, additive to TT-6.6c, ~5 pts). Total
~45–52 points, up from the original 13 — same undersizing pattern as TT-6.3/TT-7.2 on first pass.
Three decisions made by the user during planning: (1) owner-vs-admin roles get real behavioral
enforcement now, not just a display badge; (2) organization discovery ships as both a directory
(TT-6.6c) and a shareable admin-generated link (TT-6.7), not either/or; (3) the directory is
verified-only. "Sponsored by [org]" indicator on therapy/counsellor cards (SCRUM-111's third
flagged open item) deferred to a future TT-7.3a-adjacent ticket per product-owner/project-manager
agreement, since it depends on TT-7.3a (not yet built) and an undecided definition of "sponsored"
over time. Full history in `documentation/decision-log.md`'s 2026-08-29 entries.

**Sprint:** 5 — combined with the payments foundation (TT-7.1/7.2). **Dependency correction**
(previously stated too broadly below TT-7): TT-6.1/6.2/6.4a/6.4b/6.3a/6.3b — the full org data
model and config layer — have **no** TT-7.1 dependency; only the live-charge-execution slice
(TT-7.3a, tracked under TT-7 below) needs both TT-6.3b and TT-7.1. All of TT-6.1 through TT-6.3b
shipped (or are shipping) in parallel with TT-7.1 rather than waiting on it.

---

## Epic TT-7: Payments & Billing — *New*
*Goal: neither counsellor pricing display nor Org subsidized rates mean anything without a real way to move money.*

TT-7.2 (SCRUM-47) went through product-owner review and was found to be significantly
undersized as a single 3-point stub — no counsellor-pricing concept exists anywhere today,
pricing is genuinely dual-mode (flat vs. per-service-type), and currency is free-text
everywhere in the system, not just on the pricing field. Split into TT-7.2a (shared currency
list + retrofit, prerequisite) → TT-7.2b (pricing data model + API) → TT-7.2c (pricing UI),
mirroring the TT-6.4a/b/c precedent. This also absorbs the currency-validation slice of TT-7.4
— see that row's note.

| Key | Story | Type | Priority | Points | Source | Depends on |
|---|---|---|---|---|---|---|
| TT-7.1 | ✅ **Implemented** (SCRUM-110, merged). Transaction/payment model (full audit-trail status history) + Paystack charge initiation & webhook/callback plumbing, test-mode end-to-end for a single simple case. TalkTherapy is sole merchant of record (no per-counsellor subaccounts). | Story | High | 13 | New (SCRUM-110, revised from 8) | — |
| TT-7.2a | ✅ **Implemented** (SCRUM-153, PR #90, merged). Platform-wide supported-currency list (config-driven allow-list + default) applied everywhere currency is used — retrofits `Therapy`/`GroupTherapy` `payment_data->currency` validation (`CreateTherapyRequest`/`UpdateTherapyRequest`/`CreateGroupTherapyRequest`/`UpdateGroupTherapyRequest`, currently unconstrained `string`) and adds a defense-in-depth check at charge-initiation (`InitiatePaystackChargeAction`/`TransactionService`). Absorbs TT-7.4's "replace free-text currency with a supported-list check" line item — see TT-7.4 note. Needs a one-off audit of existing `payment_data->currency` values before enforcement goes live (no schema change since `payment_data` is JSON, but a legacy free-text value outside the new list must not silently break reads of old rows). | Story | High | 5 | New split of existing TT-7.2/TT-7.4 (SCRUM-47 product-owner review) | TT-7.1 |
| TT-7.2b | ✅ **Implemented** (SCRUM-154, PR pending). Counsellor pricing data model + backend API: new `counsellor_pricings` table supporting EITHER a single flat/default rate OR distinct per-service-type overrides (individual vs. group, online vs. in-person, per-session vs. per-package — reuses `SessionTypeEnum`/`TherapyPerPaymentEnum`, new `TherapyTypeEnum` for individual/group) — counsellor's choice, not forced into one mode. Strictly informational/display-only — does NOT touch `CreateTherapyRequest`, `GetPayableAmountAction`, or `EnsureCanInitiateChargeAction`; a client still proposes their own amount at booking time. `COUNSELLOR_RATE` (`OrganizationCounsellorCompensationBasisEnum`) stays unresolved/unused. | Story | Medium | 8 | New split of existing TT-7.2 (SCRUM-47 product-owner review) | TT-7.2a |
| TT-7.2c | ✅ **Implemented** (SCRUM-155, PR pending). Counsellor profile pricing UI: edit form (flat-vs-override mode toggle, override rows, currency select scoped to TT-7.2a's supported list) on the counsellor's own profile edit flow, plus public display of the listed rate(s) on `Profile/Counsellor/Show.vue`. Also added a small backend addition (`DELETE /counsellor/{counsellorId}/pricings`) discovered while building the UI — the merged TT-7.2b backend had no way to clear pricing entirely. Playwright-verified golden path. | Story | Medium | 5 | New split of existing TT-7.2 (SCRUM-47 product-owner review) | TT-7.2b |
| TT-7.3a | ✅ **Implemented** (SCRUM-48, PR #91, merged). Org-as-payer charge initiation only: `organization_id` is an explicit, validated input at charge time (never inferred from membership alone). Requires an active `organization_members` row for the payer, active `organization_counsellors` coverage for **every** currently-active counsellor on the therapy/group-therapy being paid for, current org verification, and `PAY_PER_USE` billing mode (never `RETAINER`); a `GroupTherapy` also needs the member's `include_group_therapies` flag set. Records which org financed the charge on `transactions.organization_id` (not on `Therapy`/`GroupTherapy`/`Session` — a therapy can have many charge events with different org attribution over time). Paystack charge itself unchanged (still the member's own card/email — no org payment-instrument concept). | Story | Medium | 5 | New split of existing TT-7.3 (SCRUM-111); scope finalized via product-owner/project-manager/architect review (SCRUM-48) | TT-6.3b, TT-7.1 |
| TT-7.3b | Full org-billing lifecycle: payout to affiliated counsellors per agreed compensation terms (gross amount → platform fee → counsellor/org split), refund handling for org-paid transactions. Needs a platform-fee concept, which doesn't exist yet anywhere in TT-7 — track as a precondition for TT-7.6 independent of orgs. **Resequenced (2026-09-02, SCRUM-223 product-owner/project-manager review, user decision)**: this now BLOCKS TT-7.7 rather than depending on it — the user chose to include org-paid transactions in TT-7.7's refund scope from the start, which requires TT-7.3b's org-refund-reconciliation piece (notifying the org admin, adjusting compensation) to exist before TT-7.7's refund execution (TT-7.7d) is built, not after. TT-7.3b in turn still needs TT-7.6 (payout) to exist first, since there's no payout to reconcile against otherwise. | Story | Medium | 8 | New split of existing TT-7.3 (SCRUM-111) | TT-7.3a, TT-7.6 |
| TT-7.4a | ✅ **Implemented**. Payment-status exposure plumbing: `latestTransaction` relation (`ofMany`) on the models using `TherapyTrait`/`Session`, exposed on `TherapyResource`/`GroupTherapyResource`/`SessionResource` (eager-loaded at every collection call site — `SessionService::getSessions()` included — to avoid an N+1 on the paginated session list); wires the `transactionStatus` flash value `TransactionController::callback` already sets through `TherapyController::getTherapy`/`GroupTherapyController::getGroupTherapy` as a per-render Inertia prop, mirroring the existing `session('session')` precedent in both controllers. Prerequisite for TT-7.4b/c. | Story | High | 3 | New split of existing TT-7.4 (SCRUM-118 product-owner/architect review); filed as SCRUM-156 | TT-7.1 |
| TT-7.4b | ✅ **Implemented**. Client Pay button + redirect + return-flow status UI, individual therapy & session only (group therapy explicitly excluded — see TT-7.4d). New `usePayment(therapy, therapyType)` composable (matching `useTherapyState`/`useAlert`/`useErrorHandler` conventions) owns initiate/redirect (`window.location.href`, never Inertia `router.visit`)/status/dismiss logic, reusing `useTherapyState`'s `computedIsParticipant`/`computedIsCounsellor` for the pay-gating check; consumed by both `TherapyPaymentDetails.vue` and `UnifiedTherapy.vue`'s session-actions modal rather than logic living in one and being reused by the other. Dismissible one-time status banner (not persistent), all `TransactionException` messages surfaced distinctly, "abandoned" treated as recoverable not terminal. Also fixed a discovered pre-existing bug (`TherapyActiveHeader.vue`'s "show session information" toggle was dead, making the Session Actions Modal unreachable for anyone). Playwright-verified as far as this dev environment's missing Paystack sandbox credentials allow (button gating, error path, paid-state display; full checkout-and-return cycle not completable here). | Story | High | 8 | New split of existing TT-7.4 (SCRUM-118 product-owner/architect review); filed as SCRUM-157 | TT-7.4a |
| TT-7.4c | ✅ **Implemented**. Counsellor read-only payment-status indicator on the same two surfaces as TT-7.4b — status text only ("Paid"/"Payment failed"/"Awaiting payment"), Pay control never rendered for the counsellor (backend already 403s via `EnsureCanPayForModelAction`; this is UI-layer control-hiding, reviewed separately as a distinct authorization-adjacent concern). TT-7.4 (SCRUM-118's payment UI) is now fully implemented for individual therapies/sessions — only TT-7.4d (group therapy) remains, as its own future epic. | Story | Medium | 3 | New split of existing TT-7.4 (SCRUM-118 product-owner/architect review); filed as SCRUM-158 | TT-7.4a |
| TT-7.4d | Group-therapy per-member Pay UI — **blocked/deferred**, own future epic, not part of this push. `Transaction` is currently a single `morphMany` per model (`EnsureCanInitiateChargeAction` blocks any further charge once one successful transaction exists against that model), so group-therapy payment today is "first payer covers the whole group." Product decision (SCRUM-118 review): each member should pay their own share, which needs a new per-member payment record/schema change beyond what TT-7.1 supports — out of scope until scheduled as its own epic. | Story | — | TBD | New split of existing TT-7.4 (SCRUM-118 product-owner review) — decision made, sizing pending its own epic | TT-7.4a, new backend schema work |
| TT-7.4-retry | ✅ **Implemented** (SCRUM-222, PR #150, open). Retry-on-failure UI (TT-7.4's original second clause): distinct "try payment again" wording/styling on `TherapyPaymentDetails.vue`'s PER_THERAPY pay button and `UnifiedTherapy.vue`'s PER_SESSION session-actions-modal pay button when the persistent `paymentStatus` field (TT-7.4a's `latestTransaction`-backed field, already used by TT-7.4c's counsellor-facing indicator) is `FAILED` or `ABANDONED` (product-owner review, SCRUM-222, confirmed both non-terminal/retriable) — not the one-shot `transactionStatus` flash value `usePayment.js` uses for the post-redirect banner, so the distinct copy survives reload. Frontend-only, no backend change. Full attempt-history list and persistent-banner alternatives considered and rejected as out of scope. | Task | Medium | 2 | Split out of original TT-7.4 (SCRUM-118 project-manager review); scoped (SCRUM-222 product-owner review, 2026-09-02) | TT-7.4a, TT-7.4b |
| TT-7.5a | Payment-gated access — individual Therapy/Session (strict vs. trust-based, default trust-based). Split (product-owner/project-manager/architect review, SCRUM-215, 2026-09-02) into: **a1** per-Therapy gate-mode setting, stored as `payment_data->strictPaymentGate` (camelCase, matching `per`/`amount`/`currency`/`inPersonAmount`'s existing convention, not a new `therapies` column — architect-recommended), wired through `CreateTherapyRequest`/`UpdateTherapyRequest` → `UpdateTherapyAction`'s existing `setValueOnPaymentData()`/`clearPaymentData()` machinery, cleared to `false` when `paymentType` is `free` — 3pts; **a2** first-access grant persistence: new narrow `payment_access_grants` table (`user_id`, `payable_type`/`payable_id` morph, `granted_at`, unique on the triple, `firstOrCreate`-based for race-safety, mirrors TT-10.1's composite-unique precedent) written once via a new `Grant*Action` — deliberately NOT derived from `Transaction.status` at read time, since that becomes silently wrong the moment TT-7.7 (refunds) starts mutating an existing transaction's status — 5pts; **a3** page-load enforcement (extend `EnsureUserHasAccessToTherapyAction` for the PER_THERAPY strict case) — 3pts; **a4/a5** session- and chat-level enforcement for the PER_SESSION case and `MessageService`'s 4 independently-duplicated call sites (`getSessionMessages`/`getDiscussionMessages`/`getTherapyTopicMessages`/`getMessageReplies`) — architect: extract ONE shared gate-satisfaction check all these call sites delegate to, rather than a third/fourth independent copy; `MessageService` sites use a plain boolean early-return matching their existing `isNotParticipant`/`return []` idiom, NOT the new `PaymentRequiredException` (that's for controller/page-load call sites only, via `ResolvesExceptionResponse`) — 10pts combined; **a6** frontend (counsellor strict/trust toggle on Therapy create/edit form + client-facing "locked, pay to access" state, likely an Inertia prop mirroring TT-7.4a's `transactionStatus` pattern rather than an exception-driven redirect) — 5pts. Sequencing: a1 → a2 → a3 → {a4, a5} → a6. Whether to also consolidate `MessageService`'s pre-existing duplicated authorization onto `EnsureUserHasAccessToTherapyAction` as part of this work, versus tracking it as separate debt (SCRUM-211/SCRUM-214 pattern), is an open call needing explicit sign-off before a4/a5 starts. | Story | High | 26 (provisional) | New split of TT-7.5 (SCRUM-215, product-owner/project-manager/architect review 2026-09-02) | TT-7.1, TT-7.4a (both done) |
| TT-7.5b | GroupTherapy payment-gated access — **blocked**, cannot be scoped in detail until TT-7.4d (per-member group-therapy payment) is itself scoped; will need GroupTherapy-equivalents of TT-7.5a's a2–a6 once TT-7.4d's schema exists. Uses the identical `payment_data` key name TT-7.5a establishes so TT-7.4d can reuse the shape rather than inventing a second one. Public/non-participant visibility unaffected, same as TT-7.5a. No technical dependency on TT-7.5a's own progress, but sits idle in the backlog until someone schedules a TT-7.4d scoping pass. | Story | — | TBD | New split of TT-7.5 (SCRUM-215, product-owner/project-manager/architect review 2026-09-02) | TT-7.4d |
| TT-7.6a | ✅ **Implemented** (SCRUM-225, PR #153, merged). Payout-destination onboarding: Paystack Transfer Recipient creation for both bank account (`nuban`) and Ghanaian mobile money, `recipient_code` + masked display storage only (raw account numbers never persisted post-creation), destination-change email notification (account-takeover mitigation), gated on `Counsellor::isVerified()`. First task: a time-boxed engineering spike against Paystack's sandbox confirming the exact request/response shape for both `nuban` and `mobile_money` recipient creation, and currency-corridor support (does Transfer cleanly support every currency this platform accepts, e.g. USD alongside GHS) — before finalizing this ticket's scope, per architect instruction (no existing `PaystackClient` precedent beyond `/transaction/initialize`/`/verify`). | Story | Medium | 8 | New split of existing TT-7.6 (SCRUM-224 product-owner/project-manager/architect review, 2026-09-02) | TT-7.1 |
| TT-7.6b | ✅ **Implemented** (SCRUM-226, PR #152, merged). Earnings ledger: new `counsellor_earnings` table (typed columns — `transaction_id`, `counsellor_id`, `gross_amount`/`fee_amount`/`net_amount`, `currency`, `share_basis`/`share_percentage` snapshot for GroupTherapy rows, `status`: pending/processing/paid_out/failed — mirrors `organization_counsellor_compensations`'s typed-column convention, not a JSON blob), one row per entitled counsellor on every successful non-org (`organization_id IS NULL`) `Transaction` — 100% for individual Therapy/Session, split per `shareEqually`/`sharePercentage` for a GroupTherapy limited to counsellors ACTIVE (`state` pivot on `counsellor_group_therapy`) at transaction-success time, snapshotted at creation, never recalculated later. Companion `counsellor_earning_status_histories` table mirrors `TransactionStatusHistory`'s audit-trail shape (own table, not a retrofit — different subject). New `Transaction::earnings()` hasMany relation alongside the existing `statusHistories()`. Builds a new generic `platform_settings` key/value table (`SettingsEnum` gains `platformFeePercentage`/`minimumPayoutAmount` cases — the enum already existed empty, confirmed as the intended key namespace) with a `SettingsService::get()` reading the DB row and falling back to `config()`/env-backed defaults if unset; itemized gross/fee/net breakdown disclosed to the counsellor (TT-6.4c compensation-transparency precedent). Same mechanism holds the GHS 50 / USD 10 minimum-payout-threshold value. Explicit regression test: never reads/writes `payment_access_grants`. | Story | Medium | 13 | New split of existing TT-7.6 (SCRUM-224 product-owner/project-manager/architect review, 2026-09-02) | TT-7.1 |
| TT-7.6c | ✅ **Implemented** (SCRUM-227, PR #155, merged). Payout execution: new `counsellor_payouts` table (unique `reference` column, mirrors `transactions.reference`'s idempotency role). Counsellor self-service + admin-on-behalf-of trigger (admin does NOT bypass the minimum threshold — one enforcement path, not two divergent ones, for the same money-movement operation) wraps `DB::transaction()` around `CounsellorEarning::lockForUpdate()->where('status', 'pending')`, claiming eligible rows by flipping them to `processing` inside the same locked transaction that inserts the `counsellor_payouts` row (mirrors `RespondToRequestAction`'s existing lock-then-mutate idiom) — a concurrent second trigger finds no `pending` rows left to claim. Real Paystack Transfer call isolated in a queued job, never inline in the request/response cycle (mirrors TT-7.7d's isolation-from-dispatcher precedent). `transfer.success`/`transfer.failed`/`transfer.reversed` handled as new `match()` cases in the EXISTING `ProcessPaystackWebhookJob` (same route/signature-verification gate — architect: one webhook URL, no isolation benefit from a second route/controller), returning affected ledger rows to `pending` on failure/reversal (money never silently disappears) and notifying counsellor + admin with a reason; retry = re-trigger payout, no bespoke retry mechanism. Full audit trail on `counsellor_payouts`. Explicit regression test: never reads/writes `payment_access_grants`. Highest-risk ticket in this split — real money movement. | Story | High | 13 | New split of existing TT-7.6 (SCRUM-224 product-owner/project-manager/architect review, 2026-09-02) | TT-7.6a, TT-7.6b |
| TT-7.6d | Counsellor-facing frontend: payout-destination onboarding form (bank/momo, verified-gating messaging) + earnings/balance screen with itemized gross/fee/net breakdown (TT-6.4c pattern) and withdraw control, including a retry-after-failure state mirroring TT-7.4-retry's distinct copy convention. Playwright-verified golden path. | Story | Medium | 8 | New split of existing TT-7.6 (SCRUM-224 product-owner/project-manager/architect review, 2026-09-02) | TT-7.6a, TT-7.6b, TT-7.6c |
| TT-7.6e | Admin-facing frontend: platform-fee-setting form, admin payout-trigger UI (select counsellor, trigger, view result), payout audit/history table (mirrors TT-6.6d/TT-7.7c queue-table precedent). | Story | Low | 5 | New split of existing TT-7.6 (SCRUM-224 product-owner/project-manager/architect review, 2026-09-02) | TT-7.6b, TT-7.6c |
| TT-7.7a | Refund data model & eligibility: new refund audit-trail schema mirroring `TransactionStatusHistory`'s per-state-change-is-a-row philosophy (architect recommendation, 2026-09-02: a separate `refunds` table — not a `TransactionStatusEnum::refunded` case — since `status` already means "did the charge succeed" for existing consumers, and refunds are naturally 1:many per transaction, not 1:1); the client-request/admin-approval state machine reuses the existing polymorphic `Request` model (new `RequestTypeEnum::refund` case, dispatched via `RespondToRequestAction` to a new `RespondToRefundRequestAction`, mirroring the `organizationCounsellorCompensationChange` precedent) for the ask/approve step ONLY — the actual Paystack call is isolated in its own queued job (TT-7.7d), never inline in the shared request-response dispatcher (architect: keeps a real external money-moving side effect out of a dispatcher already flagged as growing debt, SCRUM-119/120). `EnsureTransactionIsRefundEligibleAction` (SUCCESS-only, full-refund-only, no duplicate pending request; org-paid-transaction eligibility check included, per the user's 2026-09-02 decision to keep org-paid transactions in scope from the start). No FK/read/write relationship to `payment_access_grants`. | Story | Medium | 5 | New split of existing TT-7.7 (SCRUM-223 product-owner/architect review) | TT-7.1, TT-7.2a, TT-7.3b |
| TT-7.7b | Client refund request: backend (`RequestRefundAction`, client-scoped-to-own-transaction, reason required, one-active-request-per-transaction guard) + admin notification on new request + client-facing request control/reason form/pending-status display on both `TherapyPaymentDetails.vue` (PER_THERAPY) and `UnifiedTherapy.vue` (PER_SESSION), mirroring TT-7.4b's dual-surface pattern. | Story | Medium | 8 | New split of existing TT-7.7 (SCRUM-223 product-owner review) | TT-7.7a, TT-7.4a, TT-7.4b |
| TT-7.7c | Admin review queue: admin-only paginated pending-requests list (mirrors TT-6.6d/TT-6.5a's queue-table precedent) + `RejectRefundRequestAction` (note required, notifies client immediately, terminal, idempotent) + `ApproveRefundRequestAction` (admin-only, idempotency-guarded against double-approval/retry, transitions to `approved`/`processing` — does not itself call Paystack). | Story | Medium | 8 | New split of existing TT-7.7 (SCRUM-223 product-owner review) | TT-7.7a, TT-7.7b |
| TT-7.7d | Real Paystack refund execution: `PaystackClient::refundTransaction()` (new method, mirrors `initializeTransaction()`), fired on TT-7.7c's approve transition via a queued job (not inline in `RespondToRequestAction`); `refund.processed`/`refund.failed` webhook handling mirroring `ProcessPaystackWebhookJob`'s existing queued/signature-verified pattern, updating the `refunds` table directly while leaving the original transaction's `TransactionStatusHistory` rows untouched; idempotency guard so a retried webhook or a re-approval attempt can never double-refund. Org-paid transactions trigger TT-7.3b's reconciliation (org notification, compensation adjustment) here. Explicit regression test (product-owner-mandated): refunding a transaction never creates, reads, or writes any `payment_access_grants` row. | Story | High | 8 | New split of existing TT-7.7 (SCRUM-223 product-owner review) | TT-7.7c, TT-7.1, TT-7.3b |
| TT-7.7e | Client outcome notification (refunded/refund-failed copy that MUST explicitly state platform/therapy access is unaffected — product-owner-reviewed, mental-health trust requirement) + counsellor-facing payment-status indicator (TT-7.4c) extended to show a "Refunded" state instead of a stale "Paid" label. | Story | Medium | 5 | New split of existing TT-7.7 (SCRUM-223 product-owner review) | TT-7.7d, TT-7.4c |
| TT-7.8 | ~~Anonymity-safe checkout~~ — resolved, no action needed: Paystack's checkout/receipt is only ever shown to the paying user themselves, never to the counsellor or any other party, so the existing anonymity guarantee (which governs what *other people* see) is unaffected. | Task | — | — | New (SCRUM-110) — resolved | — |
| TT-7.9 | Multi-currency support: forex conversion with an applied markup, clearly disclosed to the user at payment time | Story | Medium | 8-13 (TBD) | New (SCRUM-110) — decision made to support multi-currency with disclosed markup; scoped as its own follow-up ticket, not part of TT-7.1's single-currency M1 | TT-7.1, TT-7.4b |

**Sprint:** 5 (TT-7.1 alongside TT-6). TT-7.4a/b/c (all done) were fast-follows immediately
after TT-7.1 landed. TT-7.5 (now TT-7.5a, SCRUM-215, done) outgrew fast-follow sizing at 26
points — same undersizing-on-first-pass pattern as TT-6.3/TT-2.2/TT-2.6/TT-7.2 — and was tracked
as its own mini-milestone (a1→a2→a3→{a4,a5}→a6) rather than a single-PR follow-up;
TT-7.5b (GroupTherapy) is unscheduled, blocked on TT-7.4d (itself unscoped). TT-6.1
through TT-6.3b (Organizations, SCRUM-111) have no payments dependency at all and can proceed in
full parallel with TT-7.1; only TT-7.3a (charge-initiation, done) depends on both TT-6.3b and
TT-7.1.

**TT-7.6/TT-7.3b/TT-7.7 status (2026-09-02)**: TT-7.7 (refund handling, SCRUM-223) went through
product-owner/project-manager/architect review and, like every other TT-7 sub-story reviewed so
far, was found significantly undersized at its original 5-point estimate — split into TT-7.7a-e,
34 points total (see rows above). The user resolved product-owner's org-paid-transaction fork
toward the largest option: org-paid transactions stay in TT-7.7's scope, but only once TT-7.6
(payout) and TT-7.3b (org billing reconciliation) both ship first — this **reverses** the
previous dependency direction (TT-7.3b used to depend on TT-7.7; now TT-7.7 depends on TT-7.3b,
which depends on TT-7.6). TT-7.6, previously "own Epic once scheduled, not a TT-7 sub-story," is
promoted to the active next unit of work as a result — it needs its own `/start-feature` pass
before TT-7.3b or TT-7.7 can proceed. TT-7.9 (multi-currency) remains a larger, separately-
scheduled follow-up, unaffected by this resequencing.

**TT-7.6 split finalized (2026-09-02, SCRUM-224 product-owner/project-manager/architect
review)**: went through its own `/start-feature` pass and, like every other TT-7 sub-story
reviewed so far, grew well past its 13-point provisional estimate — split into TT-7.6a–e, 47
points total (see rows above). Four confirmed forks from user review: (1) platform fee is a
platform-super-admin-configurable setting with an env-var fallback, not hardcoded — genuinely new
settings infrastructure, since none exists in this codebase today (`SettingsEnum` was an empty
stub, confirmed by architect as the intended key namespace for exactly this); (2) payout supports
both bank account and Ghanaian mobile money as Paystack Transfer Recipient destinations; (3)
minimum payout threshold is fixed at GHS 50 / USD 10, reusing (2)'s settings mechanism rather than
hardcoded; (4) KYC depth is Paystack's own account-name-match check plus the counsellor's existing
platform verification — no new identity-verification subsystem. Two project-manager judgment
calls, both architect-endorsed: admin-triggered payout does NOT bypass the minimum threshold (one
enforcement path, not two, for the same money-movement operation); the frontend split into
counsellor-facing (TT-7.6d) and admin-facing (TT-7.6e) tickets rather than one oversized frontend
ticket, mirroring the TT-6.5/TT-7.5a precedent. TT-7.6a and TT-7.6b are mutually independent
(parallelizable); TT-7.6c depends on both; TT-7.6d/e depend on all three backend tickets. A
Paystack Transfer API request/response-shape and currency-corridor spike is the first task inside
TT-7.6a, not resolved ahead of time. Full rationale: `documentation/decision-log.md`'s 2026-09-02
"SCRUM-224" entry.

TT-7.2 (now split a/b/c, 18 points total vs. the original 3-point estimate) is too large to
treat as a same-sprint fast-follow alongside TT-7.1 the way TT-7.4/7.5/7.7 are — plan TT-7.2a
in Sprint 5 (low-risk, currency-list prerequisite, worth landing early) and TT-7.2b/TT-7.2c
as Sprint 6 carryover, sequenced strictly a → b → c. **All three landed** (SCRUM-153/154/155) —
TT-7.2 is fully implemented.

---

## Epic TT-8: Admin & Operations
| Key | Story | Type | Priority | Points | Source | Depends on |
|---|---|---|---|---|---|---|
| TT-8.1 | Admin dashboard: users, active sessions, system health | Story | High | 8 | Existing (7.1) | — |
| TT-8.2 | Data-collection transparency page (GDPR-style "what we know about you") | Story | Medium | 5 | Existing (7.2) | — |

**Sprint:** 7 — combined with the remaining TT-4 security items (2FA, password policy, audit trail) since both are "hardening" work with similar review overhead.

---

## Epic TT-9: Profile & Growth
| Key | Story | Type | Priority | Points | Source | Depends on |
|---|---|---|---|---|---|---|
| TT-9.1 | Shareable, easy-to-copy profile links | Task | Low | 2 | Existing (8.2) | — |
| TT-9.2 | "Get Started" button in HowTo modal closes + scrolls to start | Task | Low | 1 | Existing (3.3) | — |
| TT-9.3 | Multilingual support | Story | Low | 13 | Existing (8.3) — Icebox | — |
| TT-9.4 | AI-mediated emergency assistance | Story | Low | 13 | Existing (8.4) — Icebox | TT-4.4 |

**Sprint:** 8 (icebox items stay unscheduled until a sprint has slack)

---

## Epic TT-10: File Attachments & Profile Media — *New*
*Goal: standardize the underused `fileables` polymorphic pivot (today used untagged by
License/Post/Report/Message) so Counsellor avatar/cover stop being one-off FK columns, then use
that standardized mechanism to ship two capabilities that don't exist today: Organization logo
upload and a plain User profile avatar. Also closes a confirmed zero-test-coverage gap on the one
upload flow that's fully built and live today (Counsellor avatar/cover).*

Full `/start-feature` planning done for SCRUM-182 (product-owner → project-manager → architect
review, 2026-08-30). Architect resolved the plan's one hard-blocking design question: a bare
`morphToMany(...)->wherePivot('tag', ...)` relation returns a `Collection`, not `File|null`, which
would have silently broken `CounsellorResource`'s `$this->avatar?->url` and
`OrganizationDirectoryResource`'s `$this->logo?->url`. Fix: a two-method split per slot — a
`*File()` `MorphToMany` relation (`withPivotValue('tag', '...')`) plus a `get*Attribute()`
accessor reading `$this->*File->first()`, so existing resources need zero changes. A composite
unique index `(fileable_type, fileable_id, tag)` on `fileables` enforces at-most-one-row-per-tag
at the DB layer without affecting the four existing untagged (`tag IS NULL`) consumers, since SQL
treats each `NULL` as distinct in a unique index.

**Correction (2026-08-31, during TT-10.2 implementation)**: the method above is `withPivotValue()`,
not `wherePivotValue()` -- the latter doesn't exist on Eloquent's `MorphToMany`/`BelongsToMany` in
this Laravel version and is silently absorbed by the dynamic-where-clause magic (`where('pivot_value',
...)`) instead of throwing, so the mistake produces no error, just a `tag` column that's always NULL.
Caught via TT-10.2's Feature tests actually asserting the DB state, not just a 302/no-errors
response. `TT-10.1`'s migration and this plan both originally said `wherePivotValue`; every TT-10
sub-ticket implementing a `*File()` relation should use `withPivotValue()`.

**User decisions (2026-08-30, plan approved)**: file validation is in scope and must be enforced
on *both* frontend and backend (not backend-only, no longer contingent — folded into TT-10.8
below). Frontend uploads across all three surfaces (counsellor avatar/cover, org logo, user
avatar) should share one consistent component and feel, not three bespoke implementations —
`UpdateCounsellorImages.vue`'s existing pattern is extracted into a shared, reusable upload
component (new TT-10.3) that org logo and user avatar consume, and counsellor avatar/cover is
refactored onto it too for visual/behavioral consistency (not left as a legacy one-off).

Jira tickets filed as standalone issues (SCRUM-182 is issue type "Feature", same hierarchy level
as Story/Task — not an Epic — so children are linked via "Relates"/"Blocks" issue links rather
than the `parent` field).

| Key | Jira | Story | Type | Priority | Points | Source | Depends on |
|---|---|---|---|---|---|---|---|
| TT-10.1 | SCRUM-183 | `fileables` gets a nullable `tag` column + composite unique index `(fileable_type, fileable_id, tag)` (architect-approved shape — blocks every other row below) + regression coverage confirming License/Post/Report/Message's existing untagged usage is unaffected | Task | High | 5 | New (SCRUM-182) | — |
| TT-10.2 | SCRUM-184 | Counsellor avatar/cover migrated onto tagged `fileables` via `avatarFile()`/`coverFile()` relations + `getAvatarAttribute()`/`getCoverAttribute()` accessors (drop `avatar_id`/`cover_id` FK usage in a later, separate migration only after a verified backfill) + `UpdateCounsellorAction` rewritten to `sync()` against the tagged relations instead of writing FK columns directly + first-ever test coverage for the counsellor avatar/cover upload/replace/delete flow | Story | High | 8 | New (SCRUM-182) | TT-10.1 |
| TT-10.3 | SCRUM-185 | Shared, reusable file-upload frontend component — extracted from `UpdateCounsellorImages.vue`'s existing change/remove/restore-on-cancel pattern (preview, replace, remove, `Avatar.vue`-style empty-state fallback); counsellor avatar/cover UI refactored onto it so all upload surfaces in the app share one consistent look and interaction, not three one-off implementations | Task | High | 3 | New (SCRUM-182) — user-requested consistency pass | — |
| TT-10.4 | SCRUM-186 | Organization logo upload — backend: endpoint, tagged `logo` fileable (`logoFile()`/`getLogoAttribute()`), reuses `EnsureUserIsOrganizationAdminAction`, `OrganizationResource` exposes `logo` (currently missing entirely) | Story | Medium | 5 | New (SCRUM-182) | TT-10.1 |
| TT-10.5 | SCRUM-187 | Organization logo upload — frontend: uses TT-10.3's shared upload component, wired into the org dashboard/edit flow | Task | Medium | 3 | New (SCRUM-182) | TT-10.3, TT-10.4 |
| TT-10.6 | SCRUM-188 | User avatar — backend: new self-service-only upload/delete endpoint off `/profile`, tagged `avatar` fileable on `User`, authorization built from scratch, `UserResource` exposes `avatar` (`UserComponent.vue` already reads `user.avatar`, currently always undefined) | Story | Medium | 5 | New (SCRUM-182) | TT-10.1 |
| TT-10.7 | SCRUM-189 | User avatar — frontend: uses TT-10.3's shared upload component, wired into `Profile/Show.vue`; `UserComponent.vue` needs zero changes | Task | Medium | 2 | New (SCRUM-182) | TT-10.3, TT-10.6 |
| TT-10.8 | SCRUM-190 | File-upload validation hardening — size + MIME enforced on **both** the frontend (immediate feedback in TT-10.3's shared component, before an invalid file is even submitted) and the backend (FormRequest rules on all four endpoints) — closes `UpdateCounsellorRequest`'s `// TODO validate size of files`. Confirmed in scope. | Task | Medium | 4 | New (SCRUM-182) | TT-10.2, TT-10.3, TT-10.4, TT-10.6 |

Total: **35 points**.

> **Known gap, out of TT-10.6's scope (flagged by `reviewer`, 2026-08-31)**: `UserComponent.vue`
> reads `user.avatar` and is also used inside `AdminUsersComponent.vue`, but TT-10.6 only wired
> `avatar` into `UserResource` (the shared, single-instance Inertia auth-user prop), not
> `AdminUserResource` (a separate, bulk `::collection()` resource backing the admin users list) --
> so admin-listed users simply won't show an avatar (falsy, not a crash) until a follow-up ticket
> adds it there, repeating the same eager-load audit TT-10.2/TT-10.4 did for their own bulk
> listings first.

> `HowToStep::file_id` (a fourth FK-column file attachment) is deliberately scoped OUT of this
> epic — tracked as its own unscheduled, Low-priority follow-up, not silently migrated or dropped.

**Sprint:** next active sprint — no dependency on TT-1 through TT-9; Organization logo directly
completes work already shipped for TT-6.5a's dashboard (column/relation existed with no upload
path), so sequencing it right after that work is cheaper than deferring it.

---

## Reconciled Sprint Roadmap

| Sprint | Focus | Epics | Why here |
|---|---|---|---|
| 1 | Stability | TT-1 | Unchanged — nothing else should ship on a shaky foundation |
| 2 | Real-time discussions & scheduling | TT-2 | Unchanged |
| 3 | **Video & audio sessions** | TT-3 | New — inserted ahead of Organizations because it's a core product gap affecting every existing user, not a growth feature for a not-yet-existing customer segment |
| 4 | Trust, safety & crisis response | TT-4 | Same timing as before, but institutionalisation + crisis quick-access pulled to the front of the sprint given the stakes |
| 5 | Organizations + payments foundation | TT-6, TT-7 | Merged — Org "Client Mode" billing was always a payments story in disguise |
| 6 | Clinical progress & personal tools | TT-5 | Unchanged timing, expanded scope (outcome tracking, export) |
| 7 | Admin, ops & remaining security | TT-8, TT-4.2/4.5/4.6 | Hardening work batched together |
| 8 | Profile polish & icebox | TT-9 | Unchanged |

---

## What changed vs. the original plan, and why

- **Video/audio (TT-3)** is the one addition I'd actually fight for a priority bump on. Everything
  else new (crisis quick-access, outcome tracking, payments, exports, QA audit trail) is additive
  and can slot into the existing rhythm without disrupting it — video changes the sprint order
  because shipping Organizations before the core 1:1 experience has video would mean selling a
  B2B feature on top of a product gap your existing users already feel.
- **Payments (TT-7)** was implicitly required by the old Story 1.3 ("subsidized rates") and Story
  8.1 ("pricing") but never had its own story. Splitting it out makes the dependency explicit and
  prevents Sprint 5 from stalling when someone realizes there's no gateway wired up.
- **Crisis quick-access (TT-4.4)** is deliberately scoped smaller than the icebox "AI Emergency"
  item so it can ship on a normal sprint timeline instead of waiting on an AI integration.
