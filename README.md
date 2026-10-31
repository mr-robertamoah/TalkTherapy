<div align="center">
    <img src="talktherapy.svg" alt="TalkTherapy Logo" />
</div>

<br/>
<br/>

# TalkTherapy

Welcome to the TalkTherapy application, a Laravel and Vue.js based project designed to facilitate online therapy sessions and make it easier for individuals and groups to find and interact with verified mental health professional in order to achieve **sound mental state**.

## Features

- User authentication and authorization
- Posts by Counsellors
- Real-time chat functionality for both discussions and therapies
- Scheduling of therapy sessions
- Counsellor and User profiles
- Guilded tour feature for most pages
- Secure data handling

## Frontend Pages

- **Home**
    - The landing page of the application.
    - It displays posts from counsellors.
    - Users can share comments on the posts.
    - This page also has public therapies and counsellors.
- **About**
    - Gives you information about the application.
    - It shows which major features are currently deployed as against those been worked on.
- **Login**
    - Allows users to log in to their accounts.
- **Register**
    - Enables new users to create an account.
- **Preference**
    - Interface for setting your preferences with respect to language, religion (if you are particular about it), and other factors.
- **Verify Email**
    - Allows users to verify their emails
    - Links to this page is shared via email provided by a counsellor or user.
- **Forgot Password**
    - Allows users to reset their passwords.
- **User Profile**
    - Allows users to view and edit their profile information
    - This is also where a user can start the process of becoming a counsellor.
- **Counsellor Profile**
    - Allows users to view and edit their counsellor information.
    - This includes the process of become verified, which is essential to being able to interact with users.
- **Therapy**
    - This section allows a user to update or delete a therapy.
    - This page allows a user to send requests to counsellors or create links that can be used by a counsellor to become in charge of a therapy.
    - It also houses all sessions.
- **Therapies**
    - This page shows all the possible therapies in which a user/counsellor is involved.
    - These includes therapies of wards if you are a guardian of any user on the platform.

## Backend Routes

- **Auth Routes**:
    - `GET /api/register`: Get the registration page.
    - `POST /api/register`: Register a new user.
    - `GET /api/login`: Get the login page.
    - `POST /api/login`: Authenticate a user.
    - `POST /api/logout`: Log out the current user.
    - `GET /api/forgot-password`: Get the page for requesting password change.
    - `POST /api/forgot-password`: Sends the reset password link.
    - `GET /api/reset-password`: Get page for resetting password.
    - `POST /api/reset-password`: Resets password for a user.

- **User Routes**:
    - `GET /api/user`: Retrieve the authenticated user's information.
    - `GET /api/users`: Retrieve information of users for Administrators.
    - `GET /api/users/guardianship`: Retrieve information of a user's guardianship.
    - `POST /api/users/{user_id}/guardianship`: Sends guardianship request to other users.
    - `GET /api/user/therapies`: Retrieve therapies associated with a user.

- **Therapy Routes**:
    - `GET /therapies`:  Get page that shows the therapies of user.
    - `GET /therapies/{therapyId}`:  Retrieve details of a therapy.
    - `GET /user/therapies`:  List all therapies for the authenticated user.
    - `GET /ward/therapies`:  List all therapies for the authenticated user's wards.
    - `GET /therapies/counsellor`:  List all therapies for the counsellor.
    - `PATCH /therapies/{therapyId}`:  Update a therapy.
    - `DELETE /therapies/{therapyId}`:  Delete a therapy.
    - `POST /therapies/{therapyId}`:  Change status of a therapy.
    - `POST /therapies/{therapyId}/sessions`:  Create a session for a therapy.
    - `POST /therapies/{therapyId}/topics`:  Create a topic for a therapy.

- **Message Routes**:
    - `GET /messages/discussions/{discussionId}`: Get messages for a discussion
    - `POST /messages`: Send a message
    - `POST /messages/{messageId}`: Update a message
    - `DELETE /messages/{messageId}`: Delete a message
    - `DELETE /messages/{messageId}/me`: Delete a message only for a user/counsellor

## Deployment

Continuously deployed to AWS EC2 instance by leveraging AWS Systems Manger's Run Command capability which enhances security becauses no SSH port is left open.

Each push to `main` runs `.github/workflows/main.yml`. Only one deploy runs at a time. The workflow extracts `scripts/deploy.sh` from the exact commit being deployed, runs it as root on the instance, and fails the job if the deploy fails. The deploy's output is printed only on failure, because the repo is public.

The script does the following, in order:
1. Puts the site in maintenance mode for the whole deploy. It aborts, changing nothing, if it can't.
2. Checks out the commit (it must be on `main`) and builds it as the `ubuntu` user.
3. Gives the private storage folders (identity, license and chat-attachment documents) to the web server user. That user is detected from the running php-fpm workers; set `WEB_USER`/`WEB_GROUP` if detection fails.
4. Runs every `artisan` command, including the migrations, as that web user. Root never runs PHP.
5. Clears the config and cache, and asks the queue and Reverb workers to restart. They only come back if a process manager runs them; the deploy prints what it finds.

**If a deploy fails after the code changed,** the site deliberately stays in maintenance mode. That way it never serves code whose migrations haven't run. Fix the cause on the box, then re-run the same commit:

```bash
sudo DEPLOY_SHA=<sha> bash scripts/deploy.sh
```

To give up instead, check out the previous commit printed at the top of the deploy log, run `composer install`, and run `php artisan up`. Back up `storage/app` and the database before a promotion that includes data migrations: those migrations can't be rolled back.

Scheduled jobs (`routes/console.php` -- reminders, invoice settlement, document retention, the group-video
idle speaking sweep) need Laravel's scheduler running. In Docker, the `queue` container runs it via
Supervisor (`php artisan schedule:work`, see `docker/queue/supervisord.conf`); any other host must run
`php artisan schedule:run` every minute (cron) or keep `schedule:work` alive. Run exactly ONE scheduler
per database -- a second one (e.g. `schedule:work` on the host too) runs every task twice. Seeded demo data
built relative to "now" (open invoices, pending sessions) will be processed by these jobs as it ages --
re-seed (`php artisan migrate:fresh --seed`) to reset it.

## Portfolio

You can find more about me on my [portfolio](https://www.robertamoah.com).


## License

This project is licensed under the MIT License.

## Contact

For any inquiries, please contact me at [robertamoah.dev@gmail.com](mailto:robertamoah.dev@gmail.com).
