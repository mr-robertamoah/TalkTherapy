<?php

use App\Enums\RequestTypeEnum;
use App\Http\Resources\RequestResource;
use App\Models\Counsellor;
use App\Models\GroupTherapy;
use App\Models\Request as AppRequest;
use App\Models\Therapy;
use App\Models\User;
use App\Notifications\GroupTherapyAssistanceRequestSentNotification;
use App\Notifications\GroupTherapyMembershipRequestAcceptedGuardianNotification;
use App\Notifications\GroupTherapyMembershipRequestAcceptedNotification;
use App\Notifications\GroupTherapyMembershipRequestSentNotification;
use App\Notifications\TherapyAssistanceRequestAcceptedGuardianNotification;
use App\Notifications\TherapyAssistanceRequestAcceptedNotification;
use App\Notifications\TherapyAssistanceRequestSentNotification;
use App\Notifications\TherapyCreatedNotification;
use Illuminate\Http\Request;

// SCRUM-57: no notification about an anonymous therapy or group ever names the anonymous person --
// by mail or broadcast. SCRUM-55/56: who is masked follows who sent a request, and each user's own
// anonymity (isAnonymousFor), not just the group flag.

function aDistinctUserFor57(string $first = 'Zephyrine', string $last = 'Quillfeather'): User
{
    return User::factory()->create(['firstName' => $first, 'lastName' => $last, 'username' => strtolower($first).'_secret_'.fake()->unique()->numberBetween(1, 99999)]);
}

function mailTextFor57($notification, $notifiable): string
{
    return (string) $notification->toMail($notifiable)->render();
}

function expectNoNameFor57(string $text, User $user): void
{
    expect($text)->not->toContain($user->firstName)->not->toContain($user->lastName)->not->toContain($user->username);
}

function aCounsellorFor57(): Counsellor
{
    return Counsellor::factory()->create(['user_id' => User::factory()->create()->id]);
}

function aRequestFor57(array $attributes): AppRequest
{
    return AppRequest::query()->forceCreate(['status' => 'PENDING', 'data' => [], ...$attributes])->fresh();
}

test('a guardian is never told who created an anonymous therapy', function () {
    $client = aDistinctUserFor57();
    $guardian = User::factory()->create();
    $anonymous = Therapy::factory()->create(['addedby_type' => User::class, 'addedby_id' => $client->id, 'anonymous' => true]);
    $named = Therapy::factory()->create(['addedby_type' => User::class, 'addedby_id' => $client->id, 'anonymous' => false]);

    expectNoNameFor57(mailTextFor57(new TherapyCreatedNotification($anonymous), $guardian), $client);
    // Sanity: the same notification does name them when the therapy isn't anonymous.
    expect(mailTextFor57(new TherapyCreatedNotification($named), $guardian))->toContain('Zephyrine');
});

test('a counsellor whose offer an anonymous client accepts never learns who they are -- by mail or broadcast', function () {
    $client = aDistinctUserFor57();
    $counsellor = aCounsellorFor57();
    $therapy = Therapy::factory()->create(['addedby_type' => User::class, 'addedby_id' => $client->id, 'counsellor_id' => $counsellor->id, 'anonymous' => true]);
    $request = aRequestFor57([
        'from_type' => Counsellor::class, 'from_id' => $counsellor->id,
        'to_type' => User::class, 'to_id' => $client->id,
        'for_type' => Therapy::class, 'for_id' => $therapy->id, 'type' => RequestTypeEnum::therapy->value,
    ]);
    $notification = new TherapyAssistanceRequestAcceptedNotification($request);

    expectNoNameFor57(mailTextFor57($notification, $counsellor), $client);
    expectNoNameFor57(json_encode($notification->toBroadcast($counsellor)->data), $client);
    expect($notification->toBroadcast($counsellor)->data['to'])->toBe('an anonymous user');
});

test('a guardian is never told which anonymous ward\'s request was accepted', function () {
    $client = aDistinctUserFor57();
    $therapy = Therapy::factory()->create(['addedby_type' => User::class, 'addedby_id' => $client->id, 'counsellor_id' => aCounsellorFor57()->id, 'anonymous' => true]);

    expectNoNameFor57(mailTextFor57(new TherapyAssistanceRequestAcceptedGuardianNotification($therapy), User::factory()->create()), $client);
});

