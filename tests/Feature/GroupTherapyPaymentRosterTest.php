<?php

use App\Enums\ConstantsEnum;
use App\Enums\CounsellorGroupTherapyStateEnum;
use App\Enums\TransactionStatusEnum;
use App\Models\GroupTherapy;
use App\Models\Transaction;
use App\Models\User;

// TT-7.4d-d/SCRUM-261: GroupTherapyResource.paymentRoster -- a deliberate, scoped exception to
// this codebase's otherwise-universal anonymity rule, visible ONLY to this group's own counsellor.

function aPaidPerTherapyGroup(array $overrides = []): GroupTherapy
{
    return GroupTherapy::factory()->create(array_merge([
        'payment_type' => 'PAID',
        'payment_data' => ['per' => 'PER_THERAPY', 'amount' => 100, 'currency' => 'GHS'],
        'public' => true,
    ], $overrides));
}

test('this group\'s own counsellor sees the payment roster', function () {
    $counsellorUser = User::factory()->create();
    $counsellor = $counsellorUser->counsellor()->create(['email' => fake()->unique()->safeEmail(), 'about' => 'test']);
    $groupTherapy = aPaidPerTherapyGroup();
    $groupTherapy->counsellors()->attach($counsellor->id, ['state' => CounsellorGroupTherapyStateEnum::active->value, 'role' => 'NORMAL']);
    $member = User::factory()->create();
    $groupTherapy->users()->attach($member->id, ['anonymous' => false]);
    Transaction::factory()->create([
        'for_type' => GroupTherapy::class,
        'for_id' => $groupTherapy->id,
        'user_id' => $member->id,
        'status' => TransactionStatusEnum::success->value,
    ]);

    $response = $this->actingAs($counsellorUser)->get(route('group.therapies.get', ['groupTherapyId' => $groupTherapy->id]));

    $response->assertOk()->assertInertia(fn ($page) => $page
        ->where('therapy.paymentRoster.0.id', $member->id)
        ->where('therapy.paymentRoster.0.username', $member->username)
        ->where('therapy.paymentRoster.0.paymentStatus', TransactionStatusEnum::success->value)
    );
});

// The core privacy/authorization boundary this ticket must get right: NOBODY except this
// specific group's own counsellor may see the roster -- not a member (even a paying one), and not
// an unrelated counsellor who has nothing to do with this group.
test('a group member never sees the payment roster, even their own paid one', function () {
    $groupTherapy = aPaidPerTherapyGroup();
    $member = User::factory()->create();
    $groupTherapy->users()->attach($member->id, ['anonymous' => false]);
    Transaction::factory()->create([
        'for_type' => GroupTherapy::class,
        'for_id' => $groupTherapy->id,
        'user_id' => $member->id,
        'status' => TransactionStatusEnum::success->value,
    ]);

    $response = $this->actingAs($member)->get(route('group.therapies.get', ['groupTherapyId' => $groupTherapy->id]));

    $response->assertOk()->assertInertia(fn ($page) => $page
        ->missing('therapy.paymentRoster')
    );
});

test('a counsellor unrelated to this group never sees its payment roster', function () {
    $outsiderCounsellorUser = User::factory()->create();
    $outsiderCounsellorUser->counsellor()->create(['email' => fake()->unique()->safeEmail(), 'about' => 'test']);
    $groupTherapy = aPaidPerTherapyGroup();
    $member = User::factory()->create();
    $groupTherapy->users()->attach($member->id, ['anonymous' => false]);
    Transaction::factory()->create([
        'for_type' => GroupTherapy::class,
        'for_id' => $groupTherapy->id,
        'user_id' => $member->id,
        'status' => TransactionStatusEnum::success->value,
    ]);

    $response = $this->actingAs($outsiderCounsellorUser)->get(route('group.therapies.get', ['groupTherapyId' => $groupTherapy->id]));

    $response->assertOk()->assertInertia(fn ($page) => $page
        ->missing('therapy.paymentRoster')
    );
});

// The whole point of this ticket's own anonymity exception (product-owner sign-off, logged in
// decision-log.md): the roster shows REAL identity to the counsellor even when the group itself
// is anonymous -- unlike `addedby`, which stays masked for the same counsellor viewing the same
// response.
test('the roster shows real member identity to the counsellor even on an anonymous group', function () {
    $counsellorUser = User::factory()->create();
    $counsellor = $counsellorUser->counsellor()->create(['email' => fake()->unique()->safeEmail(), 'about' => 'test']);
    $memberAddedBy = User::factory()->create();
    $groupTherapy = aPaidPerTherapyGroup([
        'addedby_type' => User::class,
        'addedby_id' => $memberAddedBy->id,
        'anonymous' => true,
    ]);
    $groupTherapy->counsellors()->attach($counsellor->id, ['state' => CounsellorGroupTherapyStateEnum::active->value, 'role' => 'NORMAL']);
    $member = User::factory()->create();
    $groupTherapy->users()->attach($member->id, ['anonymous' => false]);
    Transaction::factory()->create([
        'for_type' => GroupTherapy::class,
        'for_id' => $groupTherapy->id,
        'user_id' => $member->id,
        'status' => TransactionStatusEnum::success->value,
    ]);

    $response = $this->actingAs($counsellorUser)->get(route('group.therapies.get', ['groupTherapyId' => $groupTherapy->id]));

    $response->assertOk()->assertInertia(fn ($page) => $page
        // The group's own addedby identity stays masked for the counsellor (pre-existing,
        // unaffected by this ticket) --
        ->where('therapy.addedby.fullName', ConstantsEnum::anonymousUserLabel->value)
        // -- but the roster's own member identity is real, per this ticket's own scoped exception.
        ->where('therapy.paymentRoster.0.fullName', $member->name)
        ->where('therapy.paymentRoster.0.username', $member->username)
    );
});

