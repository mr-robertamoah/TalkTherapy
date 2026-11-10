<?php

use App\Models\Administrator;
use App\Models\Counsellor;
use App\Models\CounsellorPayout;
use App\Models\User;
use App\Notifications\PayoutFailedNotification;

// TT-7.6c/SCRUM-227 (security review finding): Notification::fake() (used everywhere else this
// notification is exercised) never actually calls toMail(), so it can't catch a rendering bug --
// this test deliberately renders the mail message for real, for BOTH notifiable types this
// notification is actually sent to (the counsellor, and an admin User), since the original bug
// (calling ->getName() unconditionally, which only Counsellor defines) only broke the User path.

test('toMail renders without error for a Counsellor recipient', function () {
    $counsellor = Counsellor::factory()->create(['user_id' => User::factory(), 'name' => 'Dr. Jane']);
    $payout = CounsellorPayout::factory()->create(['counsellor_id' => $counsellor->id, 'amount' => 10000, 'currency' => 'GHS']);

    $mail = (new PayoutFailedNotification($payout, 'Invalid account details'))->toMail($counsellor);

    expect($mail->greeting)->toContain('Dr. Jane');
    expect(implode(' ', $mail->introLines))->toContain('GHS 100.00');
    expect(implode(' ', $mail->introLines))->toContain('Invalid account details');
});

test('toMail renders without error for an admin User recipient', function () {
    $counsellor = Counsellor::factory()->create(['user_id' => User::factory()]);
    $payout = CounsellorPayout::factory()->create(['counsellor_id' => $counsellor->id]);
    $admin = User::factory()->has(Administrator::factory())->create(['firstName' => 'Admin', 'lastName' => 'Alex']);

    $mail = (new PayoutFailedNotification($payout))->toMail($admin);

    expect($mail->greeting)->toContain('Admin Alex');
});
