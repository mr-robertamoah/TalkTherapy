# Join links part 2, shareable profile links, group typing (SCRUM-415, SCRUM-51, SCRUM-68)

Delivered together in one PR. Every account below is from the normal seed (`documentation/seeded-data.md`); the
password for all of them is `password`. No new seed data is needed.

## 1. Organisation join links (SCRUM-415)

### What was built
- **Opening a join link** shows a page with the organisation (name, logo, description) and a **Request to join**
  button. Opening it changes nothing.
- **Pressing the button** sends the application. The result ("Request sent to …", or why it couldn't be) appears on
  the same page.
- **A link that can't be used** shows a page saying why: already used or revoked, already a member, already applied,
  your own link, or the organisation isn't taking members. In that last case the reason is the same generic one
  whichever setting is off. The page shows nothing about the organisation.
- **My organizations** has a **Pending applications** list: your own member applications still waiting, whether from a
  link or from the directory.
- **The admin "Join links" card** (organisation dashboard):
  - lists every join link made for the organisation by any of its admins;
  - marks each one **Active**, **Used** or **Revoked**, with a copy box for each active link;
  - lets an admin **revoke** an active link after confirming.
- **New sign-ups:** someone who opens a link while signed out and then **signs up** (rather than signing in) gets a
  "Continue to your invitation" box on the Preferences page. Signing in already took people back to the link.
- **Fixes included:**
  - a used link (of any kind) can no longer be switched back on;
  - an organisation join link is never switched back on;
  - an existing member can no longer apply to their own organisation.

### Try it
1. Log in as `org_demo_admin` and open `/organizations/1/dashboard`.
2. In **Join links**, press **create join link**. The new link appears at the top, marked **Active** and **New**.
   Copy it.
3. In another browser (or a private window), log in as `refund_demo_client` (any account that is neither a member nor
   already applied) and open the link.
   - You should see "Join as a member / Org Demo Wellness Collective" and a **Request to join** button.
4. Press it.
   - You should see a green box: "Request sent to Org Demo Wellness Collective."
5. Open the same link again.
   - You should see "This join link can't be used — This link is no longer active."
6. As `refund_demo_client`, open `/organizations/mine/dashboard`.
   - **Pending applications** should list Org Demo Wellness Collective.
7. Back as the admin, reload the dashboard.
   - The link should now read **Used**.
8. Create another link. Log in as `org_demo_plain_admin` (a co-admin) and open the same dashboard.
   - The new link is listed there too.
9. As the co-admin, press **revoke**, then confirm.
   - It should read **Revoked**.
   - Opening it shows the "no longer active" page.
10. **Sign-up path:**
    1. Create a link.
    2. Open it in a fresh private window (you are sent to log in).
    3. Go to **Want to register?** and create an account.
    4. On Preferences, press **Continue to your invitation**.
    5. You should land on the join page.

## 2. Shareable profile links (SCRUM-51)

### What was built
- **Counsellor profile pages** (`/counsellor/{id}`) have a **Share** card with the profile link and a **copy**
  button. A **share** button also appears where the device has a share sheet (phones).
- **Visitors**, signed in or not, see it only while the counsellor is verified and not suspended.
- **The counsellor** always sees it on their own profile. When the profile can't be shared yet, it says why.
- **Clients and ordinary users** have no public profile and get nothing.

### Try it
- **Signed out:** open `/counsellor/9` (`payment_demo_counsellor`). The **Share this profile** card shows
  `…/counsellor/9`, and **copy** says "Link copied."
- **Own profile:** log in as `payment_demo_counsellor` and open the same page. The card reads **Share your profile**.
- **Unverified counsellor:**
  - Log in as `unverified_counsellor` and open your own profile. The card says "Your profile can be shared once you
    are verified."
  - Signed out, the same profile shows no share card at all.

## 3. Typing in group and discussion chats (SCRUM-68)

### What was built
- **Group therapy session chats** and **discussion chats** show who else is typing, above the message box:
  - "Ama is typing…"
  - "Ama and Kofi are typing…"
  - "Ama, Kofi and 2 others are typing…"
- **Names come from the server's list of who is in the chat.** An anonymous group member only ever appears under
  their anonymous name.
- **Someone disappears from the line** as soon as they send (or clear their text), or after 5 seconds without typing.
- **One-to-one therapy chat** is unchanged (it still says "typing…").

### Try it
**Group chat:** use three browsers or windows on `/group-therapies/11/chat` ("Group Video Call Demo"; its session is
already in progress).
- Log in as `group_video_call_demo_counsellor`, `group_video_call_demo_member` and
  `group_video_call_demo_anonymous_member`.
- Type as the anonymous member. The other two see "Client (Anonymous User) is typing…", never the username.
- Type in a second window too. You should see both names, e.g. "Client (Anonymous User) and GroupVideoCall
  DemoMember are typing…".
- Send or clear the text. That name drops off at once.

**Discussion chat:** use three windows on `/discussions/1/chat`.
- Log in as `sarah_johnson`, `emma_williams` and `david_brown`.
- Type in one window. The others see "Dr. Emma Williams is typing…" (and so on).

### Deployment note
`config/reverb.php` now sets `accept_client_events_from = members`.
- Reverb reads this setting only when it starts, so restart it after deploying. `deploy.sh` runs `reverb:restart`.
- Locally, run `docker compose restart reverb`.
- Until Reverb restarts, the names line simply stays empty.
