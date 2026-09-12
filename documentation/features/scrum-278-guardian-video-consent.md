# TT-3.1e: Guardian/Minor Video-Consent Enforcement (SCRUM-278)

A minor client's video call requires an active guardian to explicitly approve it first. Closes
the epic that started with an urgent interim fix (PR #216 — every minor was simply blocked from
video entirely, with no consent path at all) and ended with a full grant/revoke/reminder/UI flow
across seven sub-tickets (TT-3.1e-a through -g).

## What was built

- **Data model** (TT-3.1e-a): `therapies.video_consent_mode` (`PER_THERAPY` — one approval covers
  every session; `PER_SESSION` — each session needs its own) and a `video_consents` table, one
  row per grant, never deleted — revocation sets `revoked_at`/`revoked_by_guardian_id`/
  `revocation_reason` on the existing row rather than creating a new one, preserving full
  grant→revoke→re-grant history per scope.
- **Grant flow** (TT-3.1e-b): `GrantVideoConsentAction` — any ONE guardian of the ward may grant
  (no unanimity), idempotent and race-safe. Fails closed if no mode has been actively set yet
  (never silently defaults to the more-permissive `PER_THERAPY`), and rejects a grant whose scope
  doesn't match the therapy's *current* mode.
- **Invalidation flow** (TT-3.1e-c): `RevokeVideoConsentAction`/`InvalidateVideoConsentAction` —
  any one guardian may revoke, and a guardianship deletion automatically lapses that specific
  guardian's own grants for that ward. Either path immediately ends any currently-active video
  call under the revoked scope (not a flag checked on the next join attempt).
- **Real enforcement** (TT-3.1e-d): `HasValidVideoConsentForSessionAction` — one indexed query,
  OR-ing across both scope types, that never reads the therapy's current mode at all. This is
  what makes a later mode switch "prospective-only" for free: a grant made under an old mode still
  satisfies this check after the mode changes.
- **Reminders** (TT-3.1e-e): a guardian is reminded a day-before and an hour-before a session,
  but only while consent is still outstanding for the relevant scope — once granted, reminders
  stop for that scope.
- **Frontend + first HTTP wiring** (TT-3.1e-f): a "video consent" tab on the therapy page (mode
  toggle for the counsellor or any guardian; approve/revoke — behind a confirmation modal — and an
  audit trail for guardians only), and a specific "guardian consent needed" banner (not a generic
  error) when a minor's join attempt is blocked.
- **Closeout** (TT-3.1e-g, this ticket): an end-to-end regression matrix
  (`tests/Feature/GuardianVideoConsentRegressionTest.php`) proving every sub-ticket's own,
  independently-reviewed behavior holds up together through the real HTTP routes — no gaps found.

## How to try it out

### Test data

Seeded by `DatabaseSeeder::createGuardianVideoConsentDemoData()` — see
`documentation/seeded-data.md`'s "SCRUM-285" section for full details:

| Username | Password | Role |
|---|---|---|
| `video_consent_demo_minor` | `password` | The therapy's own client (a minor, `dob` set to 15 years ago) |
| `video_consent_demo_guardian` | `password` | The minor's guardian |
| `video_consent_demo_counsellor` | `password` | The assigned counsellor |

"Video Consent Demo Therapy" is already in `PER_THERAPY` mode with an immediately in-progress
online session, deliberately left **without** a grant, so the blocked state is visible
immediately on login.

### Steps

1. Log in as `video_consent_demo_minor` and open "Video Consent Demo Therapy" → chat page → click
   "join video". You'll see the specific **"Guardian consent needed"** banner, not a generic
   error.
2. Log out, log in as `video_consent_demo_guardian`, open the same therapy → **"video consent"**
   tab. You'll see "Video consent has not been given yet" and an **approve video access** button.
   Click it.
3. Try switching the consent mode (per therapy / per session) — the toggle is also visible to
   `video_consent_demo_counsellor`, but only the guardian sees approve/revoke/audit controls.
4. Click **revoke consent** — a confirmation modal explains the immediate, call-ending
   consequence before you confirm.
5. Click **show consent history** to see the full grant/revoke audit trail, including who acted
   and when.
6. Log back in as `video_consent_demo_minor` and try "join video" again — with consent granted,
   the join proceeds past the consent check (it will still fail at the actual WebRTC connection
   step, since this dev environment has no live Daily.co credentials — a known, pre-existing
   limitation flagged since TT-3.1c/d, not something this feature can fix locally).

### What a successful result looks like

- A minor with no valid consent for the relevant scope cannot join video, and sees a specific
  explanation naming where to go get it — never a generic "something went wrong."
- A guardian (any one of several, if the ward has more than one) can approve or revoke consent
  from the therapy's own page, and every guardian of that ward — not just the one who acted — can
  see the full history of who granted/revoked and when.
- Revoking consent (or a guardianship being deleted) ends an already-active call immediately,
  verified end-to-end through the real HTTP routes in this closeout's own regression suite, not
  just at the underlying Action's own unit level.
- Switching between `PER_THERAPY` and `PER_SESSION` mode never retroactively invalidates a grant
  already made under the prior mode.
- A minor with no guardian at all is permanently blocked — there is no one who could ever grant on
  their behalf, so this fails closed by construction, not by an extra check that could be missed.
- The full backend test suite (1665 tests) covers all of the above without needing live
  Daily.co/Reverb credentials.

## Known, accepted limitations (not gaps — logged and deliberate)

- No DB-level constraint prevents two simultaneously-valid grants for one scope (MySQL 8 has no
  partial/filtered unique index); `GrantVideoConsentAction`'s own row-locking is the sole enforcer
  of "exactly one valid grant per scope."
- A join-time TOCTOU window exists between the consent check and credential issuance — if a
  guardian revokes in that exact instant, a join can succeed once. This does not apply to an
  already-active call, which both revoke and a guardianship deletion end synchronously and
  unconditionally regardless of when the call started.
- The guardian's approve/revoke button acts on the single *soonest* eligible session under
  `PER_SESSION` mode, not a full per-session management list — an accepted simplification given
  this ticket's one-button scope.

## Out of scope, filed separately

- **SCRUM-287**: a user's self-editable `dob` has no upward-age floor, so a minor could self-edit
  past 18 to skip every `isAdult()`-gated safeguard platform-wide (not specific to video) — found
  during TT-3.1e-d's security review, needs its own product/architect pass.
- **SCRUM-288**: whether a minor who loses their *only* guardian should lose the whole therapy
  (not just video access) — the user's own explicit direction during this epic's original scoping
  was to file this separately rather than fold it in here.

## Epic complete

This closes out SCRUM-278 (TT-3.1e) — see `documentation/decision-log.md` for the full trail of
judgment calls made across all seven sub-tickets (TT-3.1e-a–g), including the interim safeguard
that preceded this epic (PR #216) and every review finding applied along the way.
