# SCRUM-242/TT-7.3b-k: Client-facing org-billing disclosure UI

One of twelve sub-tickets under the TT-7.3b org-billing epic (see `documentation/decision-log.md`'s
2026-09-03 SCRUM-230 entries for the full scope-correction history). Dependency-free — reads only
already-shipped billing-mode (TT-6.3b) and retainer-access-bypass (SCRUM-237/TT-7.3b-f1) state, no
dependency on any of TT-7.3b's not-yet-built collection/invoicing infrastructure.

## What was built

A retainer-covered client (an org member on RETAINER billing mode, covered for a specific
counsellor via SCRUM-237's bypass) never needs to personally pay for that engagement — but before
this ticket, they still saw a normal "pay now" control with no explanation, or nothing at all.
This adds a modest, non-financial disclosure in its place:

> This therapy is covered under [Organization]'s plan with TalkTherapy -- no payment needed from
> you.

on both payment-control surfaces:

- `TherapyPaymentDetails.vue` — the PER_THERAPY case, on the therapy's "payment details" tab.
- `UnifiedTherapy.vue`'s session-actions modal — the PER_SESSION case.

Never fee amounts, compensation percentages, or payout figures — deliberately narrow scope, since
this is a genuinely new UI convention for the codebase (no prior surface shows a client any
financial-split information).

**Pay-per-use org members are unaffected.** Today, a pay-per-use org-attributed transaction still
charges the member's own card in full (TT-7.3a's existing behavior; the new org-charge-at-source
infra is a separate, not-yet-built sub-ticket) — so the Pay control and normal payment flow are
untouched for that case.

**Backend.** `GetRetainerCoveringOrganizationAction` (new) — extracted from
`EnsureStrictPaymentGateSatisfiedAction`'s own retainer-coverage query (SCRUM-237) so the access
gate and this disclosure ask the identical question via one action instead of two drifting copies.
Returns the covering `Organization` (not a bool) since the disclosure needs its name.
`TherapyResource` gained a new `orgRetainerCoverage` field: `null`, or `{organizationName}`.

**Frontend.** `usePayment.js` gained `isOrgRetainerCovered()`; `canPayForTherapy()` and
`canPayForSession()` both now also require `!isOrgRetainerCovered()`, so the Pay control is hidden
in exactly the case the disclosure appears.

## Bug found and fixed during implementation

**Anonymity leak (security-engineer finding).** The first version of `orgRetainerCoverage()`
computed the covering org unconditionally whenever the therapy's `addedby` was a `User`, with no
check against the exact same `addedByUserIsMaskedFor($user)` masking the `user` field on the same
resource already applies. For a `public + anonymous` therapy, this meant *any* unauthenticated
guest — and, for a merely-anonymous (non-public) therapy, the assigned counsellor, an admin, or a
guardian — could see the client's org name, re-identifying an otherwise-anonymous client. Fixed by
gating `orgRetainerCoverage()` on the same masking check, with regression tests covering: an
anonymous therapy viewed by the assigned counsellor, a public+anonymous therapy viewed by a guest,
and a non-anonymous therapy (control case, still discloses).

## Test data

New seed data added to `database/seeders/DatabaseSeeder.php`'s `createOrganizationDashboardDemoData()`
(no existing seed exercised a retainer-covered PAID therapy) — see
`documentation/seeded-data.md`'s "Organization admin dashboard" section:

- `org_demo_member` / `password` — owns "Org Retainer Demo Therapy (Per Therapy)" and "(Per
  Session)", both PAID, with `org_demo_counsellor` (an ACTIVE-affiliated counsellor of the same
  org `org_demo_member` retains through). The per-session therapy's session is seeded already
  active (`start_time` a minute in the past) so the session-actions modal is reachable
  immediately, with no timing window to wait out.

## How to try it

1. Log in as `org_demo_member`.
2. Visit the "Per Therapy" therapy → "payment details" tab → see the disclosure line in place of
   any Pay control or payment status.
3. Visit the "Per Session" therapy → open its (already-active) session's actions modal (toggle
   "show session information", then double-click the session banner) → see the same disclosure in
   place of "pay now".
4. For comparison, log in as any pay-per-use org member (or a client with no org coverage at all)
   on a PAID therapy — the normal Pay control still appears unchanged.

## Testing performed

- Full Pest suite: 1108 passed (parallel, 8 processes) — no regressions.
- Pint clean on every touched file (whole-file).
- `reviewer` and `security-engineer` subagent review; all findings applied (see "Bug found" above).
- Live Playwright golden-path QA: both the PER_THERAPY payment-details tab and the PER_SESSION
  session-actions modal confirmed showing the exact disclosure text with no Pay control, logged in
  as the seeded `org_demo_member`.

## Files changed

Backend: `app/Actions/Organization/GetRetainerCoveringOrganizationAction.php` (new),
`app/Actions/Transaction/EnsureStrictPaymentGateSatisfiedAction.php` (now delegates to it),
`app/Http/Resources/TherapyResource.php`, `database/seeders/DatabaseSeeder.php`.

Frontend: `resources/js/Composables/usePayment.js`,
`resources/js/Components/TherapyPaymentDetails.vue`, `resources/js/Pages/UnifiedTherapy.vue`.

Tests: `tests/Unit/GetRetainerCoveringOrganizationActionTest.php`,
`tests/Feature/OrgRetainerCoverageExposureTest.php`.
