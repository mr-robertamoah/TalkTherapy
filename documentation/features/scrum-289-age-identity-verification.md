# TT-4.11: Age/Identity Verification (SCRUM-289)

TT-4.10 (SCRUM-287) closed the *self-edit-after-the-fact* bypass on `dob`, but left `dob` 100%
self-reported at every stage — nothing actually verified a claimed date of birth was true. This
epic adds an optional, admin-reviewed verification path: a user submits a short attestation
(optionally with a supporting document), and an admin's approval marks the account's `dob` as
verified, which supersedes any weaker, unverified evidence. Built across five sub-tickets
(TT-4.11a through -e).

## What was built

- **Storage foundation** (TT-4.11a): a new, hardened `identity_documents` disk — no entry in
  `config/filesystems.php`'s `links` array, so `php artisan storage:link` never symlinks it into
  `public/`, and `getUrlFor()`/`File::url` refuse outright to resolve a URL for a file on it. The
  ONLY retrieval path is `IdentityDocumentController`'s dedicated, authenticated route
  (`isAdmin() || the request's own submitter`), with a uniform 404 for both "no such document" and
  "not authorized" — collapsing what was originally a distinguishable 404-vs-403 enumeration
  oracle. A daily scheduled sweep (`AppService::deleteExpiredIdentityDocuments()`) deletes a
  document once its request has been decided (accepted/rejected) and a configurable retention
  window has passed (`IDENTITY_DOCUMENT_RETENTION_DAYS`, 30-day default).
- **Submission** (TT-4.11b): `SubmitAgeVerificationAction`, reachable via `POST
  /api/age-verification`, always admin-addressed (`to` is null) and self-service only (`from` =
  `for` = the submitting user — no way to target anyone else). A pending resubmission updates the
  same request in place (find-or-reuse-under-lock) rather than creating a duplicate, and
  snapshots the dob being vouched for at that exact moment (`data.attestedDob`) so a later
  approval verifies what was actually attested to, even if the live `dob` column drifts in
  between. Document upload is deliberately OPTIONAL — requiring one would be a real barrier to
  someone who needs help right now. Surfaced as its own card on the Profile page (not the
  "preferences step" the ticket's own text initially suggested — `dob` is only ever edited on the
  Profile page).
