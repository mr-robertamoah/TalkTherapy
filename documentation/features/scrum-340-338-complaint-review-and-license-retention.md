# Complaint review with confidential access (SCRUM-340) and license document retention (SCRUM-338)

## What was built

### SCRUM-340: admins review reports

- **A review queue** at `/administrator/reports` (also linked from `/administrator` as
  "reports"). It lists active reports (open first, then under review, oldest first), and each
  status can be listed on its own.
- **A page per report** at `/administrator/reports/{id}` showing:
  - what was reported, who reported it, the description and evidence;
  - the reported person;
  - review actions;
  - the reported conversation;
  - the report's history and a log of who read the conversation.
- **Lifecycle:** `open` → `under_review` → `resolved` (needs an outcome and a note) or `dismissed`
  (needs a note). A closed report can be reopened. When a report is closed, the reporter and the
  reported person get an email, which never names the admin. If confidential messages were read,
  the email says so.
- **Recording the reported person:** an admin picks a participant of the reported therapy. This is
  how a group report gets one, since reporters can't name group members.
- **Reading the reported conversation:** the admin picks a session and gives a reason. The
  conversation shows:
  - every non-confidential message;
  - the confidential messages, and the messages deleted for everyone (labelled), that the reported
    person sent or received.

  Other people's confidential or deleted messages stay hidden. Every confidential message shown, and every
  confidential attachment opened, is recorded. The first read moves an open report to
  under review.
- **Who can review:** any admin except one who is part of the report: the reporter, the reported
  person, a participant of the therapy, or the author of a reported post. Such an admin sees only
  "you are part of this report" in the queue, and can't open the page, its evidence, or the
  conversation.
- **Recording the reported person** starts the review (the report moves to under review).
- **The reporter** can no longer edit or delete a report once review has started.
- **The report form** for a therapy or group now tells the reporter that an admin may read the
  reported person's messages, including confidential ones.

### SCRUM-338: counsellor verification documents

- Rejecting a counsellor verification request no longer marks its licenses as validated.
- Verification documents are deleted on a schedule (daily at 04:15):
  - **Rejected requests:** all documents, 30 days after the rejection.
  - **Accepted requests:** only the national ID scan and number, 30 days after the acceptance.
    The professional license is kept.
  - **Pending requests:** nothing is touched.

  The license records are kept either way, and the admin license card shows "Documents removed
  under the retention policy on <date>".
- When a soft-deleted counsellor is purged, their license documents go too.
- Both windows are configurable: `COUNSELLOR_LICENSE_REJECTED_RETENTION_DAYS` and
  `COUNSELLOR_NATIONAL_ID_RETENTION_DAYS`.

## Test data

Seeded by `DatabaseSeeder::createReportReviewDemoData()`. Log in as the **super admin**
(`mr_robertamoah`; see `documentation/seeded-data.md`).

The seed creates "Report Demo Group Therapy":

- created by `maria_garcia`, counselled by Dr. Emma Williams;
- `amy_taylor` and `robert_miller` as anonymous members;
- one finished session, "Report Demo Session", holding public and confidential messages.

`maria_garcia` has filed three reports on it:

| Report | State | What to try |
|---|---|---|
| "[Report demo: naming amy_taylor] ..." | open, reported person = amy_taylor | Read the conversation, resolve or dismiss it |
| "[Report demo: nobody named yet] ..." | open, no reported person | See that the conversation can't be read until someone is recorded; record one |
| "[Report demo: already dismissed] ..." | dismissed, with history | Reopen it |

## How to try it

1. Log in as the super admin and open `/administrator/reports`. Two open reports are listed. The
   dismissed one appears under the "dismissed" filter.
2. Open the "naming amy_taylor" report.
3. Under **Reported conversation**, choose "Report Demo Session", type a reason of at least 10
   characters, and click **read conversation**. You should see:
   - amy_taylor's confidential message to the counsellor, and the counsellor's confidential reply
     to her;
   - robert_miller's confidential message shown as "Confidential message between other people.
     Not part of this report.";
   - amy_taylor's later message labelled "deleted for everyone", with its content;
   - the status badge changing to **Under Review**, and the access log showing your name, the
     session and your reason.
4. Click **resolve**, choose an outcome, write a note and click **resolve report**. Check Mailpit
   (http://localhost:8027): maria.garcia@example.com and amy.taylor@example.com each get "A report
   has been reviewed". Both emails mention that confidential messages were read, and neither names
   you.
5. Open the "nobody named yet" report. The conversation section asks you to record the reported
   person first. Choose amy_taylor and click **record**. The report moves to Under Review, and the
   conversation can now be read.
6. As `maria_garcia`, open the group's report button. The form shows the disclosure about admins
   reading messages.

**License retention** has no seeded documents. Try it from the command line:

```bash
docker compose exec php php artisan licenses:apply-retention --dry-run           # lists what would be deleted
docker compose exec php php artisan licenses:repair-rejected-validations --dry-run
```

With `--dry-run` both change nothing: `apply-retention` prints a table of what it would remove, and `repair-rejected-validations` prints the license ids it would un-validate. The behaviour is covered in
`tests/Feature/LicenseRetentionTest.php`.

## Tests

- `tests/Feature/AdminReportReviewTest.php`: lifecycle, notifications, the involved-admin refusal,
  recording the party, the unlock scope, deleted messages, attachments, the audit trail,
  append-only records, the reporter lock, and arch rules.
- `tests/Feature/LicenseRetentionTest.php`: the reject fix, the repair command, every retention
  rule, the backfill, the counsellor purge, and the resource field.
