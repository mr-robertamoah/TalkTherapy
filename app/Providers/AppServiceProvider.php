<?php

namespace App\Providers;

use App\Listeners\FailHealthCheckOnPendingMigrations;
use App\Services\AppService;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Foundation\Events\DiagnosingHealth;
use Illuminate\Mail\Markdown;
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
        // SCRUM-254: user-written text (names, reasons, notes) goes into mail lines and greetings,
        // which are rendered as Markdown -- "[click](https://evil)" would become a real link. This
        // escapes "[" and "<" in every {{ }} echo of the HTML mail, so nothing a user typed can
        // turn into a link, image or tag. The plain-text part is untouched (no stray backslashes).
        Markdown::withSecuredEncoding();

        // SCRUM-363: "contains" search that matches %, _ and ! literally (escaped, with an explicit
        // ESCAPE so MySQL and SQLite agree), across one or more columns OR-ed inside one group.
        QueryBuilder::macro('whereContains', function (array|string $columns, string $term) {
            $pattern = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $term).'%';

            return $this->where(function ($query) use ($columns, $pattern) {
                foreach ((array) $columns as $column) {
                    $query->orWhereRaw($query->getGrammar()->wrap($column)." like ? escape '!'", [$pattern]);
                }
            });
        });

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