test('SCRUM-55: a request sent by a user never names them -- even on a non-anonymous therapy -- while a counsellor sender is named', function () {
    $client = aDistinctUserFor57();
    $counsellor = aCounsellorFor57();
    $therapy = Therapy::factory()->create(['addedby_type' => User::class, 'addedby_id' => $client->id, 'anonymous' => false]);
    $group = GroupTherapy::factory()->create(['addedby_type' => User::class, 'addedby_id' => $client->id, 'anonymous' => false]);

    foreach ([[TherapyAssistanceRequestSentNotification::class, $therapy, RequestTypeEnum::therapy->value], [GroupTherapyAssistanceRequestSentNotification::class, $group, RequestTypeEnum::groupTherapy->value]] as [$class, $for, $type]) {
        $fromUser = aRequestFor57(['from_type' => User::class, 'from_id' => $client->id, 'to_type' => Counsellor::class, 'to_id' => $counsellor->id, 'for_type' => $for::class, 'for_id' => $for->id, 'type' => $type]);
        expectNoNameFor57(mailTextFor57(new $class($fromUser), $counsellor), $client);

        $fromCounsellor = aRequestFor57(['from_type' => Counsellor::class, 'from_id' => $counsellor->id, 'to_type' => User::class, 'to_id' => $client->id, 'for_type' => $for::class, 'for_id' => $for->id, 'type' => $type]);
        expect(mailTextFor57(new $class($fromCounsellor), $client))->toContain($counsellor->user->lastName);
    }
});

test('SCRUM-56: a member who chose anonymity, and an anonymous group\'s creator, are never named in membership emails', function () {
    $creator = aDistinctUserFor57('Creatorine', 'Hiddenworth');
    $requester = aDistinctUserFor57('Requestina', 'Maskwell');
    $group = GroupTherapy::factory()->create(['addedby_type' => User::class, 'addedby_id' => $creator->id, 'anonymous' => false]);

    // The requester chose to join anonymously -- the creator isn't told who asked.
    $membership = aRequestFor57(['from_type' => User::class, 'from_id' => $requester->id, 'to_type' => User::class, 'to_id' => $creator->id, 'for_type' => GroupTherapy::class, 'for_id' => $group->id, 'type' => RequestTypeEnum::groupTherapyMembership->value, 'data' => ['anonymous' => true]]);
    expectNoNameFor57(mailTextFor57(new GroupTherapyMembershipRequestSentNotification($membership), $creator), $requester);

    // A creator never gets a group_therapy_user row through the app today (they're a participant via
    // isUser()), so this state is an invariant guard: masking must follow isAnonymousFor($creator),
    // the same rule as addedByUserIsMaskedFor(), whatever sets it.
    // The creator is anonymous in their own group by their own choice (pivot), not the group flag.
    $group->users()->attach($creator->id, ['anonymous' => true]);
    $group = $group->fresh();
    expectNoNameFor57(mailTextFor57(new GroupTherapyMembershipRequestAcceptedNotification($membership->fresh()), $requester), $creator);
    expectNoNameFor57(mailTextFor57(new GroupTherapyMembershipRequestAcceptedGuardianNotification($group), User::factory()->create()), $creator);

    // And when the whole group is anonymous.
    $anonymousGroup = GroupTherapy::factory()->create(['addedby_type' => User::class, 'addedby_id' => $creator->id, 'anonymous' => true]);
    expectNoNameFor57(mailTextFor57(new GroupTherapyMembershipRequestAcceptedGuardianNotification($anonymousGroup), User::factory()->create()), $creator);
});

test('SCRUM-56: a group assistance acceptance respects the creator\'s own anonymity choice', function () {
    $creator = aDistinctUserFor57();
    $counsellor = aCounsellorFor57();
    $group = GroupTherapy::factory()->create(['addedby_type' => User::class, 'addedby_id' => $creator->id, 'anonymous' => false]);
    // A creator never gets a group_therapy_user row through the app today (they're a participant via
    // isUser()), so this state is an invariant guard: masking must follow isAnonymousFor($creator),
    // the same rule as addedByUserIsMaskedFor(), whatever sets it.
    $group->users()->attach($creator->id, ['anonymous' => true]);
    $request = aRequestFor57(['from_type' => Counsellor::class, 'from_id' => $counsellor->id, 'to_type' => User::class, 'to_id' => $creator->id, 'for_type' => GroupTherapy::class, 'for_id' => $group->id, 'type' => RequestTypeEnum::groupTherapy->value]);
    $notification = new TherapyAssistanceRequestAcceptedNotification($request);

    expectNoNameFor57(mailTextFor57($notification, $counsellor), $creator);
    expect($notification->toBroadcast($counsellor)->data['to'])->toBe('an anonymous user');
});

