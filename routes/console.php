<?php

use App\Services\AppService;
use Illuminate\Foundation\Inspiring;
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
})->everyThirtyMinutes()
    ->name('notifyParticipantsOfStartingSessions')
    ->withoutOverlapping();

Schedule::call(function () {
    AppService::new()->failUnheldSessions();
})->everyFourHours()
    ->name('failUnheldSessions')
    ->withoutOverlapping();

Schedule::call(function () {
    AppService::new()->broadcastStartedSessions();
})->everyFiveMinutes()
    ->name('broadcastStartedSessions')
    ->withoutOverlapping();

Schedule::call(function () {
    AppService::new()->clearVisitors();
})->dailyAt('00:01')
    ->name('clearVisitors')
    ->withoutOverlapping();

Schedule::call(function () {
    AppService::new()->alertSuperAdminWithStatus();
})->dailyAt('0:00')
    ->name('alertSuperAdminWithStatus')
    ->withoutOverlapping();
