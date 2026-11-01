<?php

use App\Models\Discussion;
use App\Models\GroupTherapy;
use App\Models\Session;
use App\Models\Therapy;
use App\Services\AppService;
use Illuminate\Support\Facades\Artisan;
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
