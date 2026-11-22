# Revenue options for TalkTherapy (SCRUM-465)

Research and recommendation only. No code was changed for this ticket. Written 2026-11-22.

## Executive summary

- Today TalkTherapy earns one thing: a platform fee of `PLATFORM_FEE_PERCENTAGE` (default 10%, which an admin can
  change in `platform_settings`). It is taken out of personal payments and added on top of organisation charges.
  Commission-free periods (SCRUM-463/467/468) and counsellor waivers (SCRUM-464) lower it further. TalkTherapy is the
  merchant of record, and no code passes Paystack's processing fee on to the payer, so that fee comes out of the
  platform's share.
- On the illustrative numbers below, the fee covers only a small team at moderate volume, and it scales only with
  paid sessions. More commission would come from the people the platform exists to serve: clients, minors and
  low-income users.
- **Recommendations:**
  1. **Employer and organisation plans.** Add a fixed monthly platform fee per organisation or per covered member on
     top of the existing usage billing. This builds on the TT-7.3 retainer invoices that already exist.
  2. **An access fund paid for by grants, sponsors and donations.** Run it as a consumer organisation that covers
     vetted low-income members and minors through the existing organisation-billing paths. This brings counsellors
     paid sessions and makes the platform fundable, without charging vulnerable users more.
  3. **Optional practice tools for counsellors, sold later and never as paid ranking.** Do not sell "featured
     profiles" that change who clients see.
- **Not recommended for now:** insurance and NHIS billing (needs facility accreditation and a claims process;
  revisit later), paywalled assessments or check-ins, premium client features that touch safety, and any
  advertising or data sales (ruled out).

## 1. Baseline and metrics

### How the fee flows through the data model

| Path | Where the money is recorded | Fee |
|---|---|---|
| Personal payment (therapy, session, group place) | `transactions` (`organization_id` null), then one `counsellor_earnings` row per entitled counsellor | Taken from the amount paid. `net_amount + fee_amount = gross_amount` |
| Organisation card charge (pay-per-use) | `transactions` with `organization_id`; per-counsellor split in `transactions.breakdown` (JSON); `counsellor_earnings` | Added on top of the counsellor's listed rate (`ComputeOrganizationChargeBreakdownAction`) |
| Retainer (monthly invoice) | `organization_invoice_lines` (`net_amount`, `fee_amount`) written when a session is held; the invoice is settled as one `transactions` row pointing at `organization_invoices`; earnings are created at settlement (`GenerateCounsellorEarningsAction::generateForSettledInvoice`) | Added on top, decided when the session is held |
| Waived fee (commission-free period or counsellor waiver) | `platform_fee_waiver_id` + `waived_fee_amount` on `counsellor_earnings`, `organization_invoice_lines` and the breakdown lines | Fee 0; the fee that would have been taken is recorded |

The fee is decided in one place only: `ResolvePlatformFeePolicyAction` builds a `PlatformFeePolicy`, which calls
`ComputePlatformFeeAction`. An arch test enforces this.

### Metric definitions

All amounts are in minor units and **per currency (GHS and USD are never added together)**, matching
`GetPlatformStatsAction`. A row counts when it is successful and not reversed.

| Metric | Definition | Already in `GetPlatformStatsAction`? |
|---|---|---|
| Gross payments (GMV) | Sum of `transactions.amount` where `status = success` | Yes (`money.revenue`; the name is misleading, because this is all money collected, not platform revenue) |
| Platform fee revenue | Sum of `counsellor_earnings.fee_amount` where `status != reversed` | Yes (`money.platformFees`) |
| Fee waived | Sum of `counsellor_earnings.waived_fee_amount` (not reversed) | **No.** Add it next to `platformFees` |
| Fee accrued but not yet billed | Sum of `organization_invoice_lines.fee_amount` on invoices that are still `open` | No. Retainer fees appear in `platformFees` only after settlement |
| Refunds | Sum of `refunds.amount` where `status = success` | Yes (`money.refundsPaid`) |
| Organisation billing | Sum of `organization_invoices` settled, plus `transactions` with `organization_id` | Partly (`invoicesSettled` only) |
| Effective take rate | Platform fee revenue / GMV | No. Expect just under 10% on personal payments and about 9.1% (10/110) on organisation charges |
| Net platform revenue | Platform fee revenue minus Paystack processing (about 1.95% of GMV, see sources) minus payout transfer costs | No. Paystack fees aren't stored |
| Paid sessions | Held sessions in paid therapies, per currency | `sessionsHeld` counts all held sessions, paid or free |

