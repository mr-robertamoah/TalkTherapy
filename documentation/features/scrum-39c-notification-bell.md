# SCRUM-39 (TT-5.4c): the notification bell

The notification bell in the main navigation, on every page that uses the main layout, at every screen
size, for clients, counsellors and administrators.

## What it does

- **The bell**
  - It shows a red count of unread notifications, as "9+" past nine. It hides at zero.
  - Screen readers hear "Notifications, N unread".
- **The panel** opens on click. It shows the person's own notifications and their counsellor profile's,
  newest first, 15 at a time, with **Show more** for older ones.
  - Unread items are shaded and bold.
  - Counsellor-profile items carry an **As counsellor** label.
- **Opening an item** marks it read and follows its link. An item without a link is only marked read.
- **Mark as read:**
  - the check button on an unread item marks just that one;
  - **Mark all as read** marks every unread item.
- **Safety notices** ("A message from TalkTherapy") show *Opens the message*. Clicking one opens the
  existing safety-notice dialog.
  - The list never marks a safety notice read, so the bell keeps counting it.
  - Only "I've read this" in that dialog dismisses it.
  - Once dismissed, it stays in the list by its title only, with no message and nothing to open.
- **Live updates:**
  - when a notification arrives, or is marked read in another tab, the count updates without a reload;
  - if the panel is open, its list refreshes too.
  - The ping carries no content.
- **No toasts or previews:** nothing about a notification shows until the person opens the panel.
- **Keyboard and screen readers:**
  - Tab to the bell and press Enter to open it; focus moves to the panel's heading.
  - Escape, or a click or focus anywhere outside the panel, closes it and returns focus to the bell.
- **Phones:** the panel spans the screen width, down to 320px.
- **Suspended accounts:** the bell is hidden, because the server refuses them.

## How to try it

1. Sign in at http://localhost:8000 as `maria_garcia` / `password`.
2. The bell is next to the username, top right. It shows `9+`.
3. Open it. Unread items are at the top, and **Show more** at the bottom loads older ones.
4. Click *A session was scheduled*. It opens the Chat Demo therapy and is now read.
5. Click the check on another unread item, then **Mark all as read**. The badge drops, and stays down after a
   reload.
6. Sign in as `sarah_johnson` / `password`. Items with **As counsellor** belong to her counsellor
   profile.
7. **Live:** keep maria signed in and send her a notification from tinker:
   ```bash
   docker compose exec -T -u www-data -e HOME=/tmp php php artisan tinker
   >>> Illuminate\Support\Facades\Notification::sendNow(App\Models\User::where('username','maria_garcia')->first(), new App\Notifications\DobChangeRequestRejectedNotification(new App\Models\Request), ['database']);
   ```
   The badge goes up without a reload.
8. **Safety notice** (`SAFETY_FLAGS_ENABLED=true`): send one the same way with
   `new App\Notifications\SafetyNoticeNotification`. In the bell it says *Opens the message*.
   - **Mark all as read** leaves it counted.
   - Clicking it opens the dialog, and **I've read this** clears it.

## Test data

`NotificationCenterSeeder`, which `DatabaseSeeder` calls, gives:

- **`maria_garcia`:** 19 notifications (some unread, more than a page). They link to *Chat Demo Individual
  Therapy* and *Chat Demo Group Therapy*.
- **`sarah_johnson`:** 1 on her account and 3 on her counsellor profile, including *A discussion starts
  soon*, which links to *Chat Demo Discussion*.

It is safe to run again on an existing database, and it skips anyone who already has them:
`docker compose exec -T -u www-data -e HOME=/tmp php php artisan db:seed --class=NotificationCenterSeeder --force`.
