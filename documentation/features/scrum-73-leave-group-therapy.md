# SCRUM-73 + SCRUM-383: leaving a group therapy, and group invitation links

## What was built

### One way to join, one way to add a counsellor

- **`AddGroupTherapyMemberAction`** is the only place a member is added: the join button
  (`JoinGroupTherapyAction`), an accepted membership request
  (`RespondToGroupTherapyMembershipRequestAction`) and a member invitation link
  (`PerformGroupTherapyLinkAction`). The caller holds the group's row lock. It refuses an ended group,
  a full group (current members only) and anyone already taking part. The join button and an accepted
  request run the same checks first (`ensureCanAdd()`); an accepted request that can no longer be met
  is rejected with the reason. Once the join commits it alerts a minor's guardians
  (`GroupTherapyMemberJoinedGuardianNotification`: "accepted" for a request, "joined" otherwise) and
  refreshes the group's open pages (`GroupTherapyMembershipChangedEvent`). Joining is throttled
  (10 a minute). A former member rejoins on
  their old row: `left_at` is cleared, anonymity is chosen again (an anonymous group still forces it
  on), `was_minor_at_join` stays true once it was true, and the original `created_at` is kept (it is
  the join date the free-history rule reads).
- **`AddCounsellorToGroupTherapyAction`** (extracted from `RespondToGroupTherapyAssistanceRequestAction`)
  is used by accepting an assistance invite and by a counsellor link. A current **or former** member
  can't become one of the group's counsellors.
- **Changed:** joining an ended group is refused. An immediate join now alerts a minor's guardians,
  just as accepting a request already did ("Your ward has joined ...").

### Membership history (`group_therapy_user.left_at`)

- `GroupTherapy::users()` and `User::groupTherapies()` list **current** members only.
  `GroupTherapy::allMemberships()` and `User::allGroupTherapyMemberships()` include former members.
- Former members still count in `Report::involves()` (and a guardian of a former member is still
  "involved"), and for the dob-change approval rule (`EnsureDobChangeIsAllowedAction`). So leaving,
  editing your dob across 18 and rejoining can't get round the rule.
- **Former members can report** (the user's decision): the group and its sessions
  (`EnsureCanReportReportableAction`). An administrator sees them in the report's participants as a
  "former member" and may name them as the reported person (`RecordReportedPartyAction`). Messages
  aren't a reportable type in this app; a message is reported through its session or group.

### Leaving (SCRUM-73)

`POST /api/group-therapies/{groupTherapyId}/leave` (`api.group.therapies.leave`, 10 per minute)
calls `LeaveGroupTherapyAction`. Inside one transaction it locks the group and then the leaver's user
row. It then:
- refuses the creator ("You created this group. End or delete it instead."), a counsellor or
  non-member ("You are not a member of this group."), a second leave ("You are no longer a member of
  this group.") and an ended group (422);
- sets `left_at`;
- closes the leaver's pending safeguarding-exception requests in this group as `inconsequencial`,
  with `data.reason` "The member left the group." (the rest of `data` kept).

After the commit, each of these steps is best effort:
- the leaver is ejected from the group's live call (new `NO_LONGER_MEMBER` removal policy, under
  `VideoJoinLock`, retried from the queue when the lock is busy);
- the creator and active counsellors get "a member left your group", naming the member as the group
  showed them ("Anonymous" for an anonymous member, never an id);
- a minor's guardians get a neutral "your ward left";
- a counsellor whose safeguarding request was closed is told, neutrally, that it is no longer needed
  (the request is under "Closed" in their Requests modal: "This request was closed: it is no longer
  needed.");
- `GroupTherapyLeftEvent` goes to the leaver's own channel, and their other open pages go home;
- a payload-less `GroupTherapyMembershipChangedEvent` goes to the group's presence channel, and open
  pages reload the group;
- `group_therapy.member_left` is logged with ids.

Nothing closes the leaver's existing websocket subscriptions server-side. Reverb v1.10's
terminate-connections API answers 500 when a connection's first channel is a private one (always the
case here), so the call was removed after it failed against the running stack. The leaver's own pages
leave the group's channel (from the leaving page, or on `GroupTherapyLeftEvent`), and every later
subscription is refused. A deliberately modified client keeps what it already has open until it
disconnects; see "Websocket subscriptions after leaving" in `documentation/operations/monitoring.md`.

Leaving is allowed while paused for a missing guardian, and while account-suspended
(`EnsureAccountIsNotSuspended` lets this route through). While suspended this is **API-only**: the
suspended page has no group UI.

Afterwards, opening a private group shows the leaver only a **"You left this group"** box: the
group's name, a "make a report" button (the usual report form, for the group as a whole or one of
its active counsellors), and "ask to join again" / "join again", which follows the normal join rules.
The server sends only a reduced preview (`GroupTherapyPreviewResource`, below); the chat stays
closed. A public group they can still read, with the same box above it.