// PER_SESSION groups have no single group-wide payment status per member (payment happens per
// session, not per group) -- the roster is guarded out entirely rather than returning an
// incorrect/empty-looking result.
test('a PER_SESSION group never exposes a payment roster', function () {
    $counsellorUser = User::factory()->create();
    $counsellor = $counsellorUser->counsellor()->create(['email' => fake()->unique()->safeEmail(), 'about' => 'test']);
    $groupTherapy = aPaidPerTherapyGroup(['payment_data' => ['per' => 'PER_SESSION', 'amount' => 50, 'currency' => 'GHS']]);
    $groupTherapy->counsellors()->attach($counsellor->id, ['state' => CounsellorGroupTherapyStateEnum::active->value, 'role' => 'NORMAL']);

    $response = $this->actingAs($counsellorUser)->get(route('group.therapies.get', ['groupTherapyId' => $groupTherapy->id]));

    $response->assertOk()->assertInertia(fn ($page) => $page
        ->missing('therapy.paymentRoster')
    );
});

test('a counsellor whose assignment to this group has been deactivated never sees its payment roster', function () {
    $counsellorUser = User::factory()->create();
    $counsellor = $counsellorUser->counsellor()->create(['email' => fake()->unique()->safeEmail(), 'about' => 'test']);
    $groupTherapy = aPaidPerTherapyGroup();
    $groupTherapy->counsellors()->attach($counsellor->id, ['state' => CounsellorGroupTherapyStateEnum::inactive->value, 'role' => 'NORMAL']);
    $member = User::factory()->create();
    $groupTherapy->users()->attach($member->id, ['anonymous' => false]);
    Transaction::factory()->create([
        'for_type' => GroupTherapy::class,
        'for_id' => $groupTherapy->id,
        'user_id' => $member->id,
        'status' => TransactionStatusEnum::success->value,
    ]);

    $response = $this->actingAs($counsellorUser)->get(route('group.therapies.get', ['groupTherapyId' => $groupTherapy->id]));

    $response->assertOk()->assertInertia(fn ($page) => $page
        ->missing('therapy.paymentRoster')
    );
});

// Security review finding (user-confirmed): a member's own per-member anonymity opt-in
// (group_therapy_user.anonymous) must still be respected by the roster, independent of the
// group's own `anonymous` flag -- the approved exception only covers the latter.
test('the roster masks a member who individually opted into anonymity, even though the group itself is not anonymous', function () {
    $counsellorUser = User::factory()->create();
    $counsellor = $counsellorUser->counsellor()->create(['email' => fake()->unique()->safeEmail(), 'about' => 'test']);
    $groupTherapy = aPaidPerTherapyGroup(['anonymous' => false]);
    $groupTherapy->counsellors()->attach($counsellor->id, ['state' => CounsellorGroupTherapyStateEnum::active->value, 'role' => 'NORMAL']);
    $anonymousMember = User::factory()->create();
    $regularMember = User::factory()->create();
    $groupTherapy->users()->attach($anonymousMember->id, ['anonymous' => true]);
    $groupTherapy->users()->attach($regularMember->id, ['anonymous' => false]);
    Transaction::factory()->create([
        'for_type' => GroupTherapy::class,
        'for_id' => $groupTherapy->id,
        'user_id' => $anonymousMember->id,
        'status' => TransactionStatusEnum::success->value,
    ]);

    $response = $this->actingAs($counsellorUser)->get(route('group.therapies.get', ['groupTherapyId' => $groupTherapy->id]));

    $response->assertOk()->assertInertia(function ($page) use ($anonymousMember, $regularMember) {
        $roster = collect($page->toArray()['props']['therapy']['paymentRoster'])->keyBy('id');

        expect($roster[$anonymousMember->id]['fullName'])->toBe(ConstantsEnum::anonymousUserLabel->value)
            ->and($roster[$anonymousMember->id]['username'])->toBeNull()
            ->and($roster[$anonymousMember->id]['paymentStatus'])->toBe(TransactionStatusEnum::success->value)
            ->and($roster[$regularMember->id]['fullName'])->toBe($regularMember->name)
            ->and($roster[$regularMember->id]['username'])->toBe($regularMember->username);
    });
});

test('a FREE group never exposes a payment roster', function () {
    $counsellorUser = User::factory()->create();
    $counsellor = $counsellorUser->counsellor()->create(['email' => fake()->unique()->safeEmail(), 'about' => 'test']);
    $groupTherapy = GroupTherapy::factory()->create(['public' => true]);
    $groupTherapy->counsellors()->attach($counsellor->id, ['state' => CounsellorGroupTherapyStateEnum::active->value, 'role' => 'NORMAL']);

    $response = $this->actingAs($counsellorUser)->get(route('group.therapies.get', ['groupTherapyId' => $groupTherapy->id]));

    $response->assertOk()->assertInertia(fn ($page) => $page
        ->missing('therapy.paymentRoster')
    );
});
