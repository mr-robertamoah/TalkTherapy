<?php

use App\Actions\Alert\BroadcastStartedSessionsAndDiscussionsAction;
use App\Actions\Counsellor\PurgeExpiredSoftDeletedCounsellorsAction;
use App\Actions\Discussion\FailUnheldDiscussionsAction;
use App\Actions\Discussion\NotifyParticipantsOfStartingDiscussionsAction;
use App\Actions\Guardianship\FindMinorsWithoutActiveGuardianAction;
use App\Actions\Guardianship\HandleGuardianLossAction;
use App\Actions\Organization\ExpireStaleCompensationRequestsAction;
use App\Actions\Organization\SendCompensationRequestExpiryRemindersAction;
use App\Actions\Organization\SettleDueOrganizationInvoicesAction;
use App\Actions\Request\DeleteExpiredIdentityDocumentsAction;
use App\Actions\Session\FailUnheldSessionsAction;
use App\Actions\Session\NotifyParticipantsOfStartingSessionsAction;
use App\Actions\Therapy\FindPaidTherapiesMissingPaymentDataAction;
use App\Actions\Transaction\ReconcileStalePendingOrganizationChargesAction;
use App\Actions\User\AnonymiseClosedAccountsAction;
use App\Actions\Video\ExpireIdleSpeakingGrantsAction;
use App\Actions\VideoConsent\SendGuardianVideoConsentRemindersAction;
use App\Actions\Visitor\ClearVisitorsAction;
use App\Actions\Visitor\SendVisitorsStatusToSuperAdminAction;
use App\Enums\VideoConsentReminderWindowEnum;
use App\Models\Discussion;
use App\Models\GroupTherapy;
use App\Models\Session;
use App\Models\Therapy;
use App\Services\LicenseService;
use App\Services\SafeguardingExceptionService;
use App\Services\SuspensionService;
use App\Support\SchedulerHeartbeat;
use App\Support\SchedulerOnce;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schedule;

/*
|--------------------------------------------------------------------------
| Console Routes
|--------------------------------------------------------------------------
|
| This file is where you may define all of your Closure based console
| commands. Each Closure is bound to a command instance allowing a
| simple approach to interacting with each command's IO methods.
|
*/

// SCRUM-373 (architecture review): the scheduler's own locks (withoutOverlapping/onOneServer) live in
// its store too, which the deploy's cache:clear leaves alone.
//
// SCRUM-378: every schedule has a name, so it can take withoutOverlapping()/onOneServer() later
// without first changing its lock key, and so `schedule:list` reads as a list of jobs.
Schedule::useCache(config('cache.scheduler_store'));

// SCRUM-248: resolve org charges whose Paystack webhook never arrived. A command (not a closure) so it
// can run in the background: each Paystack call can take up to PAYSTACK_TIMEOUT, and a slow run must
// not hold up the every-minute sweeps or the heartbeat. A 30-minute lock, not the default 24 hours, so
// a run killed mid-way doesn't lock the sweep out for a day. Also runnable by hand.
Artisan::command('payments:reconcile-stale-org-charges', function () {
    $this->info(json_encode(ReconcileStalePendingOrganizationChargesAction::new()->execute()));
})->purpose('Check org charges stuck in pending with Paystack and record the answer');

Schedule::command('payments:reconcile-stale-org-charges')
    ->name('reconcile-stale-org-charges')->everyFifteenMinutes()
    ->runInBackground()->withoutOverlapping(30)->onOneServer();

// SCRUM-373: proof of life for /health/scheduler (SchedulerHealthController).
Schedule::call(fn () => SchedulerHeartbeat::beat())->name('scheduler-heartbeat')->everyMinute();

Schedule::call(fn () => NotifyParticipantsOfStartingSessionsAction::new()->execute())->name('notify-starting-sessions')->everyFiveMinutes();

Schedule::call(fn () => FailUnheldSessionsAction::new()->execute())->name('fail-unheld-sessions')->everyFourHours();

Schedule::call(fn () => BroadcastStartedSessionsAndDiscussionsAction::new()->execute())->name('broadcast-started-sessions-and-discussions')->everyMinute();

Schedule::call(fn () => NotifyParticipantsOfStartingDiscussionsAction::new()->execute())->name('notify-starting-discussions')->everyMinute();

