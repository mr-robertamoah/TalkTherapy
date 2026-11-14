# Two-factor sign-in (SCRUM-34)

An optional second step when signing in: a 6-digit code from an authenticator app (Google Authenticator, Microsoft
Authenticator, 1Password …), or one of 8 single-use recovery codes. Built in three PRs: setup on the profile (34a,
#334), the code at sign-in (34b, #335), a super-administrator reset plus this doc (34c, #336), and 34d: required for
platform administrators from a start date, and a server command to reset anyone's.

## What a person can do

- **Turn it on** — Profile → *Two-Factor Sign-In* → **Turn on** → their password → scan the QR code (or type the key)
  → the 6-digit code. They get 8 recovery codes, shown once (Copy / *I've saved them*). Until the code is confirmed
  nothing changes; an unfinished setup lasts 15 minutes and lives only in that browser.
- **Sign in** — the password, then the code page (`/two-factor-challenge`): a code from the app, or *Lost your phone?
  Use a recovery code*. 5 minutes to enter it; until then they aren't signed in.
- **New recovery codes / turn off** — each asks for the password **and** a code (app or recovery).
- **Notices** — in the notification centre (and by email when the address is verified) whenever it's turned on or
  off, new codes are made, a recovery code is used to sign in, or the team resets it.
- **Other devices** — turning it on or off, or making new codes, signs them out everywhere else (and "remember me"
  elsewhere stops). The browser they did it in stays signed in. A password change does the same.

Limits: 5 wrong codes per account (counted across every step), then codes are refused for 15 minutes (a right code
clears the count; a super administrator's reset does too). "Remember me"
still skips the code for the life of its cookie — cookies from before two-factor was turned on stop working.

## What a super administrator can do

For someone who lost both their phone and their recovery codes, **once the team has confirmed it's really them**
(outside the app): Administrator → Users → find them → *show actions* → **reset two-factor** → their own password
(and their own code, if they have two-factor on) → **reset**. Two-factor goes off for that account; they're told,
signed out everywhere, and can sign in with the password and set it up again. The reset is written to the
application log (`Two-factor sign-in reset by an administrator`, with both account ids and the address;
`documentation/operations/monitoring.md`). The button only shows to super administrators, and only for accounts
with two-factor on. Never their own (they turn it off from their profile), and never another administrator's:
those are reset on the server (below).

## Required for platform administrators (34d)

`TWO_FACTOR_ADMINS_REQUIRED_FROM` (`YYYY-MM-DD` only, from 00:00 in `APP_TIMEZONE`; unset by default = never;
anything else, `false` included, is off) makes it
required for every account with an administrator role (normal or super):

- **Before that day** — an administrator without it sees a blue reminder under the navigation on every page: "From
  <date>, administrators need two-factor sign-in…" with **set it up** (to the profile section) and **not now** (hidden
  for the rest of that browser session).
- **From that day** — they can reach only their own account until it's set up: any page they open sends them to
  their profile, where the reminder says it's required now (no "not now"), and every other request is refused (JSON:
  403 "Two-factor sign-in is required for administrators…") -- admin pages and actions, and the admin powers on shared
  routes too (reading therapy content and documents, verifying counsellors, answering requests, admin channels).
  **Never a lockout**: they stay signed in, and their profile (and what its page loads), setting it up, the email and
  password pages, their notices and signing out all work. Once it's on, everything opens again.
- Turning it off later is allowed (password + code), and puts them straight back on the profile to set it up again —
  how they move to a new phone.
- Nothing applies while setup is switched off (`TWO_FACTOR_ENABLED=false`), or if the date can't be read (that logs a
  warning, `TWO_FACTOR_ADMINS_REQUIRED_FROM is not a date…`).

## Reset on the server

For an administrator's account (the users list refuses those), or anyone's when no super administrator can sign in —
again only once the team is sure it's really the person:

```bash
docker compose exec -u www-data php php artisan user:two-factor-reset <username|email>
docker compose exec -u www-data php php artisan user:two-factor-reset <id> --id
```

It shows who it found (and whether they're an administrator) and asks before changing anything (`--force` skips the
question). A number is read as an id only with `--id` (a username may be all digits). The effect is the same as the admin reset: off, told, signed out everywhere, wrong-code count cleared;
logged as `Two-factor sign-in reset on the server` with the account id, who ran it (the sudo user, else the
process owner), the host and whether `--force` was used. An administrator reset this way is then sent
to set it up again on their next page, if the required date has passed.

## Switch

`TWO_FACTOR_ENABLED` (on by default) shows **setting it up**. Off hides setup only: accounts that already have it are
still asked for the code, and can still make new codes or turn it off. After changing it: `php artisan config:clear`.

## Try it out

### Test data

