# Google sign-in (SCRUM-112)

Built in five parts: SCRUM-451 (shared account-creation and sign-in steps), SCRUM-452 (schema and switch), SCRUM-453
(sign-in for connected accounts), SCRUM-454 (new users: "Finish setting up") and SCRUM-455 (connect/disconnect from the
profile, "Set a password", Your data, /privacy). Decisions are in `documentation/decision-log.md` under those tickets.

## What it does

- **Only Google, only `openid email profile`**, with Google's account chooser (`prompt=select_account`), state and
  PKCE. No Google tokens are stored. An account is found **only by Google's account id (`sub`)**, stored in
  `users.google_id` with `google_linked_at` -- never by email. Google's email must be verified (`email_verified` exactly
  `true`).
- **Sign in** ("Continue with Google" on Login): a connected account signs in through the same path as a password
  sign-in, so two-factor still asks for its code and a suspension still applies. A Google sign-in is never remembered
  ("Remember me" is not offered for it). An existing account is **never** found by its email: a Google account that
  isn't connected to one goes to sign-up (below), whatever its email.
- **Sign up** (SCRUM-454; "Continue with Google" on Login or Register, with a Google account connected to nothing):
  the pending sign-up lives in the session only -- **10 minutes**, and cleared when Login or Register is opened -- then
  **"Finish setting up your account"** asks for a **username** and an **optional date of birth** (with a why-we-ask
  note; without one the person counts as under 18, as for normal sign-up). Google's name and photo are not used.
  Google's email becomes the account's (verified) email **only if** it is a `gmail.com` / `googlemail.com` address or a
  Google Workspace account (Google's `hd` claim) **and** no account here uses it; otherwise the account has no email.
  A note on that step says: if you already have a TalkTherapy account, sign in to it and connect Google from your
  profile instead. The new account has no password.
- **Connect from the profile** (SCRUM-455): Profile -> "Google sign-in" card -> "Connect Google" -> password ->
  Google's account chooser -> back on the profile, "connected since <date>". It comes back to its own callback
  (`/profile/google/callback`), which needs a connect asked for from that session within 10 minutes. Refused, with our
  own words: an unverified Google email, a Google account already connected to another account (closed ones included),
  or this account already connected to a different Google account.
- **Disconnect**: the same card -> "Disconnect" -> password. Refused for an account without a password (Google would be
  its only way in): the button is disabled and points to "Set a password". Not behind the switch: a connected account
  sees the card and can disconnect even while Google sign-in is off (it can't connect then).
- **Connecting or disconnecting** ends the account's other sessions (`session_version`), stops "remember me" elsewhere
  (new `remember_token`) and sends a security notice (in-app, and by email to a verified address). This browser stays
  signed in.
- **Set a password** (accounts made with Google): Profile -> "Set a password" card (where "Update Password" usually is)
  -> "Email me a link" sends the usual password-reset link to the account's **verified** email (without one: "add an
  email address and confirm it first"). The reset page works while signed in; setting the password signs this browser
  out, and the person signs in again. Until then the forms that need a password (changing the email, closing the
  account, two-factor, downloading your data, PDF exports) point to "Set a password" instead of asking for it.
- **Account recovery lets go of Google**: "This wasn't me" on an email change, the team's two-factor reset, and an
  email change a super administrator made also disconnect Google (it may be an intruder's way back in). The stop page,
  the reset notice and the email-changed email say so. The two-factor reset and the team's email change keep Google
  only when it is the account's only way in (no password and no verified email), and the reset logs that. Closing an
  account disconnects Google at once (SCRUM-452).
- **A password reset** of an account that already had a password leaves Google connected, and the success message
  says so ("disconnect it from your profile if you didn't connect it"). Signed in, a reset link for **another** account
  is refused ("This link is for a different account. Sign out first.").
- **Your data**: "What we hold about you" and the downloaded copy say "Google sign-in: connected since <date>" or "not
  connected" -- never the Google id or email. **/privacy** has a "Signing in with Google" section.

## Routes

| Route | Name | Who |
|---|---|---|
| `GET auth/google/redirect`, `GET auth/google/callback` | `auth.google.redirect`, `auth.google.callback` | guests (sign in / sign up) |
| `POST profile/google/connect` (`current_password`) | `profile.google.connect` | signed in |
| `GET profile/google/callback` | `profile.google.callback` | signed in, with a connect intent |
| `DELETE profile/google` (`current_password`) | `profile.google.disconnect` | signed in, with a password (any switch state) |
| `POST profile/password/link` | `profile.password.link` | signed in, no password, verified email |

The sign-in and connect routes are a plain 404 while `GOOGLE_SIGN_IN_ENABLED` is off or the client isn't configured;
the two connect routes also while `GOOGLE_CONNECT_REDIRECT_URI` is empty (the card then offers no "Connect").
Disconnecting and "Set a password" don't depend on the switch. The profile shows the Google card while Google sign-in
is on, or whenever the account is connected.

## How to try it locally

1. **A Google Cloud OAuth client**: Google Cloud console -> APIs & Services -> OAuth consent screen (External, "Testing",
   add your Google account as a test user; scopes openid, email, profile) -> Credentials -> Create credentials -> OAuth
   client ID -> "Web application". Add **two** authorised redirect URIs, exactly:
   - `http://localhost:8000/auth/google/callback` (sign in / sign up)
   - `http://localhost:8000/profile/google/callback` (connect from the profile)
2. **Env** (`.env.docker` for Docker; `.env` only when running outside it):
   ```
   GOOGLE_SIGN_IN_ENABLED=true
   GOOGLE_CLIENT_ID=<client id>
   GOOGLE_CLIENT_SECRET=<client secret>
   GOOGLE_REDIRECT_URI=http://localhost:8000/auth/google/callback
   GOOGLE_CONNECT_REDIRECT_URI=http://localhost:8000/profile/google/callback
   ```
3. **Recreate the containers** that read it: `docker compose up -d --force-recreate php queue` (the env file is read at
   container start; config is cached otherwise -- `docker compose exec -u www-data php php artisan config:clear`).
4. **Seed** (if not already): `docker compose exec -T -u www-data php php artisan db:seed --force`.

Emails (reset links, security notices) land in Mailpit: http://localhost:8027.

### Things to try

- **Connect**: sign in as `john_davis` / `password` -> Profile -> "Google sign-in" -> "Connect Google" -> type
  `password` -> "Continue to Google" -> pick your test Google account. Back on the profile: "Google is connected" and
  "connected since <today>"; a "Google sign-in connected" notice in the bell and in Mailpit. Sign out and use "Continue
  with Google" on Login: you're in as `john_davis`.
- **Refusals**: connect the same Google account to a second seeded account (e.g. `amy_taylor`): "That Google account
  can't be connected...". Open `/profile/google/callback?code=x&state=y` directly: "We couldn't connect Google".
- **Disconnect**: as `john_davis` -> "Disconnect" -> `password`: "not connected". "Continue with Google" with that
  Google account no longer reaches `john_davis`: it starts a new sign-up ("Finish setting up your account") instead.
- **Sign up** (SCRUM-454): "Register" (or Login) -> "Continue with Google" with an unconnected Google account ->
  "Finish setting up your account" -> username (and optionally a date of birth) -> you land on /preferences, signed
  in, with no password. With a `gmail.com` test account whose address no account uses, the profile shows it as your
  confirmed email; otherwise the account has no email.
- **Set a password**: sign in as `google_only_demo` (with Google -- see "Test data") or any account made through the
  sign-up above -> Profile: the "Set a password" card; "Delete Account", two-factor's "Turn on", changing the email and
  "Download a copy" (Your data) point to it. "Email me a link" -> open the link from Mailpit -> choose a password -> you
  are signed out; sign in with the new password. The Google card's "Disconnect" is now enabled.
- **Your data**: Profile -> "What we hold about you" -> "Signing in and security" lists "Google sign-in: connected
  since <date>" (or "not connected"); the downloaded copy's `summary.security` has `googleConnected` /
  `googleConnectedSince`, and no Google id anywhere.
- **/privacy**: the "Signing in with Google" section.

### Follow-ups (SCRUM-456)

- **Admin-closed accounts:** a super administrator deletes an account connected to Google (Administrator -> Users ->
  delete). That Google account then gets "There's no TalkTherapy account connected to this Google account..." on
  "Continue with Google" (no new account), and "That Google account can't be connected..." when connected from another
  profile. A keyed hash of its id is kept in `blocked_google_identities`; the id itself isn't. Closing your own account
  (Profile -> Delete Account) blocks nothing.
- **Usernames:** new usernames are ASCII only (letters, digits, `-`, `_`) on Register and "Finish setting up".
- **Two-factor reset of `google_only_no_email_demo`:** as a super administrator, Administrator -> Users -> its
  "reset two-factor": the modal says "This account signs in only with Google and has no verified email; after the
  reset, the Google account alone gets in. Reset it on the server, which asks you to confirm that." and the button is
  disabled. On the server,
  `docker compose exec php php artisan user:two-factor-reset google_only_no_email_demo` prints the same sentence and asks
  "Reset it anyway, leaving the Google account as the way in?" before the usual question. Run it at a terminal: an
  account like this has no verified email, so `--force` is refused anyway. `--google-only-ok` answers the Google
  question in advance, but only matters for the rare row with a verified-at date and no email.
- **A reset that becomes Google-only while it waits** (say, the email's verification is removed) is cancelled when due
  unless the server command was told it may leave Google as the only way in; the log has a warning.

## Test data

See `documentation/seeded-data.md` ("Accounts connected to Google"):

- `google_only_demo` -- no password, connected to Google id `demo-google-sub-1`: "Set a password", the pointers on the
  password-gated forms, the disabled "Disconnect".
- `google_2fa_demo` / `password` -- connected to `demo-google-sub-2`, two-factor on (code from the documented demo key):
  disconnect and connect again; a Google sign-in still asks for the code.
- `john_davis` / `password` (or any seeded account with a password) -- connect a Google account.
- `google_only_no_email_demo` -- no password, no email, two-factor on (SCRUM-456): the Google-only two-factor reset.
  Sign in as the super admin `mr_robertamoah` (password: `SUPER_PASSWORD` in `.env.docker`) to see the admin side.

The seeded Google ids are made up: a real Google sign-in reaches `google_only_demo` / `google_2fa_demo` only if your
test Google account's `sub` is that id (set it with `tinker` on the row if you need to). Connecting a real Google
account to `john_davis` needs nothing seeded. The automated tests mock Google (`tests/Feature/GoogleSignInTest.php`,
`tests/Feature/GoogleConnectTest.php`).

## A successful result looks like

- The profile's Google card shows "connected since <date>" after connecting, "not connected" after disconnecting, and
  never which Google account.
- Other browsers signed in to the account are signed out after either; this one isn't.
- A Google-only account can set a password from the emailed link and then disconnect Google.
- Nothing anywhere (page props, Your data, the copy) shows the Google id.