Schedule::call(fn () => FailUnheldDiscussionsAction::new()->execute())->name('fail-unheld-discussions')->everyFourHours();

Schedule::call(fn () => ClearVisitorsAction::new()->execute())->name('clear-visitors')->dailyAt('00:01');

Schedule::call(fn () => SendVisitorsStatusToSuperAdminAction::new()->execute())->name('send-visitors-status')->dailyAt('0:00');

Schedule::call(fn () => PurgeExpiredSoftDeletedCounsellorsAction::new()->execute())->name('purge-deleted-counsellors')->dailyAt('01:00');

// SCRUM-367: closed accounts' personal details, after the retention period.
Schedule::call(fn () => AnonymiseClosedAccountsAction::new()->execute())->name('anonymise-closed-accounts')->dailyAt('01:30')->withoutOverlapping()->onOneServer();

Schedule::call(fn () => SendCompensationRequestExpiryRemindersAction::new()->execute())->name('compensation-request-expiry-reminders')->dailyAt('02:00');

Schedule::call(fn () => ExpireStaleCompensationRequestsAction::new()->execute())->name('expire-stale-compensation-requests')->dailyAt('02:15');

Schedule::call(fn () => SettleDueOrganizationInvoicesAction::new()->execute())->name('settle-due-organization-invoices')->dailyAt('03:00');

Schedule::call(fn () => SendGuardianVideoConsentRemindersAction::new()->execute(VideoConsentReminderWindowEnum::day_before))->name('guardian-video-consent-day-before')->dailyAt('08:00');

Schedule::call(fn () => SendGuardianVideoConsentRemindersAction::new()->execute(VideoConsentReminderWindowEnum::hour_before))->name('guardian-video-consent-hour-before')->everyFiveMinutes();

Schedule::call(fn () => DeleteExpiredIdentityDocumentsAction::new()->execute())->name('delete-expired-identity-documents')->dailyAt('04:00');

// SCRUM-338: counsellor verification documents past their retention window.
// Each deletion is logged (devops review): the files can't be restored, so the log is the trail.
Schedule::call(function () {
    LicenseService::new()->applyDocumentRetention()->each(
        fn (array $item) => Log::info('License documents removed under the retention policy.', $item)
    );
})->name('delete-expired-license-documents')->dailyAt('04:15')
    ->onFailure(fn () => Log::error('The license document retention sweep failed.'));

// SCRUM-359: tells people whose suspension reached its end. Enforcement never waits for this --
// a suspension stops applying the moment its end passes (Suspension::scopeActive).
Schedule::call(fn () => SuspensionService::new()->endExpiredSuspensions())
    ->name('end-expired-suspensions')->everyFiveMinutes()->withoutOverlapping(10)
    ->onFailure(fn () => Log::error('The suspension expiry sweep failed.'));

// SCRUM-381: a safeguarding exception stops applying the moment its end passes
// (SafeguardingException::scopeActive); this records the end and, with still no guardian, pauses the
// minor's therapy again with the usual notices. Every minute, so the notices follow closely.
Schedule::call(fn () => SafeguardingExceptionService::new()->endExpiredExceptions())
    ->name('end-expired-safeguarding-exceptions')->everyMinute()->withoutOverlapping(10)
    ->onFailure(fn () => Log::error('The safeguarding exception expiry sweep failed.'));

// SCRUM-381: the heads-up three days before an exception runs out (once per exception).
Schedule::call(fn () => SafeguardingExceptionService::new()->sendExpiryReminders())
    ->name('safeguarding-exception-expiry-reminders')->hourly()->withoutOverlapping(30)
    ->onFailure(fn () => Log::error('The safeguarding exception reminder sweep failed.'));

// TT-3.2f-h/SCRUM-325: every minute, not every five -- with the 5-minute default timeout, a
// five-minute sweep would let an idle grant linger for up to ~10 minutes.
Schedule::call(fn () => ExpireIdleSpeakingGrantsAction::new()->execute())->name('expire-idle-speaking-grants')->everyMinute()->withoutOverlapping();