| Account | Password | State |
|---|---|---|
| `two_factor_demo` | `password` | Two-factor on, with the fixed demo key `TALKTHERAPYDEMOTWOFACTORSEEDKEYA` and recovery codes `demo1-aaaaa` … `demo8-hhhhh` (`DatabaseSeeder::TWO_FACTOR_DEMO_*`). Each recovery code works once — reseed (or use another) after. |
| `mr_robertamoah` | (see `seeded-data.md`) | The super administrator, for the reset. |
| any other seeded account, e.g. `unverified_email_demo` | `password` | Two-factor off — to try setting it up. |

To get a code for the demo key without a phone, add it to any authenticator app by typing the key, or:

```bash
docker compose exec php php -r 'require "vendor/autoload.php"; echo (new PragmaRX\Google2FA\Google2FA)->getCurrentOtp("TALKTHERAPYDEMOTWOFACTORSEEDKEYA"), PHP_EOL;'
```

A code works once — for a second sign-in within the same 30 seconds, wait for the next one.

### Steps

1. **Sign in with a code** — http://localhost:8000/login as `two_factor_demo` / `password`. You land on *Two-factor
   sign-in*; visiting `/profile` now still sends you to sign in. Enter the current code → signed in.
2. **A wrong code** — sign out, sign in again, enter `000000` → "That code didn't work…". After five wrong ones, the
   sixth try (even a right code) → "Too many wrong codes. Please try again in 15 minutes."
3. **A recovery code** — on the code page, *Lost your phone? Use a recovery code* → `demo1-aaaaa` → signed in; the
   bell shows "Signed in with a recovery code". The same code again is refused.
4. **Set it up** — as `unverified_email_demo`, Profile → *Two-Factor Sign-In* → **Turn on** → `password` → add the
   shown key to an authenticator app (or use the command above with that key) → the code → 8 recovery codes; status
   "on". Sign out and in: the code is asked for.
5. **Other devices** — sign in as the same account in a second browser (or a private window), then turn two-factor
   off (password + a code) in the first. The second browser's next page goes to the sign-in page; the first stays
   signed in.
6. **Admin reset** — as `mr_robertamoah`, Administrator → Users → search `two_factor_demo` → *show actions* →
   **reset two-factor** → your password (and your code, if you have two-factor on) → **reset**. The button disappears; `two_factor_demo` now signs in with the
   password alone and has a "Two-factor sign-in reset" notice.

7. **Required for administrators** — set `TWO_FACTOR_ADMINS_REQUIRED_FROM` in `.env.docker` to a date a week ahead,
   `docker compose up -d --force-recreate php` (then `docker compose restart web` if nginx answers 502). Sign in as
   `mr_robertamoah` (no two-factor yet): the blue reminder shows with that date; **not now** hides it until the browser
   session ends. Set the date to today and recreate again: any page (Home, Administrator) lands on the profile with
   "Two-factor sign-in is now required for administrators"; **set it up** jumps to the section. Turn it on → the
   site opens again. Remove the line (and recreate) when done.
8. **Reset on the server** — `docker compose exec -u www-data php php artisan user:two-factor-reset mr_robertamoah`
   → it shows who it found → confirm → off. That browser is signed out (as with any reset); signing in again with the
   password alone lands on the profile while the date is set. By id: `… user:two-factor-reset 1 --id`.

## Where it lives

- Setup and management: `app/Actions/TwoFactor/*`, `app/Http/Controllers/Auth/TwoFactorController.php`,
  `resources/js/Pages/Profile/Partials/TwoFactorSection.vue`.
- Sign-in: `LoginRequest::authenticate()` (password only), `AuthenticatedSessionController::store`,
  `app/Support/TwoFactorLogin.php`, `TwoFactorChallengeController`, `CompleteLoginAction`,
  `resources/js/Pages/Auth/TwoFactorChallenge.vue`.
- Shared: `app/Support/TwoFactorCodes.php` (secrets, QR, recovery-code hashes), `TwoFactorAttempts` (the wrong-code
  count), `EndStaleSessions` (other sessions end), `TwoFactorChangedNotification`.
- Admin reset: `ResetTwoFactorByAdminAction` (who may) and `ResetTwoFactorAction` (the reset itself),
  `AdminTwoFactorController`, `AdminUserComponent.vue`; on the server, `app/Console/Commands/ResetUserTwoFactor.php`.
- Required for administrators: `app/Support/TwoFactorRequirement.php`, `RequireTwoFactorForAdmins` (web and api
  groups), the `twoFactorRequirement` shared prop, `resources/js/Components/TwoFactorRequirementNotice.vue`.
- Decisions: `documentation/decision-log.md` (SCRUM-34a, 34b, 34c, 34d). Operations: the SCRUM-34 lines in
  `documentation/operations/production-checklist.md` (keep old `APP_KEY`s; roll back with the switch, not a revert).
