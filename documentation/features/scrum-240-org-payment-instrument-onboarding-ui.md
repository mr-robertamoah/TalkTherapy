# SCRUM-240 (TT-7.3b-i): Org Payment-Instrument Onboarding UI

Lets an org admin view and register/replace the organization's saved Paystack payment
instrument — the reusable card authorization that pay-per-use (TT-7.3b-c) and retainer
settlement (TT-7.3b-e) charges are billed against. The backend for this (`organization_payment_instruments`,
`InitiateOrganizationPaymentInstrumentRegistrationAction`, `CaptureOrganizationPaymentInstrumentAction`)
was already built in TT-7.3b-a with no controller/route/UI of its own — this ticket adds that
missing frontend.

## What was built

- New page `Organization/PaymentInstrument.vue` at `/organizations/{organizationId}/payment-instrument`,
  linked from the org dashboard (`Organization/Show.vue`) next to "billing reconciliation", both
  gated on the org being consumer-capable.
- Shows the current instrument's masked details (card number, type, bank, expiry, currency, and
  any pending verification-charge credit) or "No payment method on file yet."
- A currency picker (from the platform's supported-currencies list) with a live preview of the
  nominal verification charge for the selected currency, and an "Add"/"Replace Payment Method"
  button.
- Clicking the button starts a real Paystack hosted-checkout redirect (there is no free "just
  verify this card" API call — registering an instrument means running one small, real charge
  through it). The admin completes card entry on Paystack's own page, then lands back on this
  same page, which shows a dismissible success/failure banner and the freshly-captured
  instrument.
- New `OrganizationPaymentInstrumentController` (`index`/`initiate`), `OrganizationPaymentInstrumentResource`,
  `RegisterOrganizationPaymentInstrumentRequest`, and `SettingsService::getOrganizationPaymentInstrumentVerificationAmounts()`.
- `TransactionController::redirectUrlFor()`'s existing Organization-subject branch now points the
  post-checkout callback at this new page instead of the org dashboard.

## How to try it out

1. Log in as `org_demo_admin` / `password` (owner-role admin of "Org Demo Wellness Collective",
   org id 1 in a fresh seed).
2. Visit `/organizations/1/dashboard`, then click "payment method" — or go directly to
   `/organizations/1/payment-instrument`.
3. The seeded org already has a payment method on file (masked `**** 4242`, GHS, with a GHS 1.00
   pending credit) — this state can't otherwise be reached in this dev environment, since a real
   registration needs a live Paystack checkout round trip and no `PAYSTACK_SECRET_KEY` is
   configured here (same limitation noted on TT-7.4b's own feature doc).
4. Change the currency selector and confirm the "verification charge" preview updates.
5. Click "replace payment method" — with no Paystack key configured, this correctly fails with a
   clean, dismissible error banner ("Unable to start payment-method verification right now...")
   rather than a crash, confirming the full request pipeline (route → controller → validation →
   service → action → Paystack call → error handling → frontend alert) works end-to-end. Against
   a real Paystack sandbox key, this would instead redirect to a hosted checkout page and, on
   return, show the captured instrument and a success banner.

## Testing performed

- `docker compose exec php ./vendor/bin/pint` — clean on all touched/new files.
- `docker compose exec php php artisan test --parallel` — full suite, 1262 passed, no regressions.
- Reviewed by `reviewer` and `security-engineer` subagents; reviewer's one required finding (missing
  `SettingsService` unit test coverage) was fixed and re-verified. Security review found no issues.
- Playwright-verified the golden path described above (page load with an existing instrument,
  currency-preview reactivity, and the graceful error path) — the full checkout-and-return cycle
  isn't completable in this dev environment for the same reason noted on TT-7.4b's own feature doc.

## Known gaps / follow-ups

- `pendingCreditAmount` is surfaced read-only on this page, but nothing currently credits it
  against a real invoice — `SettingsEnum`'s own comment says TT-7.3b-e's invoicing was meant to,
  but a grep confirms that was never wired up, even though that ticket is done. Pre-existing gap,
  not introduced or fixed here; flagged in `documentation/decision-log.md`.
- The page's status-banner/initiate-and-redirect logic is a third near-duplicate of
  `resources/js/Composables/usePayment.js`'s own mechanism (after `TherapyPaymentDetails.vue`/
  `UnifiedTherapy.vue`'s shared use of it). Not generalized into the shared composable in this
  ticket since this page's POST needs a `currency` body param and no `therapy` ref, which
  `usePayment()`'s current shape doesn't support without changing the composable itself — a
  reasonable follow-up if a fourth such page appears.
