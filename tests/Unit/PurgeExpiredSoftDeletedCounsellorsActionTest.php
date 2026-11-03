<?php

use App\Actions\Counsellor\PurgeExpiredSoftDeletedCounsellorsAction;
use App\Models\Counsellor;
use App\Models\User;

// SCRUM-134: a Counsellor is soft-deleted at deletion time and only permanently purged after the
// configurable grace period (config('counsellor.deletion_grace_period_days'), default 60) has
// passed -- gives a window to notice/undo an accidental or malicious deletion.

test('a counsellor soft-deleted within the grace period is not purged', function () {
    $counsellor = Counsellor::factory()->create(['user_id' => User::factory()]);
    $counsellor->delete();
    $counsellor->forceFill(['deleted_at' => now()->subDays(config('counsellor.deletion_grace_period_days') - 1)])->save();

    PurgeExpiredSoftDeletedCounsellorsAction::new()->execute();

    expect(Counsellor::withTrashed()->find($counsellor->id))->not->toBeNull();
});

test('a counsellor soft-deleted exactly at the grace period boundary is purged', function () {
    $counsellor = Counsellor::factory()->create(['user_id' => User::factory()]);
    $counsellor->delete();
    $counsellor->forceFill(['deleted_at' => now()->subDays(config('counsellor.deletion_grace_period_days'))])->save();

    PurgeExpiredSoftDeletedCounsellorsAction::new()->execute();

    expect(Counsellor::withTrashed()->find($counsellor->id))->toBeNull();
});

test('a counsellor soft-deleted well past the grace period is purged', function () {
    $counsellor = Counsellor::factory()->create(['user_id' => User::factory()]);
    $counsellor->delete();
    $counsellor->forceFill(['deleted_at' => now()->subDays(config('counsellor.deletion_grace_period_days') + 30)])->save();

    PurgeExpiredSoftDeletedCounsellorsAction::new()->execute();

    expect(Counsellor::withTrashed()->find($counsellor->id))->toBeNull();
});

test('a counsellor that is not soft-deleted is never purged', function () {
    $counsellor = Counsellor::factory()->create(['user_id' => User::factory()]);

    PurgeExpiredSoftDeletedCounsellorsAction::new()->execute();

    expect(Counsellor::find($counsellor->id))->not->toBeNull();
});

test('the grace period respects config overrides', function () {
    config(['counsellor.deletion_grace_period_days' => 5]);

    $counsellor = Counsellor::factory()->create(['user_id' => User::factory()]);
    $counsellor->delete();
    $counsellor->forceFill(['deleted_at' => now()->subDays(6)])->save();

    PurgeExpiredSoftDeletedCounsellorsAction::new()->execute();

    expect(Counsellor::withTrashed()->find($counsellor->id))->toBeNull();
});