// SCRUM-353: the seeded "Chat Demo" sessions and discussion are created live (now - 10 min to
// now + 1 hour), so an hour after seeding their end time has passed and the demo chat pages have
// nothing to send into. This makes them live again without a reseed. Local only, and only the seeded
// demo therapy and group's own records -- never anything matched by name alone (security review).
Artisan::command('demo:refresh-chat', function () {
    if (! app()->environment('local')) {
        $this->error('Only available in the local environment.');

        return 1;
    }

    $demoTherapies = Therapy::query()->where('name', 'Chat Demo Individual Therapy')->pluck('id');
    $demoGroups = GroupTherapy::query()->where('name', 'Chat Demo Group Therapy')->pluck('id');
    $window = ['start_time' => now()->subMinutes(10), 'end_time' => now()->addHour(), 'status' => 'in_session'];

    $sessions = Session::query()
        ->whereIn('name', ['Chat Demo Live Session', 'Chat Demo Group Live Session'])
        ->where(fn ($q) => $q
            ->where(fn ($q) => $q->where('for_type', Therapy::class)->whereIn('for_id', $demoTherapies))
            ->orWhere(fn ($q) => $q->where('for_type', GroupTherapy::class)->whereIn('for_id', $demoGroups)))
        ->update($window);
    $discussions = Discussion::query()
        ->where('name', 'Chat Demo Discussion')
        ->where('for_type', Therapy::class)->whereIn('for_id', $demoTherapies)
        ->update($window);

    $this->info("Refreshed {$sessions} demo session(s) and {$discussions} demo discussion(s): live until ".now()->addHour()->format('H:i').'.');

    return 0;
})->purpose('Make the seeded Chat Demo sessions and discussion live again (local only)');

// SCRUM-141: lists PAID therapies and group therapies whose payment settings were wiped by the
// pre-SCRUM-140 bug, with their owners, so someone can ask them to set the price again. Changes
// nothing. Exits 1 when it finds any. Run it by hand or as a report -- never as a deploy gate: only
// the owners can fix the data, and deploy.sh stays in maintenance mode on a failing step.
Artisan::command('payments:audit-paid-therapies', function () {
    $rows = FindPaidTherapiesMissingPaymentDataAction::new()->execute();

    if ($rows->isEmpty()) {
        $this->info('No paid therapy or group therapy is missing its payment settings.');

        return 0;
    }

    $this->table(['Type', 'ID', 'Name', 'Owner', 'Missing'], $rows->map(fn (array $row) => [
        $row['type'], $row['id'], $row['name'], $row['owner'], implode(', ', $row['missing']),
    ])->all());
    $this->warn("{$rows->count()} record(s) need their owner to set the payment settings again.");

    return 1;
})->purpose('List paid therapies and group therapies with missing payment settings (amount, currency, per)');

// SCRUM-288 (Q7): who is paused for a missing guardian right now -- minors (and accounts with no
// date of birth, which count as minors) with no active guardian, by id, with what they have open.
// Read-only; run before deploying so the user can decide about the no-date-of-birth accounts.
Artisan::command('guardians:report-unguarded-minors', function () {
    $found = FindMinorsWithoutActiveGuardianAction::new()->execute();
    $headers = ['User ID', 'Open therapies', 'Group memberships', 'Upcoming sessions', 'Pending proposals'];
    $toRows = fn ($rows) => $rows->map(fn (array $row) => [
        $row['id'], $row['openTherapies'], $row['groupMemberships'], $row['upcomingSessions'], $row['pendingProposals'],
    ])->all();

    foreach ([
        FindMinorsWithoutActiveGuardianAction::NO_DOB => 'No date of birth',
        FindMinorsWithoutActiveGuardianAction::KNOWN_MINOR => 'Known minor (under 18)',
    ] as $group => $label) {
        $rows = $found[$group];
        $this->info("{$label}: {$rows->count()} account(s), {$rows->filter(fn (array $row) => $row['openTherapies'] || $row['groupMemberships'])->count()} with an open therapy or group.");

        if ($rows->isNotEmpty()) {
            $this->table($headers, $toRows($rows));
        }
    }

    return 0;
})->purpose('List minors (and accounts with no date of birth) whose therapies are paused for a missing guardian. Read-only.');

