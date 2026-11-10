<?php

use App\Enums\DiscussionStatusEnum;
use App\Models\Counsellor;
use App\Models\Discussion;
use App\Models\GroupTherapy;
use App\Models\Therapy;
use App\Models\User;

// Therapy chat page

test('a participant can view the therapy chat page', function () {
    $user = User::factory()->create();
    $counsellorUser = User::factory()->create();
    $counsellor = Counsellor::factory()->create(['user_id' => $counsellorUser->id]);
    $therapy = Therapy::factory()->create([
        'addedby_type' => User::class,
        'addedby_id' => $user->id,
        'counsellor_id' => $counsellor->id,
        'public' => false,
    ]);

    $response = $this
        ->actingAs($user)
        ->get(route('therapies.chat', ['therapyId' => $therapy->id]));

    $response
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Therapy/Chat')
            ->where('therapy.id', $therapy->id)
        );
});

test('a non-participant is redirected away from a non-public therapy chat page', function () {
    $addedbyUser = User::factory()->create();
    $therapy = Therapy::factory()->create([
        'addedby_type' => User::class,
        'addedby_id' => $addedbyUser->id,
        'public' => false,
    ]);

    $unrelatedUser = User::factory()->create();

    $response = $this
        ->actingAs($unrelatedUser)
        ->get(route('therapies.chat', ['therapyId' => $therapy->id]));

    $response->assertRedirect(route('home'));
});

test('an unauthenticated user is redirected to login from the therapy chat page', function () {
    $therapy = Therapy::factory()->create([
        'addedby_type' => User::class,
        'addedby_id' => User::factory(),
        'public' => false,
    ]);

    $response = $this->get(route('therapies.chat', ['therapyId' => $therapy->id]));

    $response->assertRedirect(route('login'));
});

// Group therapy chat page

test('a participant can view the group therapy chat page', function () {
    $addedbyUser = User::factory()->create();
    $groupTherapy = GroupTherapy::factory()->create([
        'addedby_type' => User::class,
        'addedby_id' => $addedbyUser->id,
        'public' => false,
    ]);

    $response = $this
        ->actingAs($addedbyUser)
        ->get(route('group.therapies.chat', ['groupTherapyId' => $groupTherapy->id]));

    $response
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('GroupTherapy/Chat')
            ->where('therapy.id', $groupTherapy->id)
        );
});

test('a non-participant is redirected away from a non-public group therapy chat page', function () {
    $addedbyUser = User::factory()->create();
    $groupTherapy = GroupTherapy::factory()->create([
        'addedby_type' => User::class,
        'addedby_id' => $addedbyUser->id,
        'public' => false,
    ]);

    $unrelatedUser = User::factory()->create();

    $response = $this
        ->actingAs($unrelatedUser)
        ->get(route('group.therapies.chat', ['groupTherapyId' => $groupTherapy->id]));

    $response->assertRedirect(route('home'));
});

test('an unauthenticated user is redirected to login from the group therapy chat page', function () {
    $groupTherapy = GroupTherapy::factory()->create([
        'addedby_type' => User::class,
        'addedby_id' => User::factory(),
        'public' => false,
    ]);

    $response = $this->get(route('group.therapies.chat', ['groupTherapyId' => $groupTherapy->id]));

    $response->assertRedirect(route('login'));
});

// Discussion chat page

test('a discussion counsellor can view the discussion chat page', function () {
    $counsellorUser = User::factory()->create();
    $counsellor = Counsellor::factory()->create(['user_id' => $counsellorUser->id]);
    $therapy = Therapy::factory()->create();
    $discussion = Discussion::factory()->create([
        'addedby_type' => Counsellor::class,
        'addedby_id' => $counsellor->id,
        'for_type' => Therapy::class,
        'for_id' => $therapy->id,
        'status' => DiscussionStatusEnum::in_session->value,
    ]);

    $response = $this
        ->actingAs($counsellorUser)
        ->get(route('discussions.chat', ['discussionId' => $discussion->id]));

    $response
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Discussion/Chat')
            ->where('discussion.id', $discussion->id)
        );
});

test('a counsellor unrelated to the discussion is denied access to the discussion chat page', function () {
    $addedbyCounsellorUser = User::factory()->create();
    $addedbyCounsellor = Counsellor::factory()->create(['user_id' => $addedbyCounsellorUser->id]);
    $therapy = Therapy::factory()->create();
    $discussion = Discussion::factory()->create([
        'addedby_type' => Counsellor::class,
        'addedby_id' => $addedbyCounsellor->id,
        'for_type' => Therapy::class,
        'for_id' => $therapy->id,
        'status' => DiscussionStatusEnum::in_session->value,
    ]);

    $unrelatedCounsellorUser = User::factory()->create();
    Counsellor::factory()->create(['user_id' => $unrelatedCounsellorUser->id]);

    $response = $this
        ->actingAs($unrelatedCounsellorUser)
        ->get(route('discussions.chat', ['discussionId' => $discussion->id]));

    $response->assertForbidden();
});

test('a non-counsellor user is denied access to the discussion chat page', function () {
    $addedbyCounsellorUser = User::factory()->create();
    $addedbyCounsellor = Counsellor::factory()->create(['user_id' => $addedbyCounsellorUser->id]);
    $therapy = Therapy::factory()->create();
    $discussion = Discussion::factory()->create([
        'addedby_type' => Counsellor::class,
        'addedby_id' => $addedbyCounsellor->id,
        'for_type' => Therapy::class,
        'for_id' => $therapy->id,
        'status' => DiscussionStatusEnum::in_session->value,
    ]);

    $plainUser = User::factory()->create();

    $response = $this
        ->actingAs($plainUser)
        ->get(route('discussions.chat', ['discussionId' => $discussion->id]));

    $response->assertForbidden();
});

test('an unauthenticated user is redirected to login from the discussion chat page', function () {
    $counsellorUser = User::factory()->create();
    $counsellor = Counsellor::factory()->create(['user_id' => $counsellorUser->id]);
    $therapy = Therapy::factory()->create();
    $discussion = Discussion::factory()->create([
        'addedby_type' => Counsellor::class,
        'addedby_id' => $counsellor->id,
        'for_type' => Therapy::class,
        'for_id' => $therapy->id,
        'status' => DiscussionStatusEnum::in_session->value,
    ]);

    $response = $this->get(route('discussions.chat', ['discussionId' => $discussion->id]));

    $response->assertRedirect(route('login'));
});
