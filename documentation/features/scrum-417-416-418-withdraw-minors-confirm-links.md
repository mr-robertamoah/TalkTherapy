# Withdraw an application, minors joining organisations, links confirmed first (SCRUM-417, SCRUM-416, SCRUM-418)

All three are in one PR, with one commit per ticket. All the accounts below come from the normal seed
(`documentation/seeded-data.md`), and the password for every one is `password`.

## 1. Withdraw a pending organisation application (SCRUM-417)

### What was built
- **Withdraw button.** Each row of **Pending applications** on My organizations has a **withdraw** button.
- **Confirm first.** Pressing it asks you to confirm, with **withdraw** or **keep it**.
- **What withdrawing does:**
  - closes the application;
  - removes it from the organisation admins' queue;
  - shows "Your application to … was withdrawn." in its place.
- **Applying again.** You can apply again later.
- **Answering a withdrawn application.** An admin who tries is told "This application was withdrawn."

### Try it
1. Log in as `org_demo_member_applicant` (seeded with a pending application to Org Demo Wellness Collective) and open
   `/organizations/mine/dashboard`.
2. Under **Pending applications**, press **withdraw**, then **withdraw** again.
   - The row goes and a green line confirms it.
3. Log in as `org_demo_admin`, open `/organizations/1/dashboard` and look at the request queue.
   - The application is no longer there.

## 2. Minors joining an organisation (SCRUM-416)

### What was built
- **Minors without a guardian are refused.** A minor who has no active guardian, and no safeguarding exception,
  can't become a member of an organisation in any of these ways:
  - **applying**, directly or through a join link. They're told: "You can join an organization once a guardian is
    added to your account."
  - **accepting an organisation's invitation.** They get the same message.
  - **an admin accepting their application**, if the minor lost their guardian in the meantime. The admin gets only
    the neutral message "This request can't be accepted right now."
- **Invitations still go out.** Admins can still invite such a minor; refusing the invitation itself would tell the
  admin who is a minor.
- **Guardians are told.** When a minor with a guardian becomes a member, their guardians get a notification by email
  and in the notification center: "Your ward joined an organization".

### Try it
There's no seeded minor without a guardian. The Pest tests in `tests/Feature/MinorOrganizationMembershipTest.php`
cover every path.

To see it by hand:
1. In tinker, give a test account a date of birth under 18 and no guardian.
2. Log in as that account and apply to Org Demo Wellness Collective from **My organizations**.

## 3. Links are confirmed first (SCRUM-418)

### What was built
Opening any of these links now shows a page first and changes nothing until you confirm:
- guardianship;
- a therapy's counsellor;
- a discussion;
- a group's counsellor.

**Accept** makes it happen, and **Continue** takes you on:

| Link | Continue goes to |
|---|---|
| Guardianship | your profile |
| Therapy counsellor | the therapy |
| Discussion | the discussion's chat |
| Group counsellor | the group |

What the page shows, and what it never shows:

| Link | Shows | Never shows |
|---|---|---|
| Guardianship | who is asking: name, username, photo | age, date of birth, email, whether they're a minor |
| Therapy | name, whether it's private, public or anonymous, when it started, the client as others see them (anonymous stays anonymous); the background story only if the link was sent to you or the therapy is public | anything else about the client |
| Discussion | name, description, times, who started it, whether it's about a therapy or a group | the client, the therapy's name |
| Group | name, about, the creator as others see them | members |

A link that can't be used shows only the reason.

### Try it
Use tinker to create a link (`Link::forceCreate`), then open `/links/{uuid}`.

- **Therapy:** a THERAPY_COUNSELLOR link for therapy 25, "Suspension Demo Therapy (waiting for a counsellor)", whose
  client is `suspension_waiting_client`. Open it as `lisa_anderson`.
- **Discussion:** a DISCUSSION link for discussion 1, created by `sarah_johnson`. Open it as `james_wilson`.
- **Group:** a GROUP_THERAPY_COUNSELLOR link for group 11, "Group Video Call Demo", created by
  `group_video_call_demo_creator`. Open it as `michael_chen`.
- **Guardianship:** a GUARDIANSHIP link created by an account, for itself (e.g. `refund_demo_client`). Open it as
  `payment_demo_client`.

Each page shows a summary. Press **Accept**, then **Continue**.

Accepting changes the seeded data: it assigns a counsellor, adds someone to a group or discussion, or creates a
guardianship. Undo it in tinker afterwards if you want the demos as they were.
