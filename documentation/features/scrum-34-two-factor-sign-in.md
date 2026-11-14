# Two-factor sign-in (SCRUM-34)

An optional second step when signing in: a 6-digit code from an authenticator app (Google Authenticator, Microsoft
Authenticator, 1Password …), or one of 8 single-use recovery codes. Built in three PRs: setup on the profile (34a,
#334), the code at sign-in (34b, #335), and a super-administrator reset plus this doc (34c). 34d makes it required
for platform administrators from a start date.

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
those are reset on the server (SCRUM-34d).

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

## Where it lives

- Setup and management: `app/Actions/TwoFactor/*`, `app/Http/Controllers/Auth/TwoFactorController.php`,
  `resources/js/Pages/Profile/Partials/TwoFactorSection.vue`.
- Sign-in: `LoginRequest::authenticate()` (password only), `AuthenticatedSessionController::store`,
  `app/Support/TwoFactorLogin.php`, `TwoFactorChallengeController`, `CompleteLoginAction`,
  `resources/js/Pages/Auth/TwoFactorChallenge.vue`.
- Shared: `app/Support/TwoFactorCodes.php` (secrets, QR, recovery-code hashes), `TwoFactorAttempts` (the wrong-code
  count), `EndStaleSessions` (other sessions end), `TwoFactorChangedNotification`.
- Admin reset: `ResetTwoFactorByAdminAction`, `AdminTwoFactorController`, `AdminUserComponent.vue`.
- Decisions: `documentation/decision-log.md` (SCRUM-34a, 34b, 34c). Operations: the SCRUM-34 lines in
  `documentation/operations/production-checklist.md` (keep old `APP_KEY`s; roll back with the switch, not a revert).
