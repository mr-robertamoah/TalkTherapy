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

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::call(function () {
    AppService::new()->notifyParticipantsOfStartingSessions();
})->everyThirtyMinutes();

Schedule::call(function () {
    AppService::new()->failUnheldSessions();
})->everyFourHours();

Schedule::call(function () {
    AppService::new()->broadcastStartedSessions();
})->everyFiveMinutes();

Schedule::call(function () {
    AppService::new()->clearVisitors();
})->dailyAt('00:01');

Schedule::call(function () {
    AppService::new()->alertSuperAdminWithStatus();
})->everyFourHours()->between('0:00', '24:00');
