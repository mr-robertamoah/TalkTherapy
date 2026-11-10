<?php

use App\Actions\GroupTherapy\UpdateGroupTherapyAction;
use App\DTOs\GroupTherapyDTO;
use App\Models\GroupTherapy;
use App\Models\User;

test('updating a group therapy persists an explicit maxCounsellors instead of silently dropping it (SCRUM-83)', function () {
    $groupTherapy = GroupTherapy::factory()->create([
        'addedby_type' => User::class,
        'addedby_id' => User::factory(),
        'max_counsellors' => 5,
    ]);

    $updated = UpdateGroupTherapyAction::new()->execute(GroupTherapyDTO::new()->fromArray([
        'groupTherapy' => $groupTherapy,
        'maxCounsellors' => 8,
    ]));

    expect($updated->max_counsellors)->toBe(8);
});
