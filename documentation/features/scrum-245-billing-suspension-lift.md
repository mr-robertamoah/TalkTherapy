# SCRUM-245 (TT-7.3b-followup): Manual Billing-Suspension Lift & Failed-Invoice Retry

SCRUM-238 added org billing suspension with no way to ever lift it, and SCRUM-236 shipped
retainer invoice settlement with no retry mechanism for a `failed` invoice — it stayed failed
forever, and the org stayed suspended forever. This closes both gaps as a platform-admin-only
manual workflow.

## What was built

- New `Admin/OrganizationBilling.vue` page (`/administrator/organization-billing`, linked from
  the main `Admin.vue` nav), listing every currently billing-suspended organization: suspended-
  since date, suspension reason, its latest failed invoice (period/amount), and whether it has a
  payment instrument on file.
- **Retry settlement**: re-attempts a real Paystack charge against a `failed` invoice, reusing
  `SettleOrganizationInvoiceAction`'s existing lock/claim/charge logic (relaxed to also accept a
  `failed` invoice, not just `open`) rather than duplicating it.
- **Lift suspension**: directly clears `billing_suspended_at`/`billing_suspension_reason`,
  restoring the org's retainer-covered members' access. Idempotent — a no-op if the org isn't
  currently suspended.
- If a retry succeeds while the org is still marked suspended, 2 random platform admins get a
  notification pointing them at this page — this does **not** auto-lift the suspension; a human
  still decides.
- Both actions are platform-admin-only (mirrors `Organization::verify()`'s own "a trust decision
  made by staff, not self-service" precedent) — the org's own admin has no way to unsuspend
  itself.

## How to try it out

1. Log in as the super admin: `mr_robertamoah` / the value of `SUPER_PASSWORD` in `.env.docker`
   (`itisme2025` in this dev environment).
2. Visit `/administrator/organization-billing`, or from `/administrator` click "organization
   billing" in the nav.
3. The seeded "Suspended Demo Collective" org appears with a failed invoice and a payment
   instrument on file.
4. Click "retry settlement" — starts a real Paystack charge attempt (no sandbox key is
   configured in this dev environment, so this will fail the same graceful way TT-7.3b-i's own
   registration flow does; against a real sandbox key it would succeed and settle the invoice).
5. Click "lift suspension" — clears the suspension immediately; the row disappears from the list.

## Testing performed

- `docker compose exec php ./vendor/bin/pint` — clean on all touched/new files.
- `docker compose exec php php artisan test --parallel` — full suite, 1282 passed, no
  regressions.
- Reviewed by `reviewer` and `security-engineer` subagents. Security found no issues (authorization,
  double-charge/replay safety via the existing row-lock mechanism, CSRF, and rate limiting all
  hold up). Reviewer's one required finding (the read-side listing action had no authorization
  check of its own, relying solely on the controller) was fixed and re-verified.
- Playwright-verified the golden path end-to-end: loaded the list, lifted a suspension (row
  disappeared, success banner shown), re-seeded, retried a settlement (success banner shown, job
  dispatched), confirmed the empty state ("No organizations are currently billing-suspended.")
  renders correctly.

## Known gaps / follow-ups

- Full automatic retry-then-lift (no admin action required at all) was deliberately scoped out —
  investigated and confirmed there was no existing retry/dunning infrastructure to build on, and
  designing a full dunning policy (retry counts, give-up conditions, whether a settled invoice
  implies the org's *whole* payment standing is fixed) is a materially bigger, separate product
  decision. This manual workflow plus a notification is the scoped-down middle ground; a fuller
  automatic pipeline is a candidate follow-up if this proves too slow in practice.
