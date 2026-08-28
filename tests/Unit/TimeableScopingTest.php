<?php

use App\Models\Discussion;
use App\Models\Session;
use App\Models\Therapy;
use App\Models\User;

// Regression tests for SCRUM-129: Timeable::isNotUpdateable()/isNotDeleteable() built queries
// where the id-scoping only applied to the first `where` clause, with the `orWhere` branches
// left unscoped -- so ANY row in the whole table being "about to start" or currently ongoing
// made every OTHER row (regardless of its own id) report as not-updateable/not-deleteable.

test('an unrelated session that is about to start does not make a safely-scheduled session non-updateable', function () {
    $therapy = Therapy::factory()->create(['addedby_type' => User::class, 'addedby_id' => User::factory()]);

    $safeSession = Session::factory()->create([
        'for_type' => Therapy::class,
        'for_id' => $therapy->id,
        'start_time' => now()->addDays(3),
        'end_time' => now()->addDays(3)->addHour(),
    ]);

    // Unrelated session, about to start within the next 30 minutes -- if isNotUpdateable()'s
    // orWhere branches aren't scoped to $this->id, this alone would flip $safeSession's result.
    Session::factory()->create([
        'for_type' => Therapy::class,
        'for_id' => $therapy->id,
        'start_time' => now()->addMinutes(10),
        'end_time' => now()->addMinutes(70),
    ]);

    expect($safeSession->isNotUpdateable())->toBeFalse();
    expect($safeSession->isUpdateable())->toBeTrue();
});

test('an unrelated ongoing session does not make a safely-scheduled session non-deleteable', function () {
    $therapy = Therapy::factory()->create(['addedby_type' => User::class, 'addedby_id' => User::factory()]);

    $safeSession = Session::factory()->create([
        'for_type' => Therapy::class,
        'for_id' => $therapy->id,
        'start_time' => now()->addDays(3),
        'end_time' => now()->addDays(3)->addHour(),
    ]);

    // Unrelated session, currently ongoing -- isNotDeleteable() previously ran this check with
    // no id-scoping at all, so any ongoing row anywhere would flip every other row's result.
    Session::factory()->create([
        'for_type' => Therapy::class,
        'for_id' => $therapy->id,
        'start_time' => now()->subMinutes(10),
        'end_time' => now()->addMinutes(50),
    ]);

    expect($safeSession->isNotDeleteable())->toBeFalse();
    expect($safeSession->isDeleteable())->toBeTrue();
});

test('an unrelated discussion that is about to start does not make a safely-scheduled discussion non-updateable', function () {
    // isNotUpdateable() branches explicitly on Session::class vs Discussion::class -- covering
    // both models, not just Session, since the two branches build genuinely separate queries.
    $therapy = Therapy::factory()->create(['addedby_type' => User::class, 'addedby_id' => User::factory()]);

    $safeDiscussion = Discussion::factory()->create([
        'for_type' => Therapy::class,
        'for_id' => $therapy->id,
        'start_time' => now()->addDays(3),
        'end_time' => now()->addDays(3)->addHour(),
    ]);

    Discussion::factory()->create([
        'for_type' => Therapy::class,
        'for_id' => $therapy->id,
        'start_time' => now()->addMinutes(10),
        'end_time' => now()->addMinutes(70),
    ]);

    expect($safeDiscussion->isNotUpdateable())->toBeFalse();
    expect($safeDiscussion->isUpdateable())->toBeTrue();
});
