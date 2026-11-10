<?php

use App\Models\Counsellor;
use App\Models\Report;
use App\Models\User;
use App\Notifications\ReportNotification;

test('does not crash when the reporting user has deleted their account', function () {
    $reporter = User::factory()->create();
    $report = Report::factory()->create([
        'description' => 'inappropriate behaviour',
        'addedby_id' => $reporter->id,
        'addedby_type' => User::class,
        'reportable_id' => 1,
        'reportable_type' => User::class,
    ]);
    $reporter->delete();

    $admin = User::factory()->create();
    $mail = (new ReportNotification($report->fresh()))->toMail($admin);

    expect(collect($mail->introLines)->implode(' '))->toContain($reporter->name);
});

test('does not crash when the reporting counsellor has deleted their account', function () {
    $reporterUser = User::factory()->create();
    $reporterCounsellor = Counsellor::factory()->create(['user_id' => $reporterUser->id]);
    $report = Report::factory()->create([
        'description' => 'inappropriate behaviour',
        'addedby_id' => $reporterCounsellor->id,
        'addedby_type' => Counsellor::class,
        'reportable_id' => 1,
        'reportable_type' => User::class,
    ]);
    $reporterUser->delete();
    $reporterCounsellor->delete();

    $admin = User::factory()->create();
    $mail = (new ReportNotification($report->fresh()))->toMail($admin);

    expect(collect($mail->introLines)->implode(' '))->toContain($reporterCounsellor->getName());
});

test('falls back to a placeholder name when the reporter cannot be resolved at all', function () {
    $report = Report::factory()->create([
        'description' => 'inappropriate behaviour',
        'addedby_id' => 999999,
        'addedby_type' => User::class,
        'reportable_id' => 1,
        'reportable_type' => User::class,
    ]);

    $admin = User::factory()->create();
    $mail = (new ReportNotification($report))->toMail($admin);

    expect(collect($mail->introLines)->implode(' '))->toContain('a deleted account');
});
