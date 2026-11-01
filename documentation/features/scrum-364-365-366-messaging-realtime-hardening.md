# Messaging and realtime hardening, privacy policy (SCRUM-364, 365, 366, 271, 209, 205, 199, 192)

One PR, as the user asked ("group the next set of work and include the new follow-up tickets").
User decisions (2026-11-01): live updates carry no message content; the privacy policy names
**TalkTherapy GH**, **robertamoah.dev@gmail.com** (to become info@talktherapy.org) and **Ghana**.

## What was built

### Live chat updates carry no content (SCRUM-366 #1, SCRUM-271)
- A new or edited message is only **announced** over the websocket: its id and the (already
  masked) sender id. Every open page then fetches the message from `api.messages.show`, which
  applies all the usual rules: confidential redaction, anonymity, the strict payment gate, and
  suspension. A refused fetch shows nothing.
- So a suspended person whose page ignores the redirect, or a participant who hasn't paid a
  strict-gated therapy, learns at most that "a message arrived" — never what it says.
- Messages deleted for everyone are still sent as they are: their "deleted" shape has no content.
- `api.messages.show` now allows 240 requests a minute on its own counter, since every live message
  is fetched there. A temporary failure (rate limit, server error, network) is retried twice; only
  a refusal hides the message.
- The typing indicator no longer sends the user's id (it leaked an anonymous client's id); it sends
  a random per-page token.

### SCRUM-365
- The broken "group by topic/session" options on the message lists were removed. Nothing used them,
  and they could not work.
- The unused `BroadcastServiceProvider` was deleted.
- An administrator who could only see a message as an administrator can no longer subscribe to that
  message's live channel. Those reads are recorded in the read log; a subscription would not be.

### SCRUM-366
- Every remaining search (languages, religions, licensing authorities, concerns, professions,
  contacts, posts, testimonials, topics, sessions, how-tos) matches `%` and `_` literally.
- Emails keep every line of user text on one line and escape a Markdown marker at its start, so a
  name or reason can't add a heading, quote, list or code block. Links were already blocked.
- The read-log page can be narrowed to one subject: click a subject such as "Session #12" to see
  everything read about it. In the admin user list, an administrator has an **access history**
  button that opens their own reads.

### SCRUM-364: full privacy policy draft
`/privacy` now covers who runs TalkTherapy (TalkTherapy GH, Ghana; Data Protection Act, 2012 (Act
843)), what is collected, why, who sees it (including Paystack, Daily or Amazon Chime, the email
provider and hosting), retention, cookies (only essential ones), rights and how to complain to
Ghana's Data Protection Commission, children, and contact. It is still marked **not reviewed by a
lawyer**. The legal review itself can't be done in code and stays open on SCRUM-364.

### Smaller fixes
- **SCRUM-209:** topic messages can be narrowed to one session (the filter used a column that
  doesn't exist).
- **SCRUM-205:** message lists load files, replied-to messages and recipients in batches instead
  of one query per message.
- **SCRUM-199:** session status, topic and note changes are rate limited (30 a minute, own counter).
- **SCRUM-192:** organisation logo and avatar uploads are rate limited (10 a minute), and the
  counsellor profile update, shared by four forms, to 30 a minute, each on its own counter.
- Older leaks found in review (discussion and session broadcasts, the shared rate-limit counter,
  purging closed accounts) are SCRUM-367.

## How to try it
1. **Live chat:** open the same therapy chat in two browsers (for example `maria_garcia` and her
   counsellor). Send a message from one: it appears in the other as before. In the browser's
   network tab, the websocket frame carries only `{id, fromUserId, withheld}`, followed by a
   `GET /api/messages/{id}`.
2. **Suspension:** suspend one of them (whole account) as `mr_robertamoah`; their page goes to
   `/suspended`. If you stop that redirect, new messages still arrive as announcements, but the
   fetch returns 403 and nothing is shown.
3. **Read log:** as `mr_robertamoah`, open `/administrator/content-accesses` and click a subject to
   narrow the list. Then open `/administrator`, go to users, and on an administrator choose **show
   actions → access history**.
4. **Privacy:** open `/privacy` from the footer.
5. **Emails:** a reason containing a new line followed by `# Heading` arrives as one plain line.

## Deployment notes
No migrations and no new env vars. Rebuild the frontend: live messages now depend on the new
`resolveBroadcastMessage.js`. Run `php artisan queue:restart` so queued mail uses the new template.
`/privacy` still needs legal review before `main` (SCRUM-364 stays open for that).
