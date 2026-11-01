<?php

use App\Models\Discussion;
use App\Models\GroupTherapy;
use App\Models\Session;
use App\Models\Therapy;
use App\Services\AppService;
use App\Services\LicenseService;
use App\Services\SuspensionService;
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

Schedule::call(function () {
    AppService::new()->notifyParticipantsOfStartingSessions();
})->everyFiveMinutes();

Schedule::call(function () {
    AppService::new()->failUnheldSessions();
})->everyFourHours();

Schedule::call(function () {
    AppService::new()->broadcastStartedSessionsAndDiscussions();
})->everyMinute();

Schedule::call(function () {
    AppService::new()->notifyParticipantsOfStartingDiscussions();
})->everyMinute();

Schedule::call(function () {
    AppService::new()->failUnheldDiscussions();
})->everyFourHours();

Schedule::call(function () {
    AppService::new()->clearVisitors();
})->dailyAt('00:01');

Schedule::call(function () {
    AppService::new()->alertSuperAdminWithStatus();
})->dailyAt('0:00');

Schedule::call(function () {
    AppService::new()->purgeExpiredSoftDeletedCounsellors();
})->dailyAt('01:00');

Schedule::call(function () {
    AppService::new()->sendCompensationRequestExpiryReminders();
})->dailyAt('02:00');

Schedule::call(function () {
    AppService::new()->expireStaleCompensationRequests();
})->dailyAt('02:15');

Schedule::call(function () {
    AppService::new()->settleDueOrganizationInvoices();
})->dailyAt('03:00');

Schedule::call(function () {
    AppService::new()->sendDayBeforeGuardianVideoConsentReminders();
})->dailyAt('08:00');

Schedule::call(function () {
    AppService::new()->sendHourBeforeGuardianVideoConsentReminders();
})->everyFiveMinutes();

Schedule::call(function () {
    AppService::new()->deleteExpiredIdentityDocuments();
})->dailyAt('04:00');

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

// TT-3.2f-h/SCRUM-325: every minute, not every five -- with the 5-minute default timeout, a
// five-minute sweep would let an idle grant linger for up to ~10 minutes.
Schedule::call(function () {
    AppService::new()->expireIdleSpeakingGrants();
})->name('expire-idle-speaking-grants')->everyMinute()->withoutOverlapping();

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