### Illustrative projection (assumptions, not data)

No real database was queried. I found no verified Ghana session prices (see section 4), so the prices below are
assumptions to replace with platform data. Assumptions: an average fee of 10% with no waivers; Paystack at 1.95% of
everything collected; all amounts in GHS; payout transfer costs and refunds ignored.

| Scenario | Paid sessions/month | Avg. price (GHS) | Share billed to organisations | GMV/month | Platform fee/month | After Paystack/month | Per year |
|---|---|---|---|---|---|---|---|
| Early (15 counsellors × 8) | 120 | 200 | 0% | 24,000 | 2,400 | ~1,930 | ~23,000 |
| Base (40 × 15) | 600 | 250 | 15% | ~152,000 | 15,000 | ~12,000 | ~144,000 |
| Growth (150 × 20) | 3,000 | 250 | 25% | ~769,000 | 75,000 | ~60,000 | ~720,000 |

Each month of a platform-wide commission-free period removes about 1/12 of that year's fee (Base: about GHS
12,000). Counsellor waivers remove fee in proportion to the waived counsellors' share of volume.

## 2. Options at a glance

| Option | Who pays | Size (rough) | Effort | Access impact | Main risks | Waiver interaction |
|---|---|---|---|---|---|---|
| Commission tiers | Counsellors (indirectly clients) | ± a few % of fee revenue | S | Neutral to slightly negative | Complexity; fairness to new counsellors | Waivers override any tier; the policy already takes a counsellor |
| Counsellor subscriptions (tools / featured) | Counsellors | Small (tools); featured adds more but harms trust | M–L | Featured listings bias who clients see | Pay-to-rank in a care setting | Must not be bundled with "no commission" unless that's the intent |
| Organisation / employer plans | Employers, schools, NGOs | Largest potential, recurring | M (builds on TT-7.3) | Positive: members pay nothing | Org sees too much; minors' consent | Platform fee waivable; subscription fee separate |
| Group therapy and workshops | Clients, organisations | Modest; leverages counsellor time | S (exists) | Positive (lower per-head price) | Group confidentiality, moderation | Same fee path; waivers apply automatically |
| Paid assessments / check-ins | Clients | Small | S–M | Negative | Paywalling a safety signal (PHQ-9 item 9) | None needed |
| Corporate wellness packages | Employers | Medium; lumpy | M (content + sales) | Positive for staff | Overreach into clinical; reporting | Separate from session fee |
| Insurance / NHIS billing | Insurers, NHIS | Uncertain; potentially large later | XL | Strongly positive if possible | Accreditation, claims, data sharing | Fee would be on claimed amount |
| Grants and NGO funding | Funders | Lumpy; non-recurring | S–M (reporting) | Positive | Dependency; outcome-reporting privacy | Grants could fund waivers explicitly |
| Sponsorships | Companies, foundations | Small–medium | S | Positive if it funds sessions | Brand pressure, implied endorsement | Could sponsor a commission-free period |
| Premium client features | Clients | Small | M | Negative if core | Two-tier care; safety features behind paywall | None |
| Donations / pay-it-forward | Individual clients, public | Small but visible | M | Positive | Fair allocation; payments rules on donations | Donated sessions could be fee-free |

S = days, M = 1–2 sprints, L = an epic, XL = multi-quarter with partners.

## 3. Options in detail

### 3.1 Commission tiers

- **What:** vary the percentage. For example, a lower rate on low-priced sessions, a lower rate once a counsellor
  passes a volume, or a different rate for organisation work.
- **Who pays / size:** the same payers as today. It redistributes the fee rather than growing it, and only matters
  at scale.
- **Effort:** S–M. `PlatformFeePolicy::feeFor($base, ?$counsellor)` is the single point of decision and already
  receives the counsellor. A tier would be a rule there plus a settings form on Admin → Payouts (TT-7.6e). Earnings
  already store `fee_amount`, so reporting doesn't change.
