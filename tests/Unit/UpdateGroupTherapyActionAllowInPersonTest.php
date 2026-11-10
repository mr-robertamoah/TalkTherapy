<?php

use App\Actions\GroupTherapy\UpdateGroupTherapyAction;
use App\DTOs\GroupTherapyDTO;
use App\Models\GroupTherapy;
use App\Models\User;

test('updating a group therapy persists an explicit allowInPerson instead of silently dropping it (SCRUM-86)', function () {
    $groupTherapy = GroupTherapy::factory()->create([
        'addedby_type' => User::class,
        'addedby_id' => User::factory(),
        'allow_in_person' => false,
    ]);

    $updated = UpdateGroupTherapyAction::new()->execute(GroupTherapyDTO::new()->fromArray([
        'groupTherapy' => $groupTherapy,
        'allowInPerson' => true,
    ]));

    expect((bool) $updated->allow_in_person)->toBeTrue();
});
