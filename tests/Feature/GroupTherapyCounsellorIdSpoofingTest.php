<?php

use App\Actions\Request\CreateRequestAction;
use App\Actions\Request\RespondToGuardianshipRequestAction;
use App\DTOs\CreateRequestDTO;
use App\DTOs\RequestResponseDTO;
use App\Enums\RequestTypeEnum;
use App\Models\Counsellor;
use App\Models\GroupTherapy;
use App\Models\User;

// SCRUM-296: GroupTherapyController::createGroupTherapy used to build its DTO's 'counsellor' via
// an unchecked Counsellor::find($request->counsellorId) -- CreateGroupTherapyAction treats a
// present 'counsellor' as the group's addedby/owner, so any authenticated caller could attribute
// a group therapy to a Counsellor they don't own by supplying an arbitrary id. This is
// particularly consequential for SCRUM-290's client_was_minor_at_creation snapshot: a minor with
// a guardian legitimately passes EnsureCanCreateTherapyAction, and forcing 'counsellor' to a
// value would make the snapshot null ("not applicable") instead of true.

function aGroupTherapyCreatePayload(array $overrides = []): array
{
    return array_merge([
        'name' => 'Spoofing Regression Group',
        'about' => 'A group for testing counsellorId trust boundaries',
        'anonymous' => false,
        'allowInPerson' => false,
        'allowAnyone' => false,
        'public' => true,
        'sessionType' => 'ONCE',
        'paymentType' => 'FREE',
    ], $overrides);
}

test('a plain user cannot attribute a created group therapy to a counsellor they do not own by supplying an arbitrary counsellorId', function () {
    $attacker = User::factory()->create(['dob' => now()->subYears(30)->toDateString()]);
    $unrelatedCounsellorUser = User::factory()->create();
    $unrelatedCounsellor = Counsellor::factory()->create(['user_id' => $unrelatedCounsellorUser->id]);

    $response = $this->actingAs($attacker)
        ->postJson(route('group.therapies.create'), aGroupTherapyCreatePayload([
            'counsellorId' => $unrelatedCounsellor->id,
        ]));

    $response->assertSuccessful();

    $therapy = GroupTherapy::query()->latest('id')->first();

    expect($therapy->addedby_type)->toBe(User::class);
    expect($therapy->addedby_id)->toBe($attacker->id);
});

test('a minor with a guardian cannot use a spoofed counsellorId to force client_was_minor_at_creation to null', function () {
    $guardian = User::factory()->create(['dob' => now()->subYears(30)->toDateString()]);
    $minor = User::factory()->create(['dob' => now()->subYears(15)->toDateString()]);

    $request = CreateRequestAction::new()->execute(
        CreateRequestDTO::new()->fromArray([
            'from' => $minor,
            'to' => $guardian,
            'for' => $minor,
            'type' => RequestTypeEnum::guardianship->value,
        ])
    );

    RespondToGuardianshipRequestAction::new()->execute(RequestResponseDTO::new()->fromArray([
        'user' => $guardian,
        'response' => 'accepted',
        'request' => $request,
    ]));

    $unrelatedCounsellorUser = User::factory()->create();
    $unrelatedCounsellor = Counsellor::factory()->create(['user_id' => $unrelatedCounsellorUser->id]);

    $response = $this->actingAs($minor)
        ->postJson(route('group.therapies.create'), aGroupTherapyCreatePayload([
            'counsellorId' => $unrelatedCounsellor->id,
        ]));

    $response->assertSuccessful();

    $therapy = GroupTherapy::query()->latest('id')->first();

    expect($therapy->addedby_type)->toBe(User::class);
    expect($therapy->client_was_minor_at_creation)->toBeTrue();
});

test('a counsellor creating a group therapy under their own counsellor identity is still attributed to their own counsellor profile', function () {
    $counsellorUser = User::factory()->create(['dob' => now()->subYears(30)->toDateString()]);
    $counsellor = Counsellor::factory()->create(['user_id' => $counsellorUser->id]);

    $response = $this->actingAs($counsellorUser)
        ->postJson(route('group.therapies.create'), aGroupTherapyCreatePayload([
            'counsellorId' => $counsellor->id,
        ]));

    $response->assertSuccessful();

    $therapy = GroupTherapy::query()->latest('id')->first();

    expect($therapy->addedby_type)->toBe(Counsellor::class);
    expect($therapy->addedby_id)->toBe($counsellor->id);
    expect($therapy->client_was_minor_at_creation)->toBeNull();
});
