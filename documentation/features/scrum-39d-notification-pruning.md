# SCRUM-39 (TT-5.4d): notification pruning

Old notifications are deleted for good every night.

## What it does

- **Default period:** 90 days, set by `NOTIFICATIONS_RETENTION_DAYS`.
- **Personal choice:** on their **Profile** page, under **Notifications**, each person can choose 30 days,
  90 days, 180 days, 1 year, or the default.
  - Their counsellor profile's notifications follow the same choice.
  - Choosing 90 when the default is 90 keeps 90 even if the default changes later. The default option
    follows whatever the default is.
- **The nightly run (02:30):** deletes every notification older than its owner's period, read or unread.
  - **Never deleted:** an unread "check in" safety notice (SCRUM-32), which stays until its dialog
    dismisses it.
  - **Owner gone for good:** notifications whose owner was permanently deleted follow the default.
- **Closing an account:** now also deletes the counsellor profile's notifications, not just the
  person's own.
- **/privacy** states the default and that it can be changed.

## How to try it

1. Sign in as `maria_garcia` / `password`, open **Profile**, and find the **Notifications** card.
2. Choose *1 year* and press **Save**. *Saved.* appears. Reload, and it is still selected.
3. Open http://localhost:8000/privacy. Under "How long we keep it" it says notifications are deleted 90 days
   after they were sent.
4. To see a run, age one of maria's notifications and run the prune now:
   ```bash
   docker compose exec -T -u www-data -e HOME=/tmp php php artisan tinker
   >>> $n = App\Models\User::where('username','maria_garcia')->first()->notifications()->first();
   >>> $n->forceFill(['created_at' => now()->subDays(400)])->save();
   >>> App\Actions\Notification\PruneNotificationsAction::new()->execute();
   ```
   It returns the number deleted. The `laravel.log` gets `Notifications pruned.` with counts per period. With
   maria on *1 year*, a notification 400 days old goes and a 200-day-old one stays.
5. Put her back on the default when done.

## Test data

There is no new seed data. Use `maria_garcia` (19 seeded notifications, SCRUM-39c) and age one by hand as
above. Seeded notifications are at most 12 days old, so a normal night deletes none of them.

## Deployment notes

- **Migrations:**
  - a nullable `users.notification_retention_days` (instant or online on MySQL 8);
  - an index on `notifications.created_at` (online).
- **New env var:** `NOTIFICATIONS_RETENTION_DAYS`. It is optional: unset or empty means 90.
- **The first night after deploying to `main` deletes everything older than 90 days. It can't be undone.**
  Before promoting:
  1. Size it: `select count(*) from notifications where created_at < now() - interval 90 day;`
  2. If it must be recoverable, back up the table first: `mysqldump <db> notifications`.
  3. Optionally, start with `NOTIFICATIONS_RETENTION_DAYS=365` and step it down over later deploys.
- **Pause pruning without a deploy:** set `NOTIFICATIONS_RETENTION_DAYS` to anything from 1 to 6. The run is
  skipped with an error log. 0 or empty does **not** pause it; it means 90.
- **Monitoring:** see `documentation/operations/monitoring.md`, which covers the skipped-run error, the
  `onFailure` line and the daily `Notifications pruned.` record.
- **Rollback:** revert the code. Older code ignores the new column. `migrate:rollback --step=2` drops the
  column and the index. Deleted notifications come back only from a backup.