// SCRUM-382: accounts that were already paused before every way into the pause ran the loss handling
// (an approved dob change, or simply being a minor with no guardian when SCRUM-288 shipped) were
// never taken out of their calls or told. One-off: runs it (HandleGuardianLossAction, queued) once
// for every account paused now with an open therapy or group. Once per account, ever (a marker in the
// scheduler's store, which the deploy's cache:clear leaves alone), so running it again is harmless.
// --dry-run lists the ids and changes nothing.
Artisan::command('guardians:announce-existing-pauses {--dry-run}', function () {
    $dryRun = (bool) $this->option('dry-run');
    $found = FindMinorsWithoutActiveGuardianAction::new()->execute();
    $ids = $found[FindMinorsWithoutActiveGuardianAction::NO_DOB]
        ->concat($found[FindMinorsWithoutActiveGuardianAction::KNOWN_MINOR])
        ->filter(fn (array $row) => $row['openTherapies'] || $row['groupMemberships'])
        ->pluck('id')
        ->sort()
        ->values();

    if ($dryRun) {
        $this->info("Would announce the pause of {$ids->count()} account(s)".($ids->isNotEmpty() ? ': '.$ids->implode(', ') : '.'));

        return 0;
    }

    // Claimed and queued one account at a time: a queueing failure gives that account's marker back
    // (so a re-run tries it again), is logged and counted, and doesn't stop the rest.
    $announced = collect();
    $failed = collect();
    $skipped = 0;

    foreach ($ids as $id) {
        $key = "guardian-pause-existing:{$id}";

        if (! SchedulerOnce::key($key, now()->addYears(10))) {
            $skipped++;

            continue;
        }

        try {
            HandleGuardianLossAction::new()->schedule($id, HandleGuardianLossAction::EXISTING_PAUSE);
            $announced->push($id);
        } catch (Throwable $th) {
            SchedulerOnce::forget($key);
            Log::error('Could not queue the announcement of an existing pause.', ['ward' => $id, 'error' => $th->getMessage()]);
            $failed->push($id);
        }
    }

    if ($announced->isNotEmpty()) {
        Log::info('guardian_access.existing_pauses_announced', ['wards' => $announced->all()]);
    }

    $this->info("Queued the pause announcement for {$announced->count()} account(s)"
        .($announced->isNotEmpty() ? ': '.$announced->implode(', ') : '.')
        ." Skipped {$skipped} already announced.");

    if ($failed->isNotEmpty()) {
        $this->error("Could not queue {$failed->count()} account(s): {$failed->implode(', ')}. Run it again to retry them.");

        return 1;
    }

    return 0;
})->purpose('One-off: take every account already paused for a missing guardian out of live calls and tell them and their counsellors, once.');

// SCRUM-338: applies the license document retention policy now, to everything already past its
// window (the same rules the daily 04:15 sweep uses). --dry-run lists what would go.
Artisan::command('licenses:apply-retention {--dry-run}', function () {
    $dryRun = (bool) $this->option('dry-run');
    $removed = LicenseService::new()->applyDocumentRetention($dryRun);
    if (! $dryRun) {
        $removed->each(fn (array $item) => Log::info('License documents removed under the retention policy.', $item));
    }

    $this->table(['License', 'Reason', 'Number cleared'], $removed->map(fn ($item) => [
        $item['license'], $item['reason'], $item['clearNumber'] ? 'yes' : 'no',
    ])->all());
    $this->info(($dryRun ? 'Would remove' : 'Removed').' documents of '.$removed->count().' license(s).');

    return 0;
})->purpose('Delete counsellor license documents that are past their retention window');

// SCRUM-338: before the fix, rejecting a counsellor verification request validated its licenses.
// Un-validates licenses that only ever appeared on rejected requests; lists (doesn't change) their
// licensing authorities for an admin to check by hand.
Artisan::command('licenses:repair-rejected-validations {--dry-run}', function () {
    $dryRun = (bool) $this->option('dry-run');
    $result = LicenseService::new()->repairRejectedVerificationValidations($dryRun);
    if (! $dryRun && $result['licenses']) {
        Log::info('Licenses un-validated by licenses:repair-rejected-validations.', ['licenses' => $result['licenses']]);
    }

    $this->info(($dryRun ? 'Would un-validate' : 'Un-validated').' '.count($result['licenses']).' license(s)'
        .($result['licenses'] ? ': '.implode(', ', $result['licenses']) : '.'));

    if ($result['authorities']->isNotEmpty()) {
        $this->warn('Validated licensing authorities on those licenses -- check by hand, not changed:');
        $this->table(['Authority', 'Name'], $result['authorities']->map(fn ($a) => [$a->id, $a->name])->all());
    }

    return 0;
})->purpose('Un-validate licenses that were wrongly validated by a rejected verification request');