- **Access:** a lower rate on cheap sessions helps affordable counsellors. Higher rates get passed on to clients.
- **Risks:** a tier that rewards volume nudges counsellors toward high caseloads. Changes must be announced in
  advance.
- **Legal:** the counsellor agreement and terms must state the rate. The tax treatment of the fee is the same as
  today (see 3.12).
- **Waivers:** a waiver means no fee, and that stays first. A tier only changes the percentage when no waiver
  applies.

### 3.2 Counsellor subscriptions (featured profile, practice tools)

- **What:** a monthly fee for (a) practice tools, such as a calendar sync, invoices or receipts for the
  counsellor's own clients, notes templates and outcome reports, or (b) a "featured" listing.
- **Who pays / size:** counsellors. For reference, SimplePractice charges US$49–99/month after a promotion and
  Psychology Today charges US$29.95/month for a listing. A Ghana price would be far lower, and there are few
  verified counsellors, so this stays small for a long time.
- **Effort:** M–L. It needs recurring billing to the counsellor, which doesn't exist yet. Paystack's
  `chargeAuthorization` reuse (proven in TT-7.3b-a for organisations, `CaptureOrganizationPaymentInstrumentAction`)
  is the pattern. The tools themselves are new work (calendar: `GetCounsellorCalendarSessionsAction`; notes:
  `SessionNote`; check-ins: `app/Actions/OutcomeCheckIn`).
- **Access:** tools are neutral. A featured listing changes who clients see. Ranking today is by the viewer's
  preferences (SCRUM-113), and paying to rank above that would undermine it.
- **Risks:** pay-to-rank in mental-health care. If a featured listing is ever sold, it must be clearly labelled,
  never placed above preference matches, and never shown in urgent help (`GetCounsellorsForHelpRequestAction`).
- **Legal:** a consumer-protection duty to disclose paid placement. Counsellors must still hold a Ghana Psychology
  Council licence (Act 857), so a subscription can never stand in for verification.
- **Waivers:** keep the subscription separate from commission. If "subscribers pay less commission" is ever offered,
  model it as a tier (3.1), not a waiver.

### 3.3 Organisation and employer plans

- **What exists (TT-7.3):** organisations are consumer or provider organisations. Members have a billing mode of
  `RETAINER` or `PAY_PER_USE` (`OrganizationMemberBillingModeEnum`, `OrganizationMemberBillingConfigService`).
  Pay-per-use charges the organisation's saved card per engagement (`ChargeOrganizationForModelAction`). The
  "retainer" is a **monthly invoice in arrears for sessions actually held**: `RecordOrganizationInvoiceLineForSessionAction`
  writes a line when a session is held, and `SettleDueOrganizationInvoicesAction` and `SettleOrganizationInvoiceAction`
  charge it. Unpaid invoices suspend coverage (`billing_suspended_at`). Groups are covered too (SCRUM-420). Despite
  the name, nothing charges a fixed fee.
- **Option:** add a fixed **platform fee per organisation or per covered member per month** (a seat or access fee),
  on top of sessions billed at cost plus the fee. This is the common employer model: Headspace describes a flat
  per-employee-per-month fee, or a lower base plus usage, and Vendr reports US$4–12+ PEPM for Modern Health. Lyra and
  Spring Health don't publish prices.
- **Who pays / size:** employers, universities, schools, churches and NGOs. It is recurring and predictable, and
  doesn't depend on how many sessions are held. Illustration only: 10 organisations with 200 covered members each at
  GHS 5/member/month is GHS 10,000/month, about the Base scenario's fee after Paystack.
- **Effort:** M. Add a subscription line type to `organization_invoices`, so an invoice line doesn't always have to
  be a session: today lines need a `session_id`, so this needs a nullable session or a separate table. Add a plan
  field on `Organization` or a small `organization_plans` table, show it on the reconciliation page (TT-7.3b-j,
  `GetOrganizationRetainerInvoicesAction`), and settle it through the existing path. Suspension already exists.
- **Access:** positive. Covered members see "covered under [Organization]'s plan" (TT-7.3b-k) and pay nothing.
- **Risks:** employers wanting to know who uses the service. Today they see session metadata only, never clinical
  content, and the member is never named on group lines. Keep that, with aggregates only and minimum group sizes.
  For minors (schools), guardian consent still applies (`EnsureMinorHasActiveGuardianAction`). TT-7.3d
  (organisations starting therapies for members) was deferred by the user and is not needed for this.
