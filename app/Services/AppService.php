<?php

namespace App\Services;

use App\Models\Counsellor;
use App\Models\Report;
use App\Models\Therapy;
use App\Models\User;
use App\Notifications\QueueJobFailedNotification;
use App\Notifications\ReportNotification;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Throwable;

// SCRUM-374/376: the scheduled sweeps that used to live here are now Actions, called from
// routes/console.php.
class AppService extends Service
{
    public function alertAdminWithReport(Report $report)
    {
        $admins = User::query()->whereAdmin()->inRandomOrder()->limit(2)->get();

        Notification::send($admins->unique(), new ReportNotification($report));
    }

    // SCRUM-82: a job reaching Queue::failing() has already exhausted its retries, so this is
    // the last chance to surface it -- logged first, ahead of the try/catch, so it's captured
    // even if notifying admins itself fails (this still relies on the log channel itself not
    // throwing, but that's true of every other Log::error() call in this codebase too), then a
    // best-effort admin email on top.
    public function alertAdminsOfFailedJob(JobFailed $jobFailed): void
    {
        Log::error('A queued job failed.', [
            'connection' => $jobFailed->connectionName,
            'queue' => $jobFailed->job->getQueue(),
            'job' => $jobFailed->job->resolveName(),
            'exception' => $jobFailed->exception->getMessage(),
        ]);

        try {
            $admins = User::query()->whereAdmin()->inRandomOrder()->limit(2)->get();

            // SCRUM-32 (devops review): a failed safety notice or alert mustn't tell an administrator -- maybe
            // one involved -- which family was told, nor that a flag exists: its name and error stay in the log.
            $sensitive = str_contains($jobFailed->job->resolveName(), 'Safety');

            Notification::send($admins->unique(), new QueueJobFailedNotification(
                $sensitive ? 'A safety notification (details in the server log)' : $jobFailed->job->resolveName(),
                $jobFailed->connectionName,
                $jobFailed->job->getQueue(),
                $sensitive ? 'Withheld -- see the server log.' : $jobFailed->exception->getMessage(),
            ));
        } catch (Throwable $exception) {
            // Never let the alerting path itself crash the queue worker -- log and move on.
            Log::error('Failed to notify admins about a failed queue job.', [
                'exception' => $exception->getMessage(),
            ]);
        }
    }

    public function getStats()
    {
        return ['stats' => [
            'counsellors' => Counsellor::count(),
            'users' => User::count(),
            'therapies' => Therapy::query()->withoutHelpChats()->count(),
        ]];
    }
}
