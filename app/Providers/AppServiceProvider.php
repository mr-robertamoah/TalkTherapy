<?php

namespace App\Providers;

use App\Listeners\FailHealthCheckOnPendingMigrations;
use App\Services\AppService;
use Illuminate\Foundation\Events\DiagnosingHealth;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // SCRUM-82: previously there was no operational visibility into failed queue jobs at
        // all. AppService::alertAdminsOfFailedJob() does the actual logging/notifying so it can
        // be unit tested directly, without needing to dispatch a real failing job.
        Queue::failing(function (JobFailed $jobFailed) {
            AppService::new()->alertAdminsOfFailedJob($jobFailed);
        });

        // SCRUM-109: makes the /up health check (configured in bootstrap/app.php) visibly fail
        // if the deploy pipeline's `migrate --force` step was ever skipped or failed silently.
        Event::listen(DiagnosingHealth::class, FailHealthCheckOnPendingMigrations::class);
    }
}