- **Legal:** a B2B contract and a data processing agreement. The Data Protection Act 843 controller/processor roles
  need to be set out. The tax treatment of the seat fee is an open question (3.12).
- **Waivers:** a commission-free period waives the per-session fee only. Decide whether it also pauses the seat fee
  (see open questions).

### 3.4 Group therapy and workshops

- **Exists:** paid groups with a flat per-head price (TT-7.4d), per-member payment gating (TT-7.5b), group video
  (SCRUM-27/314) and retainer billing for groups (SCRUM-420).
- **Option:** promote psychoeducation workshops (stress, exam anxiety, grief) as paid or organisation-paid groups.
  The counsellor earns more per hour, the client pays less, and the fee is unchanged.
- **Effort:** S. Mostly product work: workshop templates and discovery. `CreateGroupTherapyAction` and
  `GroupTherapyService` already cover it.
- **Risks:** confidentiality between members; anonymity in groups (`EnsureGroupTherapyCanBecomeAnonymousAction`);
  moderation. Minors in mixed-age groups need a policy.
- **Waivers:** the same fee path, so waivers apply automatically.

### 3.5 Paid assessments and check-ins

- **Exists:** PHQ-9 and GAD-7 check-ins (TT-5.2), sent by the counsellor inside a therapy, free, with a self-harm
  item that shows emergency help.
- **Option:** charge for standalone screening or for progress reports.
- **Recommendation: no.** Screening is the step where people decide to seek help, and item 9 is a safety signal.
  Putting it behind a payment would delay help for the most at-risk users. The instruments' credit and wording
  requirements also apply, and they are not ours to resell. A counsellor-facing outcomes report could go in the
  practice-tools bundle (3.2) instead.

### 3.6 Corporate wellness packages

- **What:** a package for employers: the organisation plan (3.3) plus workshops (3.4), awareness talks and aggregate
  usage reports.
- **Who pays / size:** employers. Medium, but lumpy and sales-led.
- **Effort:** M. The billing is 3.3. The rest is content, sales, and an aggregate-only report built on
  `GetOrganizationFinancedTransactionsAction`.
- **Risks:** reports that identify individuals in small teams (use a minimum group size); employer pressure on
  counsellors.
- **Waivers:** per-session fees follow waivers; the package fee is a contract price.

### 3.7 Insurance and health-plan billing (NHIS, private insurers)

- **Facts found:** the NHIS added depression, bipolar disorder, anxiety disorder and schizophrenia to its benefit
  package from 1 November 2024, at NHIS-accredited facilities, with outpatient care and initial acute care.
  Psychotherapy is not named in what I found. The Mental Health Authority noted that facilities need billing and
  claims processes. The Private Health Insurers Association says most of the 16 licensed private insurers cover
  therapy (an industry claim, unverified per policy).
- **Effort:** XL. TalkTherapy would likely need to be, or partner with, an accredited facility (the Psychology
  Council licenses facilities yearly), and to build claims submission, member verification, and diagnosis or
  procedure coding.
- **Access:** potentially the biggest gain for low-income users.
- **Risks:** a claim discloses a diagnosis to the payer, which is special personal data under Act 843. Anonymous
  therapies cannot be claimed. Insurers may push toward shorter care.
- **Recommendation:** not now. Talk to one private insurer and the NHIA about a pilot. Revisit after organisation
  plans are working.
- **Waivers:** the fee would be on the claimed amount, or a per-claim admin fee.

### 3.8 Grants and NGO funding

- **What:** grants (health, youth or digital-health funders, CSR budgets) earmarked to pay for sessions for target
  groups, or for platform costs.
- **Effort:** S–M. Mostly reporting: anonymised outcome and usage numbers. Session payment can reuse the
  organisation paths (see recommendation 2).
- **Risks:** dependency and funder churn. Outcome reporting must stay aggregate. Never share check-in answers or
  identities.
- **Waivers:** a funder could explicitly pay for a commission-free period, or the access fund's sessions could be
  fee-free.

### 3.9 Sponsorships