Nothing is refunded automatically. A refund can still be requested as usual (ownership-based), and an
unrefunded payment still counts after rejoining.

The UI (Participants tab, "Membership"): a member sees "leave group". Its confirmation says that
messages stay, that nothing is refunded automatically and a refund can be requested, and that they can
rejoin.

### Messages stay anonymous

`messages.sender_anonymous` and `messages.recipient_anonymous` are snapshots taken when a group
message is sent (`CreateMessageAction`), using the same rule the display uses, and so is
`sessions.updatedby_anonymous` when a member changes a session's status. `SessionResource` masks
`updatedById` the same way. `MessageResource` masks the sender when the snapshot **or** the
live rule says so. So a message sent anonymously stays masked after its sender rejoins as named, and
the existing SCRUM-380 rule still masks someone who has left. Existing group messages were backfilled
in the migration.

### Group links (SCRUM-383)

`PerformLinkAction` sends every group link type to `PerformGroupTherapyLinkAction`. That action locks
the link, then the group, then any requests it closes. It re-checks that the link is active and that
the group's creator isn't paused, both under the lock. Each link works once.

- **Counsellor link:** the counsellor joins the team, as with accepting an invite (their pending
  invite closes). The creator is told, and so are a minor creator's guardians. Refused for: a full
  group, an ended one, an unverified or suspended counsellor, someone with no counsellor profile, the
  creator, a member, and a paused creator's group (neutral, no error code).
- **Member link:** opening it redirects to the group page with `?invitation={uuid}`. The page then
  shows "you have been invited to join this group therapy" with a join button. For a private group the
  invitation is one more grant in the normal access check (`EnsureUserHasAccessToTherapyAction`, fed
  by `FindUsableGroupInvitationAction`), and it only ever shows a **reduced preview**
  (`GroupTherapyPreviewResource`): name, about, cases, counts, and the creator masked unless they
  created it through their counsellor profile; only active counsellors, by public counsellor name.
  No members, sessions, topics or payments. The invitation isn't usable (the page stays closed) for
  another group's link, a counsellor link, an inactive link, your own link, a link meant for someone
  else, a guest, an ended group, or a creator paused for a missing guardian. Confirming (`POST /api/links/{uuid}/join`, with
  `anonymous`) adds them without the creator's approval. The guardian gate, capacity, ended and
  already-a-member checks still apply. Their pending membership request is closed. A minor's guardians
  are alerted.
