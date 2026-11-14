# Two-factor reset: checking it's really them (SCRUM-424)

Someone asks support to turn off their two-factor sign-in because they lost their phone **and** their recovery codes.
The person asking very often already knows the password -- that's exactly who two-factor keeps out. So the reset is
only ever done once you're sure the request comes from the account's owner, and even then it **waits 48 hours** and
the owner can cancel it from their verified email.

## Before you reset

1. **The account must have a verified email.** The admin list refuses otherwise (the owner couldn't cancel). For an
   account without one, see "No verified email" below.
2. **Contact them through the verified address -- never only reply to the request.** Write to the address on the
   account (Administrator → Users shows it), from the support mailbox, and ask them to confirm the request from it.
   A request that came from another address, a phone call or a chat is never enough on its own.
3. **Check details the caller gives against the account, not the other way round.** Don't read details out for
   them to confirm.
4. **What is never enough on its own:** the username, a name, a date of birth, anything visible on a public or
   counsellor profile, or "I know the password".
5. **Never ask about therapy content, health details or sessions**, and don't ask for new identity documents.
6. **Warning signs -- stop and escalate to another super administrator:** urgency or pressure to skip the wait; the
   email was changed recently (the old address got a "your account email was changed" notice -- SCRUM-422); they ask
   to change the email at the same time; the request names someone else's account.

## Doing it

- **Admin list:** Administrator → Users → the person → *show actions* → **reset two-factor** → your password (and your
  code) → put the support ticket number in **Ticket number or short note** → **schedule reset**. It applies after 48
  hours unless cancelled; they're emailed a cancel link now.
- **Server** (administrators' accounts, accounts without a verified email, or when no super administrator can sign
  in): `sudo -u www-data php artisan user:two-factor-reset <username|email> --reason=<ticket>` (schedules, asks first).

Tell them: it takes effect in 48 hours; signing in with a code meanwhile cancels it; after it applies they sign in
with the password and should set two-factor up again straight away.

## No verified email

Only on the server, after the checks above (with whatever channel the account does have), and only by a super
administrator with shell access. It still waits 48 hours -- but nobody can cancel it by email, so be stricter.

## `--now` (break-glass)

`user:two-factor-reset <user> --now --reason=<ticket>` applies at once. Only for a genuine emergency -- typically the
last super administrator locked out, when 48 hours without anyone able to do super-admin work would hurt people. It
only works typed at a terminal (never in a script or with `--force`), needs a reason, and is logged as immediate.
A second person should agree first, and the reason should say who.

## "It wasn't me"

- **Before it applies:** cancel it (admin list → **cancel reset**, or `user:two-factor-reset <user> --cancel`), and
  tell them to change their password.
- **After it applies:** help them secure the account: change the password (it signs out every other session), set
  two-factor up again, and check the account's email is theirs (SCRUM-425 covers admin-side email changes). Review the
  log lines (`monitoring.md`) to see who asked and when.