- **What:** a company or foundation sponsors a programme (for example "exam-season support for students"), shown
  with a modest credit, not ads.
- **Size:** small to medium; mostly builds reach and trust.
- **Effort:** S. The existing "Sponsored" badge (`ResolveTherapySponsorAction`, SCRUM-419/420) already labels
  organisation-paid therapy for the member.
- **Risks:** implied endorsement; a sponsor wanting data or targeting. Rule: no targeting, no data, no sponsor in
  urgent help or crisis screens. Gambling, alcohol and lenders are excluded.
- **Waivers:** a sponsor could fund a commission-free period, recorded as an ordinary platform-wide waiver.

### 3.10 Premium client features

- **What:** for example extra journal storage, exports or priority matching.
- **Recommendation:** not now. The client tools that exist (journal, check-ins, data export from SCRUM-50, urgent
  help) are part of care or safety, and charging for them creates two tiers of care. Never charge for urgent help,
  safety plans, emergency numbers or data export, which is a right under Act 843.

### 3.11 Donations and pay-it-forward sessions

- **What:** clients or the public add a donation at checkout, or buy a session for someone else. The money goes into
  the access fund (recommendation 2).
- **Effort:** M. Needs a donation transaction type, which `transactions.for` doesn't model yet, a fund balance, and
  allocation rules. Easiest as a top-up paid to the fund organisation.
- **Access:** positive. Visible community support.
- **Risks:** fair allocation (who gets a donated session); donors must never learn who received it; refunds of
  donations; charity or fundraising rules if TalkTherapy isn't a non-profit (open question).
- **Waivers:** donated sessions could be fee-free (recorded as a waiver or a zero tier) so every cedi reaches the
  counsellor.

### 3.12 Legal and regulatory notes (all options)

- **Payments:** Paystack is the processor and TalkTherapy is the merchant of record (TT-7.1). Ghana merchants
  receive international card payments in GHS. Recurring charges reuse a saved authorization, as organisations do
  today. USD pricing needs the Paystack corridor confirmed, and TT-7.9 (forex) is still open.
- **Health data:** Data Protection Act 2012 (Act 843): register with the Data Protection Commission and renew every
  two years. Health data is special personal data, so any option that shares it (insurance, employer reports,
  grants) needs a lawful basis, minimisation and a processor contract. No option may sell or share data for
  marketing.
- **Clinical and professional:** the Psychology Council (Part 5, Act 857) licenses psychologists, counsellors and lay
  practitioners every year and also licenses facilities. Verification (SCRUM-31) must stay a gate for every paid
  option. Mental Health Act 2012 (Act 846) and the Mental Health Authority: oversight of mental-health services and
  facilities. I couldn't confirm whether an online platform needs facility licensing; ask a lawyer.
- **Tax:** VAT Act 2025 (Act 1151, from 1 January 2026) puts VAT at 15% plus NHIL 2.5% and GETFund 2.5% on the same
  base, with the COVID levy abolished. The GRA's Act 870 schedule lists medical services as exempt, and exempt
  supplies are also exempt from NHIL and GETFund. Whether **the platform's own commission, a seat fee or a
  counsellor subscription** counts as a medical service or a standard-rated digital or intermediary service is
  **unverified and needs a tax adviser**. The e-levy (1% on electronic transfers) was repealed with effect from
  2 April 2025, so it no longer affects mobile-money payments or payouts.
- **Other countries:** USD clients may be outside Ghana. Counsellor licensing is per jurisdiction, and other data
  laws (for example GDPR) may apply. Keep paid options Ghana-first until this is resolved.
- **Minors:** every option that charges or reports must respect guardian consent (SCRUM-278/287/288/289). Don't
  market subscriptions or premium features to minors.

## 4. What similar platforms charge

Accessed 2026-11-22 unless noted. USD figures are list prices and vary by location.

