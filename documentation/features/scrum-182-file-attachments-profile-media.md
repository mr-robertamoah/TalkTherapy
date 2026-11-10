# SCRUM-182: File attachments & profile media (TT-10)

Standardizes the app's file-attachment mechanism onto the existing `fileables` polymorphic pivot
(previously only `License::files()` actually used it — every other attachment was a one-off FK
column), and adds two new upload surfaces that didn't exist before: organization logo and plain
user avatar. Counsellor avatar/cover also got migrated onto the same mechanism so all three share
one upload UI and one consistent authorization/validation shape.

Split into 8 sub-tickets (TT-10.1–TT-10.8, SCRUM-183 through SCRUM-190), all merged into
`develop`. See `documentation/decision-log.md` for the detailed judgment calls made throughout
(the `withPivotValue` vs `wherePivotValue` gotcha, several N+1 audits, a PATCH+multipart upload
bug, a Tailwind dynamic-class bug, and more) — this doc covers what was built and how to try it.

## What was built

- **Tagged `fileables` pivot** — a nullable `tag` column plus a composite unique index on
  `(fileable_type, fileable_id, tag)`, so a single model can hold more than one distinct file slot
  (e.g. a `Counsellor` has both an `avatar` and a `cover`) through the same pivot `License::files()`
  already used for its untagged, multi-file collection use case.
- **Three upload surfaces, all on the same mechanism**:
  - **Counsellor avatar/cover** — `Counsellor::avatarFile()`/`coverFile()`, migrated off the old
    `avatar_id`/`cover_id` FK columns (which remain on the table, unused, pending a later cleanup
    migration).
  - **Organization logo** — `Organization::logoFile()`, new upload/delete endpoint
    (`PATCH /organizations/{organizationId}`), restricted to the organization's admins.
  - **User avatar** — `User::avatarFile()`, a new, self-service-only endpoint
    (`POST /profile/avatar`) available to every plain user, not just counsellors.
- **`ImageUploadField.vue`** — one shared Vue component behind all three surfaces: a hover-reveal
  camera-icon overlay for changing the image, a remove/restore toggle, and (added in TT-10.8)
  instant client-side size/MIME rejection before a bad file is even submitted.
- **`App\Support\ImageUploadRules`** — the single source of truth for the enforced limits (2MB,
  jpg/jpeg/png/webp), applied identically on all three `FormRequest`s. Mirrored (not shared,
  since PHP and JS can't literally share code) in `resources/js/Constants/imageUploadLimits.js`
  for the client-side check.

## Try it out

All three surfaces are already covered by seeded accounts — no new seed data was needed for this
feature. Every account below uses password `password` (see `documentation/seeded-data.md` for the
full roster and login instructions).

### 1. Counsellor avatar/cover

1. Log in as `sarah_johnson` (or any of the other 5 seeded counsellor accounts).
2. Visit `/counsellor/1` (Sarah's own counsellor profile).
3. Click the **EDIT** button in the top-right of the cover/avatar area to open "Update Counsellor
   Account".
4. Hover the cover image or avatar circle and click the camera icon (or the "add/change..." text
   link below it) to pick a file; hover and click the "×"/"↺" badge to remove or restore an
   existing image.
5. Click **update** — a valid jpg/png/webp under 2MB uploads and the modal closes with the new
   image showing immediately; an oversized or wrong-type file is rejected instantly, before the
   click, with a message under the field ("cover image must be a jpg, png, or webp image." /
   "avatar must be smaller than 2MB.").

### 2. Organization logo

1. Log in as `org_demo_admin` (owner-role admin of "Org Demo Wellness Collective").
2. Visit `/organizations/1/dashboard` (or whatever id the seeder assigns — a non-admin is
   redirected home before ever seeing this page).
3. Click **edit profile**, then use the same `ImageUploadField` control for the logo.
4. Save — the logo appears next to the organization's name on this same page, and (per
   `GetOrganizationDirectoryAction`) anywhere the org is listed publicly.

### 3. User avatar

1. Log in as any plain (non-counsellor) seeded user, e.g. `maria_garcia`.
2. Visit `/profile` — the avatar control sits at the top of the gradient hero, above "Welcome
   Back!".
3. Unlike the other two surfaces, this one auto-submits: picking a file or clicking remove/restore
   saves immediately (no separate "save" button), since there's no other field on this control to
   batch the save with. A "saving..." label shows while the request is in flight, and the control
   disables itself for that window so a fast remove-then-restore click can't race itself.

### Confirming rejection and validation behavior

To see the client-side rejection without needing a real oversized file, pick any file over 2MB or
with an extension other than jpg/jpeg/png/webp (e.g. a `.pdf`) on any of the three surfaces above
— the request never fires; the error appears immediately. Validation is enforced identically
server-side (`App\Support\ImageUploadRules`), so the same rejection also happens if the client
check is bypassed (curl/Postman directly against the endpoint, or JS disabled).

## Follow-ups filed, deliberately out of scope

- **SCRUM-191** — the pre-existing `licenses` storage folder is also publicly symlinked
  (unrelated to this epic, found in passing).
- **SCRUM-192** — none of the three upload routes have rate-limiting; a cheap availability
  concern, not a data-exposure one.
- **SCRUM-193** — `AdminUserResource` (the bulk admin users list) doesn't expose the new
  `avatar` field yet, so admin-listed users won't show one until this is picked up.
- **SCRUM-194** — `HowToStep::file_id` is a fourth FK-column file attachment not migrated onto
  `fileables` as part of this epic.
