# SCRUM-37 + SCRUM-437: outcome check-ins (PHQ-9, GAD-7), and counsellors' notes encrypted

## What was built

### Outcome check-ins (SCRUM-37, TT-5.2)

**Where they live:** on a 1:1 therapy. The ticket said "attached to a TherapyCase", but in this app a TherapyCase is a
shared topic label ("Anxiety"), not a client's file.

**The counsellor:**
- Sends the client a **PHQ-9** (depression) or **GAD-7** (anxiety) from the therapy page's "Check-ins" panel.
- Only one of each questionnaire can wait for an answer at a time.
- A check-in expires after 7 days.
- There is a daily limit per counsellor.

**The client:**
- Answers every question, once.
- The questions use the exact published wording, with the "not a diagnosis" line and the required credit.
- The server works out the score and the symptom level (PHQ-9: minimal to severe, 0-27; GAD-7: 0-21).

**Progress:** both sides see the answered check-ins, the answers, and a chart per questionnaire over time. The chart is
plain SVG with a table version for screen readers.

**A self-harm answer** (PHQ-9 item 9, "Thoughts that you would be better off dead or of hurting yourself", above "Not at
all"):
- The client sees their country's emergency number and "I need help now" **the moment they choose it**, and again
  after sending. They're told the counsellor will see it, but not straight away.
- The counsellor gets an alert that **can't be switched off** and says nothing of the answer. The check-in stays
  highlighted until they mark it as seen.
- No safety flag is raised automatically, and admins aren't told (the user's decisions).
- An answer that comes too late to be saved (expired, withdrawn, the therapy ended) still alerts the counsellor.

**Who can see it:**
- Only that therapy's counsellor and its client. Anyone else, including guardians and admins, gets "not found".
- Minors can answer, and guardians see nothing.
- Answers, scores and levels are encrypted.
- Notifications never name the questionnaire or give a score.

**Your data:**
- The client's check-ins are counted on "What we hold about you" and given in full in their data copy.
- When the account is erased, the answers and scores are deleted. The counsellor's record keeps that one was sent, and
  whether an answer needed their attention.

**Switched off** outside local and testing (`CHECK_INS_ENABLED`) until the clinical and legal wording is read. While
it's off, every route is a 404 and the panel isn't shown.

### Counsellors' notes encrypted (SCRUM-437)

- Session and message notes are encrypted in the database.
- A migration encrypts the existing ones, soft-deleted ones included. It works in chunks, is safe to run again, and can
  be undone with the same key.
- Exporting notes as a PDF is left for later (the user's decision).

## Try it

1. Sign in as `your_data_demo_counsellor` / `password` and open "Your Data Demo Therapy" (Therapies).
   1. The **Check-ins** panel shows a PHQ-9 chart of 4 answers. The latest is highlighted ("needs your attention").
      Show its answers, then **Mark as seen**.
   2. A GAD-7 is waiting for the client. Send a PHQ-9.
2. In another browser, sign in as `your_data_demo` / `password` and open the same therapy.
   1. Under **To answer**, answer the GAD-7: you see your score and level.
   2. Answer the PHQ-9 with "Several days" on the last question. Help appears at once, and again after sending.
3. Back as the counsellor:
   1. The bell has "A check-in answer needs your attention". The email in Mailpit (http://localhost:8027) names no
      questionnaire or answer.
   2. Its link opens the therapy with that check-in highlighted.
4. As `your_data_demo`, open `/profile/your-data`: you'll see "Check-ins you answered". `/privacy` has a "Check-ins"
   section.

## Test data

- `your_data_demo` (client) and `your_data_demo_counsellor` on "Your Data Demo Therapy". `CheckInSeeder` adds 4
  answered PHQ-9s (the latest with a self-harm answer not yet seen) and 1 open GAD-7.
- To add it to an existing database: `docker compose exec -u www-data php php artisan db:seed --class=CheckInSeeder`.

## Where it lives

- `App\Support\OutcomeMeasures\{OutcomeMeasure,Phq9,Gad7}`, `App\Enums\OutcomeMeasureEnum`
- `App\Models\OutcomeCheckIn`; migration `2026_12_05_110000`
- `App\Actions\OutcomeCheckIn\*`: `CheckInAccess` decides who may do what
- `App\Http\Controllers\OutcomeCheckInController`, routes `api.check_ins.*`; `config/check_ins.php`, middleware
  `check_ins`
- Notifications: `OutcomeCheckIn{Requested,Completed,Attention}Notification`
- `resources/js/Components/CheckIns/{OutcomeCheckInsPanel,OutcomeScoreChart}.vue`, in `BaseTherapyLayout.vue`
- Notes: `SessionNote` and `MessageNote` casts; migration `2026_12_05_100000`
- Tests: `tests/Feature/OutcomeCheckInTest.php`, `tests/Feature/NotesEncryptionTest.php`