| Platform | Model | Price | Source |
|---|---|---|---|
| BetterHelp (US/global) | Client subscription | US$70–100/week, billed every 4 weeks | https://www.betterhelp.com/faq/ |
| Talkspace (US) | Client subscription; also insurance and employers | US$69/week (messaging), US$99 (video + messaging), US$109 (+ workshops); extra live session US$69; in-network copays | https://talkspace.com/pricing |
| Psychology Today (directory) | Therapist listing fee | US$29.95/month; no free trial (review), free month per referral | https://www.choosingtherapy.com/psychology-today-review/ (the join page redirected when fetched; the figure was also in search results for https://join.psychologytoday.com/) |
| SimplePractice | Practice software for therapists | US$49 / 79 / 99 per month (Starter/Essential/Plus) after 3 months at 50% off; +US$74 per extra clinician on Plus | https://www.simplepractice.com/pricing/ |
| Headspace (employers) | PEPM flat, or lower PEPM plus usage | No figures published | https://organizations.headspace.com/blog/evaluating-mental-health-solution-pricing-models |
| Lyra Health, Spring Health | Employer contracts | Quote only. **Unverified**: third-party estimates don't clearly name either vendor | https://www.springhealth.com/blog/outcomes-based-eap-pricing-model-solution-incentives, https://www.trustradius.com/products/lyra-health/pricing |
| Modern Health (benchmark) | Employer PEPM | US$4–12+ PEPM (Vendr procurement data) | https://www.vendr.com/marketplace/modern-health |
| Open Path Collective (US/Canada) | One-time client membership; therapist keeps session fee | Membership 65 / 89 (page shows both; one is CAD); sessions 40–70 / CAD 50–90; household income under 100k / CAD 140k | https://openpathcollective.org/pricing-and-eligibility-for-affordable-therapy/ (the currency pairing is ambiguous on the page) |
| Nguvu Health (Nigeria/Kenya) | Client subscription and per session | Text NGN 3,000/week, 10,000/month; video NGN 7,500/session or 27,000 for 4 (article dated 2025-07-17) | https://www.pulse.ng/story/best-mental-health-platforms-nigeria-2025071623035510968 |
| MyTherapist.ng (Nigeria) | Per session | From NGN 5,000 (same article) | same as above |
| Mentally Aware Nigeria (MANI) | Free peer counselling (NGO) | Free, via WhatsApp (same article) | same as above |
| Shamiri Institute (Kenya) | Client subscription; free for students; employers | KES 2,500/month (2022 article) | https://www.the-star.co.ke/news/2022-10-11-shamiri-institute-offers-superior-quality-mental-healthcare-at-sh2500-per-month |
| Ghana teletherapy (TherAppConnect, Talk2Me, CPAC, TherapyRoute listings) | Per therapist, on inquiry | **No published GHS prices found** | https://apps.apple.com/in/app/therappconnect/id6478777847, https://www.therapyroute.com/therapists/ghana |

Context sources:

- Paystack fees, 1.95% in Ghana for local and international, settled in GHS:
  https://support.paystack.com/hc/en-us/articles/360009881920 and
  https://support.paystack.com/hc/en-us/articles/360009882020 (the official pricing page returned 403, so the help
  centre pages are undated).
- NHIS mental-health cover from 1 November 2024: https://gna.org.gh/2024/10/nhis-to-cover-four-mental-health-diseases/,
  https://www.graphic.com.gh/news/health/nhis-to-cover-depression-anxiety-and-schizophrenia-treatment-in-ghana.html
- Private insurers' mental-health cover (industry column, unverified): https://thebftonline.com/?p=220270
- VAT Act 2025 (Act 1151): https://taxnews.ey.com/news/2026-0163-ghanas-parliament-enacts-several-indirect-tax-related-laws-effective-1-january-2026,
  https://www.myjoyonline.com/ghanas-new-vat-law-what-has-changed-and-what-nigerias-experience-tells-us/.
  GRA exemptions (Act 870 wording): https://gra.gov.gh/domestic-tax/tax-types/vat-exemptions/
- E-levy repeal, effective 2 April 2025: https://www.ey.com/en_gl/technical/tax-alerts/ghanas-parliament-amends-tax-laws-following-the-2025-budget-statement-presentation
  (EY gives the Act number as 1128 and judy.legal as 1127; this was not resolved)
- Data Protection Act 843 and registration: https://dataprotection.org.gh/compliance/,
  https://www.dlapiperdataprotection.com/?t=law&c=GH
- Psychology Council licensing: https://ghanapsychologycouncil.gov.gh/licensing-registration.html,
  https://ghanapsychologycouncil.gov.gh/faqs.html
