# TT-2.6 (SCRUM-25): Counsellor session calendar

A counsellor can see all of their own sessions — across every individual `Therapy` and every
`GroupTherapy` they're currently assigned to — in a single week/month calendar, instead of opening
each therapy page separately to check what's scheduled. This is the direct payoff of TT-2.5
(session schedule proposals): a counsellor's sessions can now be negotiated asynchronously and
independently across many therapies, and this is the first place they see the combined result.

Split into two sub-tickets: SCRUM-212 (TT-2.6a, backend aggregation) and SCRUM-213 (TT-2.6b,
frontend calendar UI). Individual `Therapy` and `GroupTherapy` only — no other domain.

## What was built

- `GET /api/counsellor/calendar/sessions?startDate=...&endDate=...`: aggregates the authenticated
  counsellor's own sessions across every `Therapy` they're assigned to and every `GroupTherapy`
  they're currently active on (including one they created directly), date-range bounded (max 93
  days per request). Self-scoped only — never another counsellor's or an admin-wide view.
- A new `/counsellor/calendar` page ("My Calendar" in the nav, counsellor-only) with week/month
  toggle, prev/today/next navigation, and individual-vs-group / upcoming-vs-past filters.
- Each calendar entry shows its time, status (color-coded), individual-vs-group label, and
  therapy/group name. Clicking one navigates straight to the underlying `Therapy`/`GroupTherapy`
  page — the calendar itself never mutates a session (no accept/reject/end from here).
- Anonymity is respected: an anonymous therapy's client identity is masked on the calendar exactly
  the way it already is on the therapy page itself (this extraction also fixed the same masking
  logic being independently duplicated in five different places across the codebase).
- No new frontend dependency — built with the existing `date-fns` library and Tailwind, not a
  calendar UI package (see `documentation/decision-log.md` for the build-vs-buy reasoning).

## How to try it out

1. Log in as `sarah_johnson` / `password` (a seeded counsellor with existing sessions — no new
   seed data was needed for this feature).
2. Open the account dropdown (top right) and click **My Calendar**, or visit
   `/counsellor/calendar` directly.
3. The current week shows any existing sessions (e.g. the seeded "Chat Demo Live Session"). Use
   **←** / **today** / **→** to navigate, or switch to **month** view.
4. Use the filters to narrow to individual-only or group-only sessions, or upcoming-only/past-only.
5. Click any session — it navigates to that session's actual Therapy or Group Therapy page.
6. Navigate to a week/month with nothing scheduled to see the empty state (the grid itself stays
   visible for navigation context — only a small banner indicates there's nothing to show).
7. Log in as a non-counsellor account (e.g. `maria_garcia` / `password`) — "My Calendar" doesn't
   appear in the nav, and visiting `/counsellor/calendar` directly redirects home.

## Test data

No new seed data was needed — the existing seeded counsellors (e.g. `sarah_johnson`, with the
"Chat Demo" individual and group therapy fixtures) already have real sessions that appear on the
calendar. See `documentation/seeded-data.md`.

## Follow-ups filed, deliberately out of scope

- None specific to this feature. See `documentation/decision-log.md`'s SCRUM-212 entry for
  follow-ups filed during the backend sub-ticket (SCRUM-211, a pre-existing double-booking check
  gap unrelated to the calendar itself).