test('SCRUM-56: a membership request never shows an anonymous-by-choice creator to the requester', function () {
    $creator = aDistinctUserFor57('Creatorine', 'Hiddenworth');
    $requester = User::factory()->create();
    $group = GroupTherapy::factory()->create(['addedby_type' => User::class, 'addedby_id' => $creator->id, 'anonymous' => false]);
    $group->users()->attach($creator->id, ['anonymous' => true]);
    $membership = aRequestFor57(['from_type' => User::class, 'from_id' => $requester->id, 'to_type' => User::class, 'to_id' => $creator->id, 'for_type' => GroupTherapy::class, 'for_id' => $group->id, 'type' => RequestTypeEnum::groupTherapyMembership->value]);

    $seen = (new RequestResource($membership))->resolve(Request::create('/')->setUserResolver(fn () => $requester));
    expect($seen['to']['id'])->toBeNull()
        ->and(json_encode($seen))->not->toContain('Creatorine');
});

test('positive controls: when nobody is anonymous, the accepting user and the group creator ARE named', function () {
    $client = aDistinctUserFor57();
    $counsellor = aCounsellorFor57();
    $therapy = Therapy::factory()->create(['addedby_type' => User::class, 'addedby_id' => $client->id, 'counsellor_id' => $counsellor->id, 'anonymous' => false]);
    $accepted = new TherapyAssistanceRequestAcceptedNotification(aRequestFor57([
        'from_type' => Counsellor::class, 'from_id' => $counsellor->id,
        'to_type' => User::class, 'to_id' => $client->id,
        'for_type' => Therapy::class, 'for_id' => $therapy->id, 'type' => RequestTypeEnum::therapy->value,
    ]));
    expect(mailTextFor57($accepted, $counsellor))->toContain('Zephyrine')
        ->and($accepted->toBroadcast($counsellor)->data['to'])->toBe($client->name);

    $creator = aDistinctUserFor57('Creatorine', 'Hiddenworth');
    $requester = User::factory()->create();
    $group = GroupTherapy::factory()->create(['addedby_type' => User::class, 'addedby_id' => $creator->id, 'anonymous' => false]);
    $membership = aRequestFor57(['from_type' => User::class, 'from_id' => $requester->id, 'to_type' => User::class, 'to_id' => $creator->id, 'for_type' => GroupTherapy::class, 'for_id' => $group->id, 'type' => RequestTypeEnum::groupTherapyMembership->value]);
    expect(mailTextFor57(new GroupTherapyMembershipRequestAcceptedNotification($membership), $requester))->toContain('Creatorine')
        ->and(mailTextFor57(new GroupTherapyMembershipRequestAcceptedGuardianNotification($group->fresh()), User::factory()->create()))->toContain('Creatorine');
});

test('an acceptance by a since-deleted user names no one and doesn\'t crash', function () {
    $client = aDistinctUserFor57();
    $counsellor = aCounsellorFor57();
    $therapy = Therapy::factory()->create(['addedby_type' => User::class, 'addedby_id' => $client->id, 'counsellor_id' => $counsellor->id, 'anonymous' => false]);
    $request = aRequestFor57([
        'from_type' => Counsellor::class, 'from_id' => $counsellor->id,
        'to_type' => User::class, 'to_id' => 999999,
        'for_type' => Therapy::class, 'for_id' => $therapy->id, 'type' => RequestTypeEnum::therapy->value,
    ]);
    $notification = new TherapyAssistanceRequestAcceptedNotification($request);

    expect(mailTextFor57($notification, $counsellor))->toContain('an anonymous user')
        ->and($notification->toBroadcast($counsellor)->data['to'])->toBe('an anonymous user');
});
