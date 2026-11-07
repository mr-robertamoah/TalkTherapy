# Ranking by preferences, card buttons, and offering to help (SCRUM-113, SCRUM-114, SCRUM-389)

## What was built

### SCRUM-113: what people are shown, ranked by their preferences

- The Home page's public therapies, public groups and counsellors rows, and the Public Therapies page
  (`/public-therapies`), now show what matches the viewer's saved preferences (Preferences page:
  cases, languages, religions) first, more matched kinds higher, with a "Matches your preferences"
  label. Nothing is hidden. Guests and people with no preferences see the usual mixed order.
- A counsellor browsing therapies and groups is matched on the therapy's cases against their own,
  and on the client's saved languages against theirs -- never religions (security review, the user's
  decision). They only ever see the label, never what matched.
- "Load more" keeps the order for the visit (a seed per page visit), so nothing repeats or is skipped.
- The Public Therapies page is ranked and paged in the database (it used to load everything).
- Saving preferences accepts only real ids, up to 20 of each, and an empty list clears one. The
  Preferences page now shows what's saved (it showed nothing, so saving wiped them).
- The Home groups row now includes groups a counsellor created, and leaves out ones you're in.

### SCRUM-114: one button per card, for whoever is looking

Every therapy and group card on Home and the Public Therapies page shows one button, decided on the
server from the same rules the actions enforce:

| Viewer | Button |
|---|---|
| Signed out | View |
| Taking part (client, member, creator, its counsellor) | Open |
| Someone else, on a group | Join (anyone may join) or Ask to join; Request sent; Full; Ended; Not taking new members (its counsellors' verification isn't current) |
| Someone else, on a therapy | View |
| A counsellor | Offer to help (verified, and the therapy/group can take a counsellor); Offer sent; You're invited; otherwise View |
| An administrator (without a counsellor profile) | View |

Join and Ask to join open a small centred modal (by name or anonymously -- locked to anonymous in an
anonymous group) and join without leaving the page. Offer to help asks to confirm, then sends.

### SCRUM-389: offering to help, and the verification follow-ups

- **Groups:** a counsellor can offer to help a public group that has a free counsellor place; the
  group's creator accepts it from their Requests and the counsellor joins its team. The creator can
  also invite more counsellors after setting the group up ("find a counsellor" on the group page).
- **Therapies:** "find a counsellor" (the client) and "offer to help" (a counsellor) on the therapy page
  now open the picker / confirm (the button did nothing before). The page says when an offer is waiting.
- Joining a group's team closes that counsellor's other invites and offers for it.
- Counsellors verified before SCRUM-31 get end dates spread over two months after the first year.
- A counsellor who isn't currently verified can't be sent therapy, group or discussion requests at all.
- Hardening: `verified_at` can't be mass-assigned; a revocation reason can't add links or headings to
  the email; the platform stat for counsellors awaiting verification counts ended and revoked ones too.

## How to try it

Test data: see `documentation/seeded-data.md`, "Ranking, card buttons and offers". Passwords: `password`.

1. **Ranking:** sign in as `discovery_demo_user`. On Home, "Discovery Demo Therapy (matches)",
   "Discovery Demo Group (open)" and Dr. Discovery Helper come first with "Matches your preferences".
   Load more: nothing repeats. Sign out: no labels. `/public-therapies` shows therapies and groups
   together, ranked the same way.
2. **Preferences:** `/preferences` shows the saved case and language; remove them and save -- the
   labels go.
3. **Cards:** still as `discovery_demo_user`: Join on "(open)" (modal, then Open), Ask to join on
   "(ask to join)" (then Request sent), and see Full, Ended and "Not taking new members" on the others.
4. **Offers:** sign in as `discovery_demo_counsellor`: "Offer to help" on "Discovery Demo Therapy
   (matches)" and "Discovery Demo Group (needs a counsellor)" -> Offer sent. As `lapsed_counsellor`
   there is no Offer to help anywhere.
5. **Accepting:** sign in as `discovery_demo_creator` (second browser), open Requests: "A counsellor has
   offered to help with your group therapy: Discovery Demo Group (offer waiting)". Accept -- Dr.
   Discovery Helper shows on the group.
6. **Find a counsellor:** as `discovery_demo_client`, open "Discovery Demo Therapy (other)" ->
   Participants -> find a counsellor; picking a counsellor whose verification has ended is refused.

## Deployment notes

- New migration: an index on `counsellor_group_therapy`. The SCRUM-31 backfill migration was edited
  (not yet on `main`); databases that already ran it from `develop` keep one shared end date.
- The ranking uses MySQL 8 `JSON_TABLE` (and SQLite's `json_each` in tests); CI runs the MySQL branch
  in the `mysql-json` group.
- The next promotion to `main` needs `log_bin_trust_function_creators=1` on production first (README).
- Follow-ups: SCRUM-392.
