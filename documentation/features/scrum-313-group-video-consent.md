# Guardian video consent for a minor in a group (SCRUM-313)

SCRUM-313 is four PRs:
- PR 1 (merged) made consent per child;
- PR 2, this one, is the consent flow;
- PR 3 (SCRUM-329) lets a guardian choose "watch only" or "speak";
- PR 4 adds reminders and the final QA.

## What was built
Before this, a minor could never join a group's video: a blanket block, waiting for this feature. Now:
- a minor in a group (its creator or a member, never one of its counsellors) joins its video once one of their own
  parents or guardians has approved it, for the **whole group** or for **one session**;
- on the group's chat page, a minor without consent sees "Video calls in a group need a parent or guardian's consent
  first" and an **ask a parent or guardian** button. It tells each of their guardians by notification and email,
  and asking again within 6 hours says they were already asked;
- a guardian answers from **their own therapies page**: Ward Section → Group Therapies → **video consent** under the
  group. A guardian of an ordinary member can't open the group itself, which would show them other members'
  conversation. The panel shows only their own child in that group:
  - "approve for the group" or "approve this session only" (the live session, or else the next one);
  - "withdraw" for a consent already given;
- withdrawing takes the child out of a call straight away, and the rest of the group carries on;
- leaving the group cancels its consent, so coming back needs new consent;
- for now consent means taking part. Members still join to watch and listen, and under-18s still can't be given the
  floor until PR 3.

## Test data
| Account | What to look at |
|---|---|
| `group_video_call_demo_minor_member` | A minor member of "Group Video Call Demo", which has a live session. |
| `group_video_call_demo_guardian` | Their guardian. |
| `group_video_call_demo_minor_creator` | A minor creator of a separate group: works the same way. |

All from the normal seed (password `password`).

## Try it
1. Log in as `group_video_call_demo_minor_member` and open the group's chat page, `/group-therapies/<id>/chat` (from
   the group page, "chat"). You should see the consent note and **ask a parent or guardian**. Press it: "Your parent
   or guardian has been asked."
2. Log in as `group_video_call_demo_guardian`. The bell shows "Video consent requested". Go to `/therapies`, Ward
   Section → Group Therapies, and press **video consent** under "Group Video Call Demo".
3. The panel lists "GroupVideoCall DemoMinorMember", with "Whole group: not given". Press **approve for the group**:
   it reads "approved by … on …", and the current session says "covered by the group consent".
4. Log back in as the minor and open the chat page again: **join video** is offered.
5. As the guardian, press **withdraw**, then **confirm withdraw**: the minor is back to needing consent, and if they
   were in the call they're taken out of it.

## Deployment notes
- One migration adds `MEMBERSHIP_ENDED` to `video_consents.revocation_reason`.
- To roll back, revert the code. Old code brings back the blanket block, which fails closed. Keep the column: the
  migration's `down()` fails once a row has the new reason.
