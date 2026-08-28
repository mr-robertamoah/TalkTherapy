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
| TT-2.2 | Counsellor can add real-time notes to a running session | Story | Medium | 5 | Existing (2.5) | TT-2.1 |
| TT-2.3 | Counsellor can annotate a specific chat message with a timestamped note | Story | Medium | 5 | Existing (2.3) | TT-2.1 |
| TT-2.4 | Admin can cap counsellors per discussion | Story | Low | 2 | Existing (2.4) | — |
| TT-2.5 | User can propose a session day/time for counsellor accept/modify | Story | High | 5 | Existing (4.1) | — |
| TT-2.6 | Counsellor calendar view of their sessions | Story | High | 5 | Existing (4.2) | TT-2.5 |
| TT-2.7 | Fix `groupTherapies` channel-name casing mismatch — group-therapy real-time updates may be silently broken | Bug | High | — | New (SCRUM-15 follow-up) | — |

**Sprint:** 2 (unchanged from original plan). TT-2.7 should likely be pulled forward given its
severity — group real-time updates may not work at all right now — but is filed here since it's
squarely a TT-2 concern.

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
| TT-6.4c | 🔧 **In progress** (SCRUM-131, split into 5 sub-tickets SCRUM-146–150). Converts TT-6.4b's unilateral compensation write into a two-party negotiation reusing the existing `Request` accept/reject infrastructure — zero schema changes to `organization_counsellor_compensations` (proposed-but-unaccepted terms live in `Request.data`/new `expires_at`/`round` columns on `requests`). Adds a counter-offer capability (not just accept/reject) and a configurable reminder/expiry mechanism (default 7-day window, 5-round cap, both config-driven). Sub-tickets: 1/5 proposal creation ✅ (SCRUM-146, merged), 2/5 accept/reject ✅ (SCRUM-147, merged), 3/5 counter-offer 🔧 (SCRUM-148, implemented, PR pending), 4/5 reminder+expiry (SCRUM-149), 5/5 admin-facing negotiation-state API (SCRUM-150, backend only — TT-6.5a doesn't exist yet). | Story | Medium | ~27–28 (across 5 sub-tickets) | Follow-up from SCRUM-123 (SCRUM-131) | TT-6.4b |
| TT-6.3a | ✅ **Implemented** (SCRUM-124, PR #62, merged). Consumer-org membership request flows (org→member invite, member→org self-apply, the latter gated on a new `self_apply_enabled` org flag) — the member-side mirror of TT-6.2, split out the same way once M2 planning started. Membership activates immediately on acceptance (no compensation-style gate). | Story | High | 8 (new estimate, split from original TT-6.3) | New split of existing TT-6.3 (SCRUM-111) | TT-6.1 |
| TT-6.3b | ✅ **Implemented** (SCRUM-125, PR #63, open). Billing-mode config per member: retainer (fixed periodic amount regardless of usage) or pay-per-use (per-session/per-therapy, reusing `TherapyPerPaymentEnum`), plus a group-therapy include/exclude flag — mixable per member within one org, effective-dated/append-only (mirrors TT-6.4b). Excluding group therapies blocks that booking under the org context entirely (member can still book independently, outside the org, paying personally) — enforcement itself is TT-7.3a's job, not built here. A member belonging to multiple orgs that share a counsellor must explicitly pick which org an engagement bills through (feeds TT-7.3a). Org-admin dashboard visibility into member activity is scoped to session metadata (dates, frequency, type/category) and invoices only — never clinical notes or the private journal (TT-5.1). Config only here — no live charge execution. | Story | High | 8 (new estimate, split from original TT-6.3) | New split of existing TT-6.3 (SCRUM-111) | TT-6.3a |
| TT-6.5a | Org admin dashboard (profile, invite+approve, compensation terms, billing config) — new dedicated `resources/js/Pages/Organization/` page tree, following `resources/js/Pages/Profile/Counsellor/`'s structure rather than `Admin.vue`'s tab/dispatch-table pattern (already flagged as ad-hoc debt). | Story | Medium | 5 | New (SCRUM-111) | TT-6.1, TT-6.2, TT-6.4a, TT-6.4b |
| TT-6.5b | Counsellor "my organizations" view + apply-to-provider-org wizard, reusing `BecomeCounsellorForm.vue`/`CounsellorCreationSteps.vue`. | Story | Medium | 5 | New (SCRUM-111) | TT-6.4a |
| TT-6.5c | Member "your organization" view + self-apply UI, including an explicit org-selection step when contracting a counsellor affiliated with more than one of the member's orgs. | Story | Medium | 3 | New (SCRUM-111) | TT-6.3a |

**M1 status (2026-08-27): complete.** TT-6.1/6.2/6.4a/6.4b (SCRUM-119–122) all merged.

**M2 status (2026-08-27): complete.** TT-6.3 was split into TT-6.3a (SCRUM-124, membership
request flows) and TT-6.3b (SCRUM-125, billing-mode config) before implementation started,
mirroring the TT-6.2/6.4a/6.4b split on the provider side. SCRUM-124 merged (PR #62); SCRUM-125
(PR #63) open awaiting review/merge. **Neither M1 nor M2 has a next automatic continuation** —
the only remaining backend Organizations work is M3 (TT-7.3a/b, blocked on TT-7.1/SCRUM-110's
payments backend + TT-7.6/7.7) and M4 (frontend, TT-6.5a/b/c, no Jira sub-tickets filed yet).
Follow-ups filed along the way: SCRUM-123 (counsellor compensation visibility/accountability),
SCRUM-126 (systemic `(bool) $request->x` cast pattern).

**Sprint:** 5 — combined with the payments foundation (TT-7.1/7.2). **Dependency correction**
(previously stated too broadly below TT-7): TT-6.1/6.2/6.4a/6.4b/6.3a/6.3b — the full org data
model and config layer — have **no** TT-7.1 dependency; only the live-charge-execution slice
(TT-7.3a, tracked under TT-7 below) needs both TT-6.3b and TT-7.1. All of TT-6.1 through TT-6.3b
shipped (or are shipping) in parallel with TT-7.1 rather than waiting on it.

---

## Epic TT-7: Payments & Billing — *New*
*Goal: neither counsellor pricing display nor Org subsidized rates mean anything without a real way to move money.*

| Key | Story | Type | Priority | Points | Source | Depends on |
|---|---|---|---|---|---|---|
| TT-7.1 | Transaction/payment model (full audit-trail status history) + Paystack charge initiation & webhook/callback plumbing, test-mode end-to-end for a single simple case. TalkTherapy is sole merchant of record (no per-counsellor subaccounts). | Story | High | 13 | New (SCRUM-110, revised from 8) | — |
| TT-7.2 | Counsellor sets and displays preferred pricing on profile | Story | Medium | 3 | Existing (8.1) | TT-7.1 |
| TT-7.3a | Org-as-payer charge initiation only: `organization_id` is an explicit, validated input at charge time (never inferred from membership alone) — required whenever a member/counsellor pair spans more than one org they share. | Story | Medium | 5 | New split of existing TT-7.3 (SCRUM-111) | TT-6.3b, TT-7.1 |
| TT-7.3b | Full org-billing lifecycle: payout to affiliated counsellors per agreed compensation terms (gross amount → platform fee → counsellor/org split), refund handling for org-paid transactions. Needs a platform-fee concept, which doesn't exist yet anywhere in TT-7 — track as a precondition for TT-7.6 independent of orgs. | Story | Medium | 8 | New split of existing TT-7.3 (SCRUM-111) | TT-7.3a, TT-7.6, TT-7.7 |
| TT-7.4 | Retry-on-failure + single-currency validation (replace free-text currency with a supported-list check) + full payment-status UI | Story | High | 8 | New (SCRUM-110) | TT-7.1 |
| TT-7.5 | Payment-gated access: per-therapy, counsellor-controlled setting (strict gate vs. trust-based), defaulting to trust-based | Story | High | 5-8 | New (SCRUM-110) — decision made, fast-follow after TT-7.1 | TT-7.1 |
| TT-7.6 | Counsellor payout via Paystack Transfers matching `shareEqually`/`sharePercentage`, incl. bank-account/KYC onboarding. Payout is admin/counsellor-triggered separately, never auto-fired on charge success. | Story | Medium | 13 | New (SCRUM-110) — decision made; own Epic once scheduled, not a TT-7 sub-story | TT-7.1 |
| TT-7.7 | Refund handling | Task | Medium | 5 | New (SCRUM-110) — now in scope; fast-follow after TT-7.1 | TT-7.1 |
| TT-7.8 | ~~Anonymity-safe checkout~~ — resolved, no action needed: Paystack's checkout/receipt is only ever shown to the paying user themselves, never to the counsellor or any other party, so the existing anonymity guarantee (which governs what *other people* see) is unaffected. | Task | — | — | New (SCRUM-110) — resolved | — |
| TT-7.9 | Multi-currency support: forex conversion with an applied markup, clearly disclosed to the user at payment time | Story | Medium | 8-13 (TBD) | New (SCRUM-110) — decision made to support multi-currency with disclosed markup; scoped as its own follow-up ticket, not part of TT-7.1's single-currency M1 | TT-7.1, TT-7.4 |

**Sprint:** 5 (TT-7.1 alongside TT-6). TT-7.4/7.5/7.7 are fast-follows immediately after TT-7.1
lands, still targeting Sprint 5 if capacity allows. TT-7.6 (payout) and TT-7.9 (multi-currency)
are larger, separately-scheduled follow-ups — do not fold into Sprint 5's commitment. TT-6.1
through TT-6.3b (Organizations, SCRUM-111) have no payments dependency at all and can proceed in
full parallel with TT-7.1; only TT-7.3a (charge-initiation, above) depends on both TT-6.3b and
TT-7.1, and TT-7.3b additionally depends on TT-7.6/TT-7.7.

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
