# Profile information and email-change protection (SCRUM-422 + TT-12.2a)

Two things in one PR, because they share the profile form:

- **The profile's look (the user's TODO):** Profile Information reads as a plain list (First name, Last name,
  Other names, Email with a Verified / Not verified badge, Date of birth, Gender, Country; "—" when not set). **Edit**
  at the bottom left turns the card into a form with **Save** and **Cancel**. Every profile card's buttons now sit at
  the bottom left, flush with the card's text (`resources/js/Components/CardActions.vue`). Cancel now throws away
  unsaved edits, and the date of birth no longer shows a day early in timezones behind UTC.
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
   email shows **Not verified**.
4. Mailpit (http://localhost:8027): an email to `payment.demo.client@example.com`, "The email on your TalkTherapy
   account was changed", naming `p•••@e•••.org` -- never the full new address.
5. Change it back the same way (the new address wasn't verified, so nobody is emailed this time), then verify it from
   the banner, or reseed.
6. **Edit** → change a field → **Cancel** → **Edit** again: the saved value is back.

## Where it lives

- `resources/js/Pages/Profile/Partials/UpdateProfileInformationForm.vue`, `resources/js/Components/CardActions.vue`
  (used by the password, delete-account, become-counsellor, guardianship and testimonial cards).
- `app/Http/Requests/ProfileUpdateRequest.php` (`changesEmail()`, the `current_password` rule),
  `app/Http/Controllers/ProfileController.php::update`, `app/Notifications/EmailChangedNotification.php`,
  `app/Support/MaskedEmail.php`; `routes/web.php` (`profile.update` throttle).
- Tests: `tests/Feature/ProfileEmailChangeTest.php`. Decisions: `documentation/decision-log.md` (SCRUM-422).
