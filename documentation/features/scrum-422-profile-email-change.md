# Profile information and email-change protection (SCRUM-422 + TT-12.2a)

Two things in one PR, because they share the profile form:

- **The profile's look (the user's TODO):** Profile Information reads as a plain list (First name, Last name,
  Other names, Email with a Verified / Not verified badge, Date of birth, Gender, Country; "—" when not set). **Edit**
  at the bottom left turns the card into a form with **Save** and **Cancel**. Every profile card's buttons now sit at
  the bottom left, flush with the card's text (`resources/js/Components/CardActions.vue`). Cancel now throws away
  unsaved edits, and the date of birth no longer shows a day early in timezones behind UTC.
- **SCRUM-427:** a new address only becomes the account's once its owner confirms it (a link to it, 24 hours; the
  link's page has a **Confirm this address** button) -- and when the current address is verified, **48 hours after
  that** (`EMAIL_CHANGE_WAIT_HOURS`). Until then the current address stays in use, for signing in and password resets
  too. The current address is warned at each step, with **This wasn't me**: it stops the change and signs the account
  out everywhere. The profile shows the waiting address with **Send the link again** and **Keep my current email**. The
  same applies when a super administrator changes someone's email (SCRUM-425); then the person's other sessions end
  when it takes effect.
- **SCRUM-422:** changing the account's email (replacing it, adding a first one or clearing it) asks for the current
  password. A wrong or missing password refuses the whole save. The form is limited to 10 tries a minute. When the
  previous address was verified, it gets an email saying the address was changed, showing the new one only masked
  (`p•••@e•••.org`) and telling them to contact support if it wasn't them. It never suggests resetting the password,
  because that link would go to the new address.

## Try it out

### Test data

| Account | Password | Use |
|---|---|---|
| `payment_demo_client` (or any seeded demo user, `documentation/seeded-data.md`) | `password` | Verified email `payment.demo.client@example.com` |

### Steps

1. Sign in as `payment_demo_client` → **Profile** (`/profile`). Profile Information shows the list; the email has a
   green **Verified** badge. Every card's button is at the bottom left.
2. **Edit** → change only the country → **Save**: no password asked, saved, back to the list.
3. **Edit** → change the email to `payment.demo.new@example.org`: a **Your current password** box appears. Enter a
   wrong one → **Save** → "That password is not correct." (what you typed stays). Enter `password` → **Save** → the
   email is still the old one (Verified), and a blue box says a link went to the new address.
4. Mailpit (http://localhost:8027): "A change of your account email was requested" to
   `payment.demo.client@example.com`, naming `p•••@e•••.org` -- never the full new address; and "Confirm your new email
   address" to `payment.demo.new@example.org`. **Forgot password** with the new address finds nothing yet; with the old
   one it works.
5. Open the confirm link → **Confirm this address** → "Confirmed. The new address becomes your account's email in a
   couple of days"; Mailpit has a second email to the old address saying when, with **This wasn't me**. The profile
   says when it takes effect. To see it apply without waiting:
   `docker compose exec -u www-data php php artisan tinker --execute='App\Models\User::where("username","payment_demo_client")->update(["pending_email_confirmed_at" => now()->subDays(3)]);'`
   and wait a minute for the scheduler -- the email is the new one, Verified, and the old address is told.
6. **This wasn't me** — repeat step 3, then open the warning's **This wasn't me** link → **Stop the change** →
   signed out everywhere; the change is gone. If two-factor was turned on while the change waited -- or in the 14
   days before it was asked for (`EMAIL_STOP_TWO_FACTOR_LOOKBACK_DAYS`, SCRUM-431) -- a two-factor reset
   is scheduled (48 hours, SCRUM-428): both the account's address and the dropped new one get "Two-factor sign-in
   reset scheduled" with **Cancel this reset** -- whichever holds the real owner's phone cancels it. (To undo a change that took effect, change it back the same way.)
7. **Edit** → change a field → **Cancel** → **Edit** again: the saved value is back.

## Where it lives

- `resources/js/Pages/Profile/Partials/UpdateProfileInformationForm.vue`, `resources/js/Components/CardActions.vue`
  (used by the password, delete-account, become-counsellor, guardianship and testimonial cards).
- `app/Http/Requests/ProfileUpdateRequest.php` (`changesEmail()`, the `current_password` rule),
  `app/Http/Controllers/ProfileController.php::update`, `app/Notifications/EmailChangedNotification.php`,
  `app/Support/MaskedEmail.php`; `routes/web.php` (`profile.update` throttle).
- Tests: `tests/Feature/ProfileEmailChangeTest.php`. Decisions: `documentation/decision-log.md` (SCRUM-422).