- Mental Health Act 846: https://www.refworld.org/node/97417 (licensing provisions for online services were not
  confirmed)

**Takeaways:** global direct-to-consumer subscriptions (US$276–436/month) are far beyond the Ghana market. The
African players that can be verified price per session or by cheap subscription (roughly US$5–20), or are free and
NGO-funded. Employer contracts are where most of the money is in this sector, and their prices are almost never
published.

## 5. Recommendations and next steps

### R1. Employer and organisation plans (first)

Extend TT-7.3 retainers with a fixed monthly platform fee, keeping session billing at cost plus the fee.

Rough tickets:
1. **Plan model.** A plan on an organisation (`organization_plans`: amount, currency, basis per organisation or per
   covered member, start and end). Super-admin set only, following `PlatformFeeWaiverService`'s super-admin pattern.
2. **Invoice line type.** Allow a non-session line on `organization_invoices` (nullable `session_id` + `kind`, or a
   separate table). Add it when the period opens. `SettleOrganizationInvoiceAction` charges it with the session
   lines. No earnings row for this line, because it is platform revenue.
3. **Organisation UI.** Show the plan on the reconciliation page (`GetOrganizationRetainerInvoicesAction`,
   `Organization/Reconciliation`) and the organisation dashboard.
4. **Admin stats.** Add `platformSubscriptions`, `feeWaived` and accrued unbilled fee to `GetPlatformStatsAction`
   (per currency).
5. **Legal and tax.** A contract and data processing agreement template, and a ruling on VAT for the seat fee.

### R2. An access fund paid for by grants, sponsors and donations

Create a platform-run consumer organisation (an "access fund") on the existing organisation-billing paths: vetted
members (low-income users, and minors with guardian consent) are covered on its retainer. Grants, sponsorships and
later donations top up the fund.

Rough tickets:
1. **Fund organisation and eligibility.** Seed or create the fund organisation; write an admin-reviewed application
   flow reusing `ApplyToOrganizationAsMemberAction` and the request queue. Eligibility rules are a product-owner
   decision.
2. **Funding the fund.** At first, the fund's invoices are paid by its own card (grant money). Later, donations go
   in as a new `transactions.for` type that credits a fund balance.
3. **Fee policy for fund sessions.** Fee-free (a per-organisation waiver: an extension of `PlatformFeePolicy`) or a
   reduced tier. Decide with the product owner.
4. **Funder reporting.** Aggregate counts only (sessions, members, check-in completion rate, no scores or
   identities).
5. **Sponsor credit.** Reuse the "Sponsored" badge for covered members only; nothing public about who is covered.

### R3. Counsellor practice tools (later, and not paid ranking)

Once there are enough verified counsellors, test an optional subscription for tools (calendar sync, receipts for
clients, outcome reports). Reject any featured or paid-ranking placement unless the product owner decides otherwise.

Rough tickets:
1. **Survey counsellors** on which tools they would pay for and at what GHS price.
2. **Recurring counsellor billing.** A saved authorization, following `CaptureOrganizationPaymentInstrumentAction`.
3. **First tool:** probably client receipts or outcome reports.

Keep the 10% commission and waivers as they are while these are tested. Revisit commission tiers (3.1) only with
real take-rate data from the new metrics.

## 6. Open questions for the product owner

1. Is TalkTherapy (or will it be) a company, a non-profit or both? This decides grants, donations and tax.
2. Do real session prices and volumes exist to replace the projection's assumptions? Who can run read-only reports?
3. Is a seat or access fee for organisations acceptable, and should a commission-free period pause it too?
4. Which groups should the access fund serve first (students, minors, NGO referrals), and who decides eligibility?
5. Should access-fund sessions be fee-free, at a reduced fee, or at the normal fee?
6. Is any form of paid "featured" counsellor placement acceptable, or is it ruled out?
7. Will paid features be offered outside Ghana (USD clients abroad), given licensing and data-law differences?
8. Is there appetite to approach an insurer or the NHIA for a pilot, given facility accreditation?
9. Who gives the tax opinion on VAT for the commission, seat fees and subscriptions, and on Mental Health Act
   licensing for an online service?
10. Should `money.revenue` in the admin overview be renamed "Payments collected" so it isn't read as platform income?
