# SCRUM-354: likes and comments on public therapies

Public individual therapies and public group therapies can now be liked and commented on, like posts. The same work also made private therapies' likes and comments unreachable (SCRUM-351).

## What was built

- **The bar.** A likes and comments bar on the therapy page (`/therapies/{id}`) and the group therapy page (`/group-therapies/{id}`). It appears **only for public therapies**.
  - **Likes:** a toggle with a count. Logged-out visitors see the count but can't like.
  - **Comments:** a button with a count that opens the comments list and form (`CommentsModal`).
- **Private therapies** don't show the bar. The API refuses to like them, comment on them, or list their comments, with the same answer it gives for a therapy that doesn't exist.
- **Anonymity.**
  - A comment by one of the therapy's own anonymous people shows as "Client (Anonymous User)" to everyone else, with no user id. That means the client of an anonymous therapy, or an anonymous group member or creator. The author still sees it as theirs and can delete it.
  - Anyone else's comment shows their username, even on an anonymous therapy.
- **Likes on therapies** are only ever a count plus whether *you* liked it. The API never says who liked a therapy, so an anonymous client liking their own therapy can't be spotted. Post and counsellor likes are unchanged.

## How to try it

**Test data:** a normal seed (`docker compose exec php php artisan migrate:fresh --seed`). The seeded accounts all use the password `password` (see `documentation/seeded-data.md`).

**Public individual therapy:** Therapy 8 is public. Its owner is `robert_miller`.
1. Log in as `maria_garcia` and open http://localhost:8000/therapies/8.
2. Below the header, click the heart. It turns solid and shows "1 like". Click again to unlike.
3. Click "0 comments", type a comment, and click **add comment**. It appears in the list, and the bar shows "1 comment".

**Public group:** group therapies 3, 5 and 6 are public. Open http://localhost:8000/group-therapies/3 and repeat the steps above.

**Private therapy:** therapy 1 belongs to `maria_garcia` and is private. Open http://localhost:8000/therapies/1: there is no likes and comments bar.

**Anonymous comment:**
1. Make a public therapy anonymous and comment on it as its owner, using tinker or the therapy's update form.
2. Log in as anyone else and open the comments. The owner's comment shows "Client (Anonymous User)".

## Tests

- `tests/Feature/TherapyLikesAndCommentsTest.php`
- `tests/Feature/LinkCreationAuthorizationTest.php`: SCRUM-350, shipped in the same PR.