- **Approval/rejection** (TT-4.11c): `RespondToAgeVerificationRequestAction` — approving marks
  `users.dob_verified_at` and retroactively corrects every qualifying minor-status snapshot via a
  new `ApplyVerifiedDobAction`, extracted from and now shared with TT-4.10d's own dobChange
  approval flow (one implementation of "what does it mean to correct a person's historical minor
  status," not two). A verified dob is authoritative: any still-pending dobChange request for the
  same user is auto-closed as a new `superseded` status (never `rejected`, which would misleadingly
  imply the guardian's own submission was judged wrong on its own merits) — deliberately
  one-directional; an ordinary dobChange approval never supersedes a pending ageVerification.
  Rejecting has no automated consequence (the user's own explicit decision — moderation, if
  warranted, is a manual admin action).
- **Admin review queue UI** (TT-4.11d): pending requests surface in the existing generic Requests
  list/modal, the same one dobChange already uses, rather than a new page — attestation text,
  attested dob, and a document link (via the authorized route) when one exists.
  `RequestResource`'s existing null-`to` PII-narrowing (previously dobChange-only) now also covers
  ageVerification, since both types are always null-`to` and visible to every admin.
- **Closeout** (TT-4.11e, this ticket): full regression matrix confirming every prior sub-ticket's
  behavior holds up together, plus the gaps found and closed below.

## Gaps found and closed during closeout

Three **test-coverage gaps** (this ticket's own review passes found no code defects — every
sub-ticket's own review had already caught and fixed its own issues before merging):

- No test exercised the document-upload path all the way through an actual approve/reject decision
  (submission and response were each tested end-to-end, but never chained for a request that
  actually had a document attached). Added `tests/Feature/AgeVerificationDocumentApprovalTest.php`.
- The retention-sweep tests all happened to use the same value as the config default (30 days),
  which would pass identically even if the config were never actually read. Added a test in
  `tests/Unit/DeleteExpiredIdentityDocumentsTest.php` using a distinct, non-default window (7 days)
  to prove it's genuinely respected.
- No seeded demo user had a `dob` set at all, so trying the golden path required hand-picking a
  dobChange fixture built for a different feature. Added `age_verification_demo_user` (see below).

One **real bug**, found by `qa-engineer` while smoke-checking that new seed account and fixed in
TT-4.11b/SCRUM-303's own files (per this ticket's own scope rule — a defect gets fixed where it
was introduced, not patched onto this ticket): `AgeVerificationSection.vue`'s "submit a
statement"/"submit another statement" label depended entirely on local, in-session state,
initialized to `false` on every fresh page load — a user with a genuinely still-pending request
always saw "submit a statement" (implying none existed) until they submitted again in that same
session. Fixed by having `ProfileController::show()` pass a real, query-backed
`hasPendingAgeVerification` prop (not a one-time flash — this needs to reflect durable state on
every load, unlike the adjacent `dobChangePendingApproval` flash, which is correct as a flash
since it only needs to survive one redirect).

## How to try it out

### Test data

| Scenario | Account | Notes |
|---|---|---|
| Admin review (pending, attestation only) | `age_verification_demo_user` (`password`) + the super admin `mr_robertamoah` | Adult (`dob` set to 25 years ago) with an already-PENDING `ageVerification` request, reachable immediately without hand-built `tinker` data. |

See `documentation/seeded-data.md`'s "Age verification" section for full details.

### Steps (submission)

1. Log in as any user, go to Profile — the "Age Verification" card sits directly below the
   profile-info card. Click "submit a statement," type a short attestation, optionally attach a
   supporting document (jpg/jpeg/png/pdf, up to 10MB), and submit.
2. A calm confirmation banner appears; the button relabels to "submit another statement" (which
   updates the same still-pending request rather than creating a second one).

### Steps (admin review)

1. Log in as the super admin (`mr_robertamoah`) — the pending request for
   `age_verification_demo_user` is already there, no setup needed. Open "Requests."
2. The entry shows the attested dob, the attestation text, and (if a document was attached) a
   "view submitted document" link that opens it in a new tab via the authenticated retrieval
   route.
3. Click **accept** or **reject**. On accept, `dob_verified_at` is set and the notification is
   sent; on reject, nothing changes and the user is notified with no other consequence.

### What a successful result looks like

- A user can submit with or without a document; either way the request is admin-addressed and
  self-service only.
- An admin who is ALSO the request's own submitter cannot approve their own submission (closed
  during TT-4.11d's own review) — the whole point of this type is an independent check.
- Approving verifies the dob actually attested to at submission time, not whatever the live `dob`
  column says at review time if it happened to change in between.
- Approving retroactively corrects every qualifying `Guardianship`/`Therapy`/`GroupTherapy`
  snapshot, reaching all 11 of TT-4.10b's migrated `isAdult()` call sites (the other 2 are the
  snapshot-writing trigger and an unrelated eligibility check, deliberately excluded from that
  migration).
- Approving supersedes (never rejects) a still-pending dobChange request for the same user;
  approving a dobChange never touches a pending ageVerification (one-directional).
- A `dob_verified_at` marker never survives an edit it didn't apply to: any subsequent direct
  `dob` write (self-service or admin) clears it, so a brand-new, never-reviewed value can never be
  mistaken for a still-verified one.
- The document is only ever reachable via the authorized retrieval route — never a public URL —
  and is automatically deleted once its request has been decided and the configurable retention
  window (30 days by default) has passed.
- The full backend test suite (1837 tests as of this closeout) covers all of the above, including
  the document-upload-through-approval path and a non-default retention window, end-to-end through
  real HTTP routes wherever one exists.

## Known, accepted limitations (not gaps — logged and deliberate)

- No admin-facing "verified"/"unverified" badge anywhere — internal/admin-only by the user's own
  explicit decision, consistent with a "no accusatory UX on a mental-health platform" rationale.
- No automated consequence for a rejected/later-discovered-misrepresented submission — moderation,
  if warranted, is a manual admin action, not an automated account restriction (the user's own
  explicit decision).
- `GetRequestResourceAction`'s per-type dispatch chain grew by one more branch rather than being
  refactored into a type-to-handler map, which this epic's own architect note flagged as optional,
  not required — already tracked, accepted debt (SCRUM-119/120).

## Out of scope, filed separately

- **SCRUM-300** (High, Bug): `FileService`/`File`/`getUrlFor()` serve every OTHER file type
  (including counsellor license/ID documents already collected today) through a public URL with
  zero authorization — a pre-existing gap found while scoping this epic, fixed here only for the
  new `identity_documents` disk specifically (defense-in-depth), not the broader gap. Not yet
  started.
- **SCRUM-301**: making counsellor license/national-ID upload required (currently nullable) — a
  related but distinct policy decision the user made separately during this epic's scoping. Not
  yet started.

## Epic complete

This closes out SCRUM-289 (TT-4.11) — see `documentation/decision-log.md` for the full trail of
judgment calls made across all five sub-tickets (TT-4.11a–e), including the original
`/start-feature` scoping pass's policy decisions and every review finding applied along the way.