- **Admin link:** refused when you create one, and when you use an existing one ("This link is no
  longer active."). The app has no group-admin role.
- A counsellor link is refused at creation too when the group has no counsellor place left (the panel
  is hidden then). A suspended counsellor is refused for every counsellor-making link in one place
  (`PerformLinkAction::COUNSELLOR_TYPES`).

The creator sees two link panels in the Participants tab: **Counsellor links** and **Member links**
(`TherapyLinksPanel.vue`). Both use one pair of helpers in `UnifiedTherapy.vue`
(`createTherapyLink(kind)` / `getTherapyLinks(kind)`).

## How to try it

All passwords are `password`. App at http://localhost:8000, mail at http://localhost:8027. The group
is "Leave Demo Group Therapy" (id 16 in the current local database; get it and fresh link URLs with
the tinker snippet in `documentation/seeded-data.md`, "Leaving a group, and group invitation links").
Current local links: member `http://localhost:8000/links/61daf278-476b-40fd-b722-71f70d9f5fd7`,
counsellor `http://localhost:8000/links/092b478b-cb3d-43f4-b41e-16bcf95602d3`.

1. **Leave as an anonymous member.** Log in as `leave_demo_anonymous_member` and open the group
   (`/group-therapies/16`). Go to Participants, then Membership ("2 of 5 members"), then "leave
   group", and confirm.
   - You land on home, once.
   - `leave_demo_creator` and `leave_demo_counsellor` get "Client (Anonymous User) has left the
     group ..." (in Mailpit and in their notifications) -- the group's anonymous label, never the name.
   - Opening the group again shows only the "You left this group" box.
2. **Leave as a minor.** Log in as `leave_demo_minor_member` and leave.
   - `leave_demo_guardian` gets "Your ward has left the group therapy".
3. **Refusals.** As `leave_demo_creator` or `leave_demo_counsellor`, the Membership box shows no
   leave button, and only the creator sees "end group therapy". Calling the leave endpoint directly
   returns the 422 messages listed above.
4. **Former member's message.** The chat page only shows the active session, and the seeded "Leave
   Demo Session" (id 44 locally) is held, so check it through the API. Opening the API URL in the
   address bar doesn't work (Sanctum only treats a request as logged in when it comes from the app,
   and redirects to login otherwise), so use curl with a `Referer` header, logged in as
   `leave_demo_counsellor`:
   ```bash
   J=/tmp/leave_demo_counsellor.jar
   curl -s -o /dev/null -c $J http://localhost:8000/login
   XSRF=$(awk '/XSRF-TOKEN/ {print $7}' $J | python3 -c 'import sys, urllib.parse; print(urllib.parse.unquote(sys.stdin.read().strip()))')
   curl -s -o /dev/null -b $J -c $J -H "X-XSRF-TOKEN: $XSRF" -X POST http://localhost:8000/login \
     -d "username=leave_demo_counsellor&password=password"
   curl -s -b $J -H "Accept: application/json" -H "Referer: http://localhost:8000/" \
     http://localhost:8000/api/sessions/44/messages
   ```
   Felix Former's message ("Thanks -- this helped me a lot.") has `fromUserId: null` -- it stays
   anonymous. (Felix's and Ada's messages both show `null`; only the counsellor's has an id.)
5. **Former member: report and rejoin.** As `leave_demo_former_member`, open the group.
   - You see "You left this group", with "make a report" and "ask to join again", and nothing else.
   - "make a report": the usual report form, for the group in general or Dr. Leo Counsellor.
   - "ask to join again": the group is private and PAID and needs approval, so the dialog says it
     sends a request to the group's creator, and its button is "send request". `leave_demo_creator`
     sees it in the Membership box (after a reload: a new request isn't pushed live) and can accept
     it; you are then a member again (your earlier payment still counts).
6. **Member link.** As `leave_demo_invitee`, open the member link.
   - You land on the group page, open on its Participants tab, with "you have been invited to join
     this group therapy" and a "join group" button in the Membership box. The "about" tab has the
     group's name and about; you see its counsellors, but not the creator's name, sessions or topics.
   - Click "join group", choose anonymity, and confirm ("You were invited, so you join straight
     away."). You are a member straight away.
   - If `leave_demo_creator` has the group open, its member count and member links refresh on their
     own: the used link now shows as inactive.
   - Opening the same link again says it is no longer active.
7. **Counsellor link.** As `leave_demo_link_counsellor`, open the counsellor link.
   - You land on the group page as one of its counsellors.
   - `leave_demo_creator` is told.
   - The group now has 2 of its 2 counsellor places, so the creator's counsellor-link panel goes
     away, and creating another counsellor link is refused.
8. **Make links.** As `leave_demo_creator`, use "get link" in each panel.
   - Each panel lists its new link once (one card per link). Its address is on this app's own
     origin (`http://localhost:8000/links/...` locally), and the copy button copies that.

## Test data

- **"Leave Demo Group Therapy"** (`DatabaseSeeder::createLeaveGroupDemoData()`), private and PAID
  (each seeded member has a SUCCESS payment), with
  `leave_demo_creator`, `leave_demo_counsellor`, `leave_demo_anonymous_member`,
  `leave_demo_minor_member` + `leave_demo_guardian`, `leave_demo_former_member` (left three days ago),
  `leave_demo_invitee` (for the member link), `leave_demo_link_counsellor` (for the counsellor link),
  and one active link of each kind. See `documentation/seeded-data.md` for the full table and the
  tinker snippet that prints the link URLs.
- Each link works once. To reset the demo, run `migrate:fresh --seed`, or re-activate a link as the
  creator from its panel.

## Tests

- `tests/Feature/GroupTherapyLeaveAndLinksTest.php`: leaving, rejoining, messages, refunds,
  dob/report history, joins, and all three link types, including the race.
- `tests/Feature/GroupTherapyReviewFixesTest.php`: former members reporting and their preview, the
  invitation preview and every invitation that must not open a private group, the anonymity
  snapshots, closed-account names in notices, closing safeguarding requests, the shared join step,
  throttling, the member-count query, and an architecture check that nothing in `app/` writes
  membership rows through `users()` / `groupTherapies()`.
- `tests/Feature/GuardianPauseEntryPointsTest.php`: the join-by-link route is gated, and the leave
  route is on the allowlist.
- `tests/Feature/GuardianPauseFollowupsTest.php`: the link tests are updated for the move.
