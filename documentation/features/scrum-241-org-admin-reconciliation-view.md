# SCRUM-241/TT-7.3b-j: Org-admin reconciliation view

The last of twelve sub-tickets under the TT-7.3b org-billing epic (see
`documentation/decision-log.md`'s 2026-09-03 SCRUM-230 entries for the full scope-correction
history). Depends on TT-7.3b-c/-d/-e/-f2, all merged.

## What was built

A read-only, org-admin-only view showing every financed session/transaction for an organization,
split into two sections:

- **Financed Sessions (Pay-Per-Use)** — one row per direct charge (`Transaction.organization_id`
  set, subject a Therapy/Session/GroupTherapy): which engagement, which counsellor, the total
  charged, the counsellor's share, the platform fee, payout status, and the charge's own status.
- **Retainer Invoices** — one row per settlement period (`OrganizationInvoice`): period, total
  amount, and status (open/pending/settled/failed). Each row expands to show its
  `OrganizationInvoiceLine`s (which session, which counsellor, net/fee amounts) — including the
  CURRENT, still-`open` period's accrued-so-far balance, not just past settled ones.

A billing-suspended organization (SCRUM-238) shows a banner at the top of the page.

**Backend.** `GetOrganizationFinancedTransactionsAction`/`GetOrganizationRetainerInvoicesAction`
(new) mirror `GetOrganizationMembersAction`/`GetOrganizationCounsellorsAction`'s own org-scoped-list
shape (TT-6.6a) exactly — same admin gate (`EnsureUserIsOrganizationAdminAction`), same pagination
convention. `OrganizationReconciliationController` (new) mirrors `OrganizationController::dashboard()`'s
own page-plus-dedicated-JSON-endpoints split (each initial paginator's path is repointed at its own
JSON route, not this page's route, so "load more" gets JSON back). `OrganizationResource` gained
`isBillingSuspended`/`billingSuspendedAt`/`billingSuspensionReason` — safe to add there since that
resource is only ever rendered behind the same admin gate.

**Frontend.** `resources/js/Pages/Organization/Reconciliation.vue` (new) — a dedicated page (not
another tab on `Organization/Show.vue`), mirroring `Admin/Payouts.vue`'s own
dedicated-page-with-audit-table structure (TT-7.6e). Linked from `Show.vue` via a "billing
reconciliation" button, visible only for a consumer org, carrying a "suspended" badge when
applicable.

**Data minimization**, mirroring `OrganizationMemberResource`'s own precedent (SCRUM-159): the new
`OrganizationFinancedTransactionResource`/`OrganizationInvoiceLineResource` expose session/therapy
NAMES only, never `SessionNote`/journal content — an org admin has no legitimate need to see
clinical content just because they administer the org financing it.

## Judgment calls (see `documentation/decision-log.md`'s 2026-09-06 SCRUM-241 entry for full reasoning)

- GroupTherapy financed transactions show no counsellor name — org billing was never built for
  group therapies (an existing scope boundary from TT-7.3b-b/-c), so there is no single
  counsellor/share to show.
- A settlement `Transaction` (`for_type = OrganizationInvoice`) is explicitly excluded from the
  pay-per-use transactions list — it's covered by the separate Retainer Invoices section instead,
  never double-counted.

## Test data

New seed data added to `database/seeders/DatabaseSeeder.php`'s `createOrganizationDashboardDemoData()`
(hand-seeded at final state, not by running the actual charge/settlement pipeline — matching this
seeder's own established convention) — see `documentation/seeded-data.md`'s "Organization admin
dashboard" section:

- A new pay-per-use member (`org_demo_payperuse_member`) with a financed Therapy, a successful
  Transaction, and a paid-out `CounsellorEarning` — demos the Financed Sessions table with every
  column populated.
- A settled retainer invoice (last calendar month) with one line against the existing "Org
  Retainer Demo Session", plus its own settlement Transaction and earning.
- An open (current calendar month) retainer invoice with one accruing line against a second,
  freshly-seeded held session — demos the "current-period accrued balance" requirement.

## How to try it

1. Log in as `org_demo_admin` / `password`.
2. Visit `/organizations/1/dashboard` → click "billing reconciliation" (or navigate directly to
   `/organizations/1/reconciliation`).
3. See the pay-per-use row (Org Pay-Per-Use Demo Therapy, USD 80.00 charged, USD 70.00 to the
   counsellor, USD 10.00 fee, payout succeeded).
4. See two retainer invoice rows — the current month (`open`, no amount yet) and last month
   (`settled`, USD 45.00) — click either to expand its lines.
5. To see the suspension banner: `docker compose exec php php artisan tinker --execute="App\Models\Organization::find(1)->suspendBilling('demo');"`, reload the page, then clear it the same way
   with `billing_suspended_at`/`billing_suspension_reason` set to `null`.

## Testing performed

- Full Pest suite: 1235 passed (parallel, 8 processes) — 12 new tests in
  `tests/Feature/OrganizationReconciliationControllerTest.php`, no regressions.
- Pint clean on every touched/new file (whole-file).
- `reviewer` and `security-engineer` subagent review; findings applied (see decision log).
- Live Playwright golden-path QA against a production build (this environment's Vite dev server
  could not run — host-level `fs.inotify.max_user_instances` exhaustion from unrelated desktop
  processes, not a project issue): logged in as `org_demo_admin`, loaded the reconciliation page,
  confirmed both tables render the seeded data correctly, expanded a settled invoice's lines,
  toggled the suspension banner on and off.
- A pre-existing, unrelated bug was found during QA and filed as SCRUM-247 (not fixed here): the
  org-admin *dashboard* page (not this ticket's new page) intermittently 502s with "upstream sent
  too big header" — an nginx `fastcgi_buffer_size` config gap, reproducible independent of this
  ticket's diff.

## Files changed

Backend: `app/Actions/Organization/GetOrganizationFinancedTransactionsAction.php` (new),
`app/Actions/Organization/GetOrganizationRetainerInvoicesAction.php` (new),
`app/Http/Controllers/OrganizationReconciliationController.php` (new),
`app/Http/Resources/OrganizationFinancedTransactionResource.php` (new),
`app/Http/Resources/OrganizationInvoiceResource.php` (new),
`app/Http/Resources/OrganizationInvoiceLineResource.php` (new),
`app/Http/Resources/OrganizationResource.php`, `app/Services/OrganizationService.php`,
`routes/web.php`, `database/seeders/DatabaseSeeder.php`.

Frontend: `resources/js/Pages/Organization/Reconciliation.vue` (new),
`resources/js/Pages/Organization/Show.vue`.

Tests: `tests/Feature/OrganizationReconciliationControllerTest.php` (new).
