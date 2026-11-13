# Password rules (SCRUM-35)

SCRUM-35 is two PRs. This first one (TT-4.6a) sets the rules for every new password; the second flags weak existing
passwords at login.

## What was built
Every new password (sign-up, "forgot password" reset, changing it on the profile) must:
- be at least 10 characters, with letters and numbers ("a few words together work well");
- not contain your username, your email, or the part of your email before the @;
- not be one known from data breaches. Only the first 5 characters of a scrambled version of it go to the Have I
  Been Pwned service; if it doesn't answer within 3 seconds, the password is accepted.

The rules are listed under the password field on all three forms before you type. Existing passwords still work, and
nobody is made to change theirs.

## Test data
Any seeded account (password `password`): change the password from the profile. The seeded passwords still sign in.

## Try it
1. Log in, open the profile, press **Update** under "Update Password". The three rules are listed under "New
   Password".
2. Try `<your username>2026x`: "Your password must not contain your username or email."
3. Try `short9chr` (too short) or `onlyletterslong` (no number): refused with the reason.
4. Try `password123456` (a well-known breached password): refused as breached (needs the internet; offline, it's
   allowed).
5. Try `quiet harbour 2026`: saved.
6. Sign up with a username and a password containing it: refused.

## Deployment notes
- No migrations. New optional settings (defaults shown): `PASSWORD_MIN_LENGTH=10`, `PASSWORD_CHECK_BREACHES=true`,
  `PASSWORD_BREACH_CHECK_TIMEOUT=3` -- `PASSWORD_CHECK_BREACHES=false` turns the outbound check off.
- The app now calls `https://api.pwnedpasswords.com` on those three forms; if egress is restricted, allow it (or the
  check is just skipped after 3s).
- The PHP image sets `zend.exception_ignore_args = On`; rebuild `php`, `queue` and `reverb`. On a server not using
  this image, make sure php.ini has it On.
- A failed check logs "The breached-password check is unavailable" -- frequent ones mean the service isn't reachable.
