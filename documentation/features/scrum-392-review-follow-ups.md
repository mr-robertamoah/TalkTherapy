# Review follow-ups (SCRUM-392)

Follow-ups from the SCRUM-113/114/389 reviews, plus two older bugs QA found along the way. Mostly
behind the scenes; the parts people notice are below.

## What changed for people

- **One join dialog.** Joining a group from a card (Home, Public Therapies), from the group's own
  page, or through a member invitation link uses the same dialog. "How others in the group see you"
  starts from your saved "stay anonymous" preference everywhere (locked to anonymous in an anonymous
  group). An invitation joins straight away.
- **Joining from a card updates the page.** The card's member count goes up and the group appears
  first in "Your Recent Group Therapies" (Home), without reloading.
- **Find a counsellor lists only counsellors whose verification is current.** The public counsellor
  directory still lists everyone (with their verification shown). A new search in the picker starts
  from its first page.
- **Counsellors may offer to help at most 5 times a day** (therapies and groups together, rolling
  24 hours, whatever became of the offers). Past that: "You have offered to help the most times
  allowed in a day. Please try again tomorrow." Set by `COUNSELLOR_DAILY_OFFER_CAP`.
- **Therapy details show a readable status** ("in session", "pending").
- **Dialogs are announced as dialogs** to screen readers, with their title as their name.
- **Home no longer scrolls sideways on a phone** (375px).

## Behind the scenes

- The public-list queries are model scopes (`Therapy/GroupTherapy::publicForOutsider`) -- never an
  access check.
- Group rules have one home: `isCreatedBy()`, `hasFreeCounsellorPlace()`/`canTakeCounsellor()`,
  `isFull()`; requests: `Request::ASSISTANCE_TYPES`, `whereAssistanceOfferFrom()`,
  `pendingGroupAssistance()`, `whereToOrFrom()`.
- Resources send the raw `status` (e.g. `IN_SESSION`) plus `statusLabel`.

## How to try it

`docker compose up -d`, then http://localhost:8000. Password for every account: `password`.

1. **Join from a card:** log in as `discovery_demo_client`, open Home, press **Join** on "Discovery
   Demo Group (open)". The dialog opens; join. The card shows one more member and **Open**, and the
   group is first in "Your Recent Group Therapies". (To undo locally, leave the group from its page.)
2. **Join from the group page:** open "Discovery Demo Group (ask to join)" → participants → **join
   group**: the same dialog, titled "Ask to join ...", with **Send request**. Cancel.
3. **Find a counsellor:** as `discovery_demo_client`, open "Discovery Demo Therapy (other)" →
   participants → **find a counsellor**, search "dr": only verified counsellors. Search "Unverified":
   none. Change the search: the list is the new search's first page.
4. **Offer cap:** as `discovery_demo_counsellor`, offer to help on public therapies/groups; the sixth
   offer within a day is refused with the message above (or set `COUNSELLOR_DAILY_OFFER_CAP=1` in
   `.env.docker` and recreate the php container to see it sooner).
5. **Status:** open any in-session therapy's details: "In Session".

## Test data

No new seed data. Accounts: `discovery_demo_client`, `discovery_demo_counsellor`,
`discovery_demo_creator` (see `documentation/seeded-data.md`).

## Tests

`tests/Feature/ReviewFollowUpsTest.php` (helpers and scopes, the cap, the picker, status).
