# In-person sessions: safer start and end, a real meeting place, adults only (SCRUM-443)

Part of SCRUM-115. The location privacy hotfix (who can see where a session meets) is SCRUM-442.

## What was built

### Starting and ending
- Starting and ending a session take two people, one on each side. The first click asks, and the other side confirms.
- The person who asked can't confirm their own request; the server checks this, not just the page.
- In a group, a member's request is confirmed by a counsellor (or an administrator), and a counsellor's request by a member.
- Two members can no longer start, end, or bill a session between themselves.

### The meeting place
- **In-person sessions:** need a pin on the map and a written description ("Where exactly?", 3–255 characters).
- **Online sessions:** store no place. They used to be saved at 0,0, and existing ones are cleared by a migration.
- **Switching to online:** clears the place.
- **Edits:** an edit that doesn't send the place keeps it, and the edit form opens on the saved pin and description.
- **Schedule proposals:** an in-person proposal carries a place too, and accepting it books the session there. A counter-offer keeps the place unless it switches to online.
- **Shared picker:** the create, edit and propose forms all use one picker (`InPersonPlacePicker`).

### Adults only
- In-person sessions are for adults (18 and over); someone with no date of birth counts as under 18.
- A therapy or group can't switch in-person on while a client or member is under 18, and a minor's checkbox is disabled, with the reason shown.
- A minor can't join a group that meets in person, whether through the join button, a membership request or an invitation link.
- No in-person session is created, proposed or started while someone under 18 takes part. This includes therapies switched on before the rule.
- In a group, members and user creators get a neutral message ("In-person sessions aren't available for this group right now."), so nobody learns another member's age.

### Anonymity
Wherever in-person meets an anonymous therapy or group, a note says that the people there will see you. It appears in the therapy settings, the join dialog, and the session and proposal forms.

## Try it

**Test data:** see "In-person sessions" in `documentation/seeded-data.md`. All passwords are `password`.

1. **Create:** sign in as `michael_chen`, open "In-Person Demo Therapy" and create a session.
   - Choose "in person". The map and "Where exactly? (required)" appear.
   - Leaving the description empty is refused. With a pin and a description, it saves.
2. **Edit:** edit "In-Person Demo Session".
   - It opens on the saved pin and description.
   - Rename it and save: the place is kept.
   - Switch it to online and save: the location card disappears.
3. **Propose:** as `john_davis` on the same therapy, propose a time in person. The place is required. Accepting it books a session at that place.
4. **Start:** at session time, the counsellor starts the session and the client confirms it. The counsellor can't confirm their own request.
5. **Adults only:** as `your_data_demo_ward` (a minor), the "Allow in person sessions" box is disabled, with the reason.

## Notes
- **Maps:** the map uses Google Maps (`VITE_GOOGLE_API_KEY`). Place search and directions come next, in SCRUM-444.
- **Migration:** `2026_12_06_100000_clear_place_of_online_sessions` clears the place of online sessions. It can't be reversed, since the values were meaningless.
- **Factories:** `allow_in_person` now defaults to false, and tests opt in.
