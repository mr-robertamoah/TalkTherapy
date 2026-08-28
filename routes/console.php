<?php

use App\Services\AppService;
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
