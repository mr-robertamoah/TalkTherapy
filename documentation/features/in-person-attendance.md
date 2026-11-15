# In-person attendance: the counsellor's register, your own mark, and the "marked absent" notice (SCRUM-446)

Part of SCRUM-115. The backend (who's on the register, the 48-hour window, masking, closing as a no-show) is SCRUM-445;
see its entry in `documentation/decision-log.md`. Attendance never moves money.

## What was built

### The counsellor's register
- A counsellor of the therapy or group opens **Attendance** on an in-person session that has started:
  - from the session's details ("show details" on a session card under "Most Recent Sessions", which lists the therapy's
    newest 5 sessions), or
  - from the "Session Actions" dialog while the in-person session is the active one. A session that has ended is no
    longer the active one, so after the end (including both seeded sessions) it is reached through "show details".
- The section says how many people are marked ("1 of 3 marked") and has a **mark attendance** button (or **view
  attendance** when they can't change it). The button opens the register in a dialog.
- Each person is listed as the server names them. An anonymous member stays masked ("Client (Anonymous User)"). A
  masked former member shows as "Former member, left <month year>".
- Each person gets a **Present / Absent** choice: radio buttons styled as a two-part control, with the person's name as
  the group label. Arrow keys move between the two choices.
- **save attendance** sends everyone who has a choice. Anyone left without one keeps what they had. The button stays
  disabled until something changes.
- The dialog shows the window: "You can mark attendance until <date, time>".
- The register is read-only (names and Present / Absent / Not marked, no form) when:
  - the window has closed;
  - the session was closed (FAILED or ABANDONED);
  - the viewer is an administrator; or
  - the counsellor can't act right now (for example, they're suspended). The server tells the page this
    (`canRecord`), so the page never shows a form the server would refuse.
- **Close as a no-show:** offered when everyone on the register is marked absent, the session's end time has passed and
  the session never started (PENDING, or a start that hasn't been confirmed).
  - A confirm dialog explains that the session will be marked failed, that nobody is charged for it, and that this
    can't be undone.
  - The server checks the rest (for example, a start asked for by the client). If it refuses, its message is shown.
- Errors appear in a `role="alert"` box. "Attendance saved." and the no-show confirmation appear in a `role="status"`
  region.

### What a client or member sees
- On an in-person session's details (and in the Session Actions dialog), a client or member sees their own mark:
  "Your counsellor marked you present (on <date>)." or "Your counsellor marked you absent (on <date>)." The date is
  when it was marked, not the session's date.
- They see nothing while they're unmarked, and never anyone else's mark.
- When the counsellor saves, the session is announced (`SessionUpdatedEvent`). The page fetches the session again, and
  the fetched session already carries the viewer's own mark, so the line updates without a reload.

### The "marked absent" notice
- `SessionMarkedAbsentNotification` goes to a client or member when they are marked **absent**: their first mark, or a
  change to absent.
- It is never sent for "present", and never again when an unchanged "absent" is saved again.
- At most one notice an hour per person and session, so a mark flipped back and forth doesn't send a stream of them.
- It goes only to the person marked. An anonymous member is told about their own mark, and nobody is told about theirs.
- Text, in the bell and the email: "You were marked absent from <session name> on <date>. If that's wrong, talk to your
  counsellor." For a paid session it ends "...talk to your counsellor or ask for a refund." (the notice saves which, so
  the bell matches the email).
- It never includes the place, the counsellor who marked it, or anyone else's name.
- It is in the **Sessions** notification category. It always goes in-app, and by email when the address is verified and
  the person hasn't switched off Sessions emails (as the other session notices do).

### "Your data"
The Sessions section of `/profile/your-data` now shows how many in-person sessions your counsellor marked your
attendance at (when there are any). It also says who sees those marks: you, the session's counsellors, and
administrators (recorded).

## Try it

**Test data:** see "In-person attendance" in `documentation/seeded-data.md`. All passwords are `password`.

Both demo sessions end **an hour before the seeder runs**, and the window closes 48 hours after that. If it has closed,
re-run the seeder. It also puts the 1:1 session back to PENDING, clears all marks, and lifts the absent notice's
hourly limit for the demo users, so the notice can be sent again:

