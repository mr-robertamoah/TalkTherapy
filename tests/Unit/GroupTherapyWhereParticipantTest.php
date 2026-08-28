<?php

use App\Models\GroupTherapy;
use App\Models\User;

// Regression test for a bug found while implementing SCRUM-108: scopeWhereUser() (and therefore
// scopeWhereParticipant(), which powers GroupTherapyService::getRecentGroupTherapies() among
// other things) only checked the group_therapy_user join-pivot, never the addedby column -- so a
// user who directly created a group therapy (addedby_type === User::class) was invisible to
// "participant" queries unless they also separately joined via the pivot.

test('a user who directly created a group therapy is matched by whereParticipant', function () {
    $creator = User::factory()->create();

    $ownGroupTherapy = GroupTherapy::factory()->create([
        'addedby_type' => User::class,
        'addedby_id' => $creator->id,
    ]);

    $unrelatedGroupTherapy = GroupTherapy::factory()->create([
        'addedby_type' => User::class,
        'addedby_id' => User::factory(),
    ]);

    $matched = GroupTherapy::query()->whereParticipant($creator)->pluck('id');

    expect($matched)->toContain($ownGroupTherapy->id);
    expect($matched)->not->toContain($unrelatedGroupTherapy->id);
});
