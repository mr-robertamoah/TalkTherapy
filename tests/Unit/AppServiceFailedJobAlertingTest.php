<?php

use App\Models\Administrator;
use App\Models\User;
use App\Notifications\QueueJobFailedNotification;
use App\Services\AppService;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

// SCRUM-82: prior to this, a job reaching Queue::failing() (i.e. one that has already exhausted
// its retries) produced zero operational visibility -- no log, no notification, nothing.

function fakeFailedJob(string $jobName, string $queue): JobFailed
{
    $job = Mockery::mock(Job::class);
    $job->shouldReceive('resolveName')->andReturn($jobName);
    $job->shouldReceive('getQueue')->andReturn($queue);

    return new JobFailed('database', $job, new Exception('Something went wrong.'));
}

test('a failed job is always logged, even if no admins exist to notify', function () {
    Log::shouldReceive('error')
        ->once()
        ->with('A queued job failed.', Mockery::on(function ($context) {
            return $context['job'] === 'App\\Jobs\\SendReminderJob'
                && $context['queue'] === 'default'
                && $context['connection'] === 'database'
                && $context['exception'] === 'Something went wrong.';
        }));

    AppService::new()->alertAdminsOfFailedJob(
        fakeFailedJob('App\\Jobs\\SendReminderJob', 'default')
    );
});

test('a failed job notifies up to two admins by email, and never a non-admin', function () {
    Notification::fake();

    User::factory()->count(3)->has(Administrator::factory())->create();
    $nonAdmin = User::factory()->create();

    AppService::new()->alertAdminsOfFailedJob(
        fakeFailedJob('App\\Jobs\\SendReminderJob', 'default')
    );

    // Exactly 2 recipients, never more than the "up to 2" cap this shares with
    // AppService::alertAdminWithReport()'s existing admin-alerting convention.
    Notification::assertSentTimes(QueueJobFailedNotification::class, 2);
    Notification::assertNotSentTo($nonAdmin, QueueJobFailedNotification::class);
});

test('a failure while notifying admins is logged instead of crashing the listener', function () {
    Notification::shouldReceive('send')->andThrow(new Exception('Mail server is down.'));

    Log::shouldReceive('error')->once()->with('A queued job failed.', Mockery::any());
    Log::shouldReceive('error')
        ->once()
        ->with('Failed to notify admins about a failed queue job.', [
            'exception' => 'Mail server is down.',
        ]);

    User::factory()->has(Administrator::factory())->create();

    // Would throw and bubble up into the queue worker if the try/catch around notifying admins
    // were ever removed.
    AppService::new()->alertAdminsOfFailedJob(
        fakeFailedJob('App\\Jobs\\SendReminderJob', 'default')
    );
});
