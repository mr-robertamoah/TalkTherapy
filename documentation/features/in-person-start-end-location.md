# In-person sessions: safer start and end, a real meeting place, adults only (SCRUM-443, SCRUM-444)

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

### Finding the place and getting there (SCRUM-444)
- **Place search:** the picker has a "Search for the place" box with Google Places suggestions, biased to Ghana (not limited to it).
  - Choosing a suggestion moves the map there and drops the pin.
  - If "Where exactly?" is empty, it's filled with the place's name (or its address when the name is too short). If it already has text, the picker asks: "Use “…” as the description?" with "Use it" and "Keep mine". The description stays editable either way.
  - Clicking the map and "current location" still work.
- **Fallback:** no error alert is shown for search in any of these cases, and the description always works.
  - **No API key, or the Places library can't load:** the box never appears; the note "Search isn't available; pick the place on the map." shows instead. Without a key the map itself may not work either: Google Maps can show its own error overlay over the map, and a click may not drop a pin.
  - **Places API (New) not enabled on the key:** the box shows, and Google only refuses on the first search (`gmp-error`). The box is then replaced by the same note, and focus moves to "Where exactly?" if it was in the box. The map (Maps JavaScript API) keeps working.
  - **A passing failure fetching the chosen place's details:** the box stays, with "Search didn't work just now. Try again, or pick the place on the map."
- **Offered description:** a "Use … ?" offer goes away when the pin is moved another way (map click, drag, current location).
- **Chosen before the map is ready:** the pin is set at once and drawn when the map appears.
- **Loading:** the Maps and Places scripts load only when the picker is shown (in-person chosen).
- **Directions:** in the session view's Location card, anyone who can see the pin gets an "Open in Google Maps" link (`https://www.google.com/maps/search/?api=1&query=<lat>,<lng>`, opens in a new tab). It's built only from the pin the server sends, so people the server hides the place from (SCRUM-442) get no link, and neither does a session whose pin was cleared after 30 days (SCRUM-447) -- the landmark still shows.

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
5. **Place search:** in step 1, type a place (e.g. "Accra Mall") in "Search for the place" and choose a suggestion.
   - The map moves and the pin drops there; an empty "Where exactly?" fills with the place's name.
   - Type your own description, then search again: you're asked whether to use the new name, and "Keep mine" leaves your text.
   - Without a key (today's default locally -- see Notes), you see "Search isn't available; pick the place on the map." instead of the box, and the map may show Google's own error. With a key that lacks Places API (New), the box shows, and the note replaces it on the first search.
6. **Directions:** open "In-Person Demo Session" (as `michael_chen` or `john_davis`). The Location card has "Open in Google Maps", which opens Google Maps at the pin in a new tab. A session whose pin has been cleared shows its landmark with no link.
7. **Adults only:** as `your_data_demo_ward` (a minor), the "Allow in person sessions" box is disabled, with the reason.

## Notes
- **Maps and where the key goes:** the map and search use `VITE_GOOGLE_API_KEY`, which Vite reads from **`.env`** when it builds or serves the frontend -- even under Docker (the vite container reads `.env`, not `.env.docker`). So the key must be in `.env`, and it must have both the Maps JavaScript API and **Places API (New)** enabled. Locally `.env` usually has no key, so the search fallback is what you'll normally see.
- **Deployment -- Places API:** place search uses the Places API (New) through `PlaceAutocompleteElement`, so **the Places API (New) must be enabled for the key's Google Cloud project** (alongside the Maps JavaScript API), and the key's API restrictions must allow it. Without it, search falls back to the plain map as above. Autocomplete sessions and place details are billed by Google: usage beyond the free monthly credit is charged to the project's billing account, so set a budget alert/quota. The directions link needs no API or key.
- **No server changes:** SCRUM-444 is frontend-only.
- **Migration:** `2026_12_06_100000_clear_place_of_online_sessions` clears the place of online sessions. It can't be reversed, since the values were meaningless.
- **Factories:** `allow_in_person` now defaults to false, and tests opt in.
