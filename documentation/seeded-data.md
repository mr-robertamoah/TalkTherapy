# Seeded Test Data

Reference for what's in the local database after seeding, so you can log in and exercise features
through the UI without creating accounts by hand. Regenerate at any time with:

```bash
docker exec talktherapy-php php artisan migrate:fresh --seed
```

This drops and recreates all tables, then runs `database/seeders/DatabaseSeeder.php`. It's
destructive to whatever is currently in the local DB — fine for dev, don't run it against
anything you care about keeping.

## Logging in

The login page (`/login`) has a "Login with username" checkbox that toggles between an email
field and a username field — either works with the same password for every seeded account below.

## Super Admin

| Field | Value |
|---|---|
| Username | `mr_robertamoah` |
| Email | `mr_robertamoah@yahoo.com` |
| Password | value of `SUPER_PASSWORD` in `.env.docker` (currently `itisme2025`) |

Has an `Administrator` record with `type = super` — use this account for `/administrator` and
anything admin-gated.

## Demo users

All seeded demo users share the password **`password`**. Email is already verified
(`email_verified_at` set) for every one of them, so none of the email-verification flow blocks
login — if you specifically need to test *that* flow, register a fresh account instead (see
"Testing the registration/verification flow" below).

The first 6 are also **counsellors** (verified, with a profile, languages, cases, and religion
already attached); the last 4 are plain users.

| # | Username | Email | Name | Counsellor? |
|---|---|---|---|---|
| 1 | `sarah_johnson` | sarah.johnson@example.com | Sarah Johnson | Yes — Dr. Sarah Johnson (#1) |
| 2 | `michael_chen` | michael.chen@example.com | Michael Chen | Yes — Dr. Michael Chen (#2) |
| 3 | `emma_williams` | emma.williams@example.com | Emma Williams | Yes — Dr. Emma Williams (#3) |
| 4 | `david_brown` | david.brown@example.com | David Brown | Yes — Dr. David Brown (#4) |
| 5 | `lisa_anderson` | lisa.anderson@example.com | Lisa Anderson | Yes — Dr. Lisa Anderson (#5) |
| 6 | `james_wilson` | james.wilson@example.com | James Wilson | Yes — Dr. James Wilson (#6) |
| 7 | `maria_garcia` | maria.garcia@example.com | Maria Garcia | No |
| 8 | `john_davis` | john.davis@example.com | John Davis | No |
| 9 | `amy_taylor` | amy.taylor@example.com | Amy Taylor | No |
| 10 | `robert_miller` | robert.miller@example.com | Robert Miller | No |

A counsellor logs in with the same account as the underlying user (there's no separate
counsellor login) — e.g. log in as `sarah_johnson` / `password` to act as Dr. Sarah Johnson.

## Reference data

Seeded once, shared across all users: 10 languages, 8 religions, 12 therapy cases (Anxiety,
Depression, Trauma/PTSD, etc.), 10 professions, 5 licensing authorities.

## Sample content (regenerated randomly on every reseed — IDs below are illustrative)

After a fresh seed you'll get roughly:
- **5 therapies**, owned by the 4 non-counsellor users, each assigned a random counsellor from
  the 6 above, with 2-4 topics and 2-5 sessions each (mixed `pending`/`held`/`in_session`/
  `abandoned` status) — some `anonymous`, some `public`, randomly, so you can find both cases to
  test against.
- **3-5 group therapies**, each with 3-8 participating users and 1-3 counsellors.
- **3-6 discussions** between counsellors, tied to a random therapy.
- **~20 posts** from counsellors (mental-health articles) plus a few from regular users, with
  likes and comments from other seeded users.

Additionally, three **named, deterministic** records exist on every reseed specifically for
exercising the dedicated chat pages (see `documentation/features/scrum-20-live-chat.md`): a
`Chat Demo Individual Therapy` and `Chat Demo Group Therapy` (both `IN_SESSION` with a live
session, client `maria_garcia` / counsellor `sarah_johnson`), and a `Chat Demo Discussion`
(`IN_SESSION`, between `sarah_johnson` and `michael_chen`, with existing messages).

Query the actual current values anytime rather than trusting numbers to stay the same across
reseeds:

```bash
docker exec talktherapy-php php artisan tinker --execute="
foreach (\App\Models\Therapy::with('addedby','counsellor')->get() as \$t) {
    echo \$t->id.' | '.\$t->name.' | owner='.\$t->addedby->username.' | counsellor='.\$t->counsellor->name.PHP_EOL;
}"
```

## Testing the registration/verification flow

The 11 seeded accounts above all skip email verification. To test registration or the
verification email itself, register a new account through the UI with any email address — the
app sends real emails through **Mailpit** in this dev environment, not out to the internet.
View them at **http://localhost:8025**.

## Keeping this file current

When a feature needs its own seed data to be testable via the UI (a new model, a new state that
doesn't exist in the current seeder, a specific edge case), add it to `DatabaseSeeder.php` (or a
dedicated seeder it calls) rather than requiring manual setup, and document it in that feature's
`documentation/features/<feature-slug>.md` — see "Feature documentation" in `CLAUDE.md`.
