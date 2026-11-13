# Email verification you can't miss (SCRUM-41)

## What was built
Sign-up doesn't ask for an email, so most accounts have none, and an unverified one gets no emails at all: no session
reminders, no password resets. You also can't start a therapy until your email is verified. The only prompt used to be
halfway down the profile. Now:
- **A slim notice on every page** for a signed-in user whose email isn't verified:
  - no email yet: "Add an email to your account so you don't miss session reminders…" with an **add an email**
    button (to the profile);
  - an email not yet verified: "Please verify <email>…" with **send the link**, which says "We've sent you a new
    link", or "Please wait a minute…" if asked too often.
  - **not now** hides it until the browser session ends.
  - It sits in the page, so it never covers the help button, and it isn't shown on the profile, the verify page,
    help chat or therapy, group or discussion chats.
- **The profile's "Email verification" section** is now at the top, in plain words, with a button.
- **After opening the link in the email**, you land on home with "Your email is verified".
- **Clearer copy** in the verification email (why it matters, how long the link lasts) and on the verify page.

Nothing new is blocked for unverified accounts.

## Test data
| Account | What to look at |
|---|---|
| `unverified_email_demo` / `password` | Has `unverified.email.demo@example.com`, not verified. |
| `no_email_demo` / `password` | No email at all. |

From the normal seed. A database seeded before this change can add them in tinker (an adult user with that username,
`email_verified_at` null, and the email or none).

## Try it
1. Log in as `no_email_demo`: every page shows the "Add an email" notice; **add an email** opens the profile, where
   "Email verification" is the first section. Press **not now** on another page: it stays hidden as you move around.
2. Log in as `unverified_email_demo`: the notice reads "Please verify unverified.email.demo@example.com…". Press
   **send the link**: "We've sent you a new link". Open Mailpit (http://localhost:8027): "Verify your email for
   TalkTherapy".
3. Open the link from that email while logged in: you land on home with "Your email is verified", and the notice is
   gone.
4. Press **send the link** seven times within a minute: the seventh says to wait a minute, no error page.

## Deployment notes
No migrations or configuration. The verification email's wording changed.