```bash
docker compose exec -T -u www-data php php artisan db:seed --class=InPersonAttendanceSeeder --force
```

1. **Mark the 1:1 session:** sign in as `michael_chen` and open "In-Person Demo Therapy".
   - The session has ended, so it is never the active one: under "Most Recent Sessions" (the newest 5), find
     "In-Person Attendance Demo Session" and click **show details**. The Attendance
     section says "0 of 1 marked".
   - Click **mark attendance**. The dialog says "You can mark attendance until …" (about 47 hours from now) and lists
     John Davis with Present / Absent.
   - Choose **Present** and save. You should see "Attendance saved." and "Last marked …".
2. **The client's view:** in another browser, sign in as `john_davis` and open the same session's details.
   - It says "Your counsellor marked you present (on <today>)".
   - Leave it open. As `michael_chen`, change him to **Absent** and save. John's line changes to "absent" without a
     reload.
   - John's bell now has "You were marked absent", with the session name and date and nothing else. Mailpit
     (http://localhost:8027) has the same email.
   - Save "Absent" again unchanged: no second notice arrives. Changing him to Present and back to Absent within the
     hour doesn't send a second one either (the hourly limit); re-run the seeder to try the notice again.
3. **Close as a no-show:** with John marked absent, the dialog offers **close as a no-show**.
   - Click it. The confirm dialog explains the session will be marked failed and nobody is charged.
   - Confirm. The status message shows, the session's status becomes FAILED, and the register turns read-only ("This
     session was closed, so its attendance can no longer be changed."). Focus moves to the dialog's heading, with the
     status message under it, and a **close** button closes the read-only register.
4. **A group with an anonymous member:** as `michael_chen`, open "In-Person Demo Group".
   - Under "Most Recent Sessions", open "In-Person Attendance Demo Group Session" (HELD) with **show details**, then
     click **mark attendance**.
   - The register lists John Davis, Robert Miller and "Client (Anonymous User)". That's `amy_taylor`, who joined
     anonymously; her name appears nowhere.
   - Mark the anonymous member absent and save. Sign in as `amy_taylor`: she has the notice and sees her own mark.
     Sign in as `robert_miller`: he sees only his own mark (or nothing).
   - The no-show option is not offered, because the session was held.
5. **Read-only:**
   - As the super admin (`mr_robertamoah`), open the group session's details: **view attendance** shows the register
     without a form.
   - After the window passes (or with the session's `end_time` moved back more than 48 hours), the counsellor sees it
     read-only too, with "Attendance could be marked until …".
6. **Your data:** as `john_davis`, open `/profile/your-data`. After step 1, the Sessions section says "Your counsellor
   marked whether you came to 1 in-person session." The number counts every in-person session he has a mark on, so it
   is 2 if you also marked him in the group session (step 4).

## Deploying (and in development)

Restart the queue workers once this ships: `php artisan queue:restart` (in Docker:
`docker compose exec -u www-data php php artisan queue:restart`, or restart the `queue` container). A long-running
worker still on the old code fails queued session notices with "Call to undefined relationship [attendances]".

## Files

- `resources/js/Components/SessionAttendanceSection.vue`: the section on a session (the counsellor's button and
  summary, the client's own mark).
- `resources/js/Components/SessionAttendanceModal.vue`: the register dialog, saving, and the no-show confirmation.
- `resources/js/Components/SessionModal.vue`, `SessionBadge.vue`, `resources/js/Pages/UnifiedTherapy.vue`: where it
  appears.
- `resources/js/Pages/Profile/YourData.vue`: the attendance count.
- `app/Notifications/SessionMarkedAbsentNotification.php` (in `App\Support\NotificationCategories::MAP`, Sessions).
- `app/Actions/SessionAttendance/RecordSessionAttendanceAction.php` now returns who was newly marked absent.
  `app/Services/SessionAttendanceService.php` sends the notice and adds `canRecord` to the register response.
- `database/seeders/InPersonAttendanceSeeder.php`.
- Tests: `tests/Feature/SessionMarkedAbsentNotificationTest.php`, `tests/Feature/InPersonAttendanceSeederTest.php`.
