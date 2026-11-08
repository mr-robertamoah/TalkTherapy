# SCRUM-39 (TT-5.4a): notification center — backend

The JSON behind the in-app notification center. The bell and dropdown that use it come in TT-5.4c, and wording
for the remaining notification types comes in TT-5.4b. Until then, those types show as "You have an update".

## What it does

- **One list:** the signed-in person's database notifications plus their counsellor profile's, newest
  first, 15 a page with a cursor. Each item says whether it belongs to the user or to their counsellor
  profile (`audience: user | counsellor`).
- **Item shape:** each item is exactly `{id, title, message, url, createdAt, read, audience, safetyNotice}`.
  It never includes the saved data, internal ids, a ward reference or a class name.
- **Hidden types:**
  - admin-only types are hidden from anyone who isn't an administrator now;
  - super-admin-only types are hidden from an administrator who isn't a super administrator;
  - safety-flag types are hidden while `SAFETY_FLAGS_ENABLED` is off.

  Hidden items are left out of the count and can't be marked read either.
- **Marking read:** a person can mark one notification read (the bell will also do this when an item is
  clicked) or mark all read. The person's or a guardian's **safety notice** is never marked by the list,
  because only its own dialog dismisses it (`safetyNotice: true` tells the UI to open that dialog).
- **Live updates:** a stored notification, or a notification being marked read, sends a content-free
  `notifications.changed` event on the person's private `users.{id}` channel.

| Method | URL | Notes |
|---|---|---|
| GET | `/notifications?cursor=…` | `{notifications: [...], nextCursor}` |
| GET | `/notifications/count` | `{unread}` (safety notices included) |
| POST | `/notifications/{uuid}/read` | 404 for anyone else's notification, a hidden one, or a safety notice |
| POST | `/notifications/read-all` | `{marked}` |

A suspended account gets 403, and a guest gets 401.

## How to try it

1. Sign in at http://localhost:8000 as the super admin `mr_robertamoah` (see "Test data").
2. Open http://localhost:8000/notifications in the same browser. It returns JSON with the safety alerts,
   help-request alerts and other notifications this account has received, each with a title, a short message
   and a link.
3. To see the next page, append `?cursor=<nextCursor>`.
4. Open http://localhost:8000/notifications/count to see the unread count.
5. To mark items read, run these from the browser console (the POST routes need the CSRF token):
   ```js
   const token = document.cookie.match(/XSRF-TOKEN=([^;]+)/)[1]
   await fetch('/notifications/read-all', { method: 'POST', headers: { 'X-XSRF-TOKEN': decodeURIComponent(token), Accept: 'application/json' } }).then(r => r.json())
   ```
   The count then drops to the number of unread safety notices, if any.
6. Sign in as a demo client and repeat. The admin-only items don't appear, and only that person's own
   notifications do.

## Test data

- **Administrator:** `mr_robertamoah`, the super admin in `documentation/seeded-data.md`. Locally it has
  received safety-flag and help-request alerts from earlier QA.
- **Non-admin:** any demo user from `documentation/seeded-data.md`.

No new seed data is needed.
