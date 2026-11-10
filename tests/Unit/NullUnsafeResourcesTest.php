<?php

use App\Enums\MessageStatusEnum;
use App\Enums\MessageTypeEnum;
use App\Enums\RequestStatusEnum;
use App\Enums\RequestTypeEnum;
use App\Http\Resources\AdminCounsellorResource;
use App\Http\Resources\AdminCounsellorVerificationRequestResource;
use App\Http\Resources\CommentResource;
use App\Http\Resources\CounsellorMiniResource;
use App\Http\Resources\DiscussionMiniResource;
use App\Http\Resources\DiscussionResource;
use App\Http\Resources\GroupTherapyMiniResource;
use App\Http\Resources\LicenseResource;
use App\Http\Resources\MessageResource;
use App\Http\Resources\PublicTherapyResource;
use App\Http\Resources\TestimonialResource;
use App\Http\Resources\TherapyResource;
use App\Http\Resources\UserMiniResource;
use App\Models\Comment;
use App\Models\Counsellor;
use App\Models\Discussion;
use App\Models\GroupTherapy;
use App\Models\Message;
use App\Models\Request as RequestModel;
use App\Models\Session;
use App\Models\Testimonial;
use App\Models\Therapy;
use App\Models\User;

test('CommentResource does not crash when the comment author has deleted their account', function () {
    $author = User::factory()->create();
    $therapy = Therapy::factory()->create([
        'addedby_type' => User::class,
        'addedby_id' => User::factory(),
    ]);
    $comment = Comment::query()->forceCreate([
        'user_id' => $author->id,
        'commentable_id' => $therapy->id,
        'commentable_type' => Therapy::class,
        'content' => 'a comment',
    ]);
    $author->delete();

    $array = (new CommentResource($comment->fresh()))->toArray(request());

    expect($array['username'])->toBe($author->username);
});

test('AdminCounsellorResource does not crash when the counsellor\'s linked user has deleted their account', function () {
    $user = User::factory()->create();
    $counsellor = Counsellor::factory()->create(['user_id' => $user->id]);
    $user->delete();

    $array = (new AdminCounsellorResource($counsellor->fresh()))->toArray(request());

    expect($array['username'])->toBe($user->username);
});

test('AdminCounsellorVerificationRequestResource does not crash when the counsellor has deleted their account', function () {
    $user = User::factory()->create();
    $counsellor = Counsellor::factory()->create(['user_id' => $user->id]);
    $verificationRequest = RequestModel::query()->forceCreate([
        'data' => [],
        'type' => RequestTypeEnum::counsellor->value,
        'status' => RequestStatusEnum::pending->value,
        'from_id' => $counsellor->id,
        'from_type' => Counsellor::class,
    ]);
    $counsellor->delete();

    $array = (new AdminCounsellorVerificationRequestResource($verificationRequest->fresh()))->toArray(request());

    expect($array['counsellor']['id'])->toBe($counsellor->id);
});

test('LicenseResource renders as an empty array when given a null model', function () {
    expect((new LicenseResource(null))->toArray(request()))->toBe([]);
});

test('CounsellorMiniResource renders a deleted placeholder when given a null model', function () {
    expect((new CounsellorMiniResource(null))->toArray(request()))
        ->toBe(['deleted' => true, 'isCounsellor' => true]);
});

test('UserMiniResource renders a deleted placeholder when given a null model', function () {
    expect((new UserMiniResource(null))->toArray(request()))
        ->toBe(['deleted' => true, 'isUser' => true]);
});

test('DiscussionResource and DiscussionMiniResource do not crash when addedby has deleted their account', function () {
    $addedbyUser = User::factory()->create();
    $therapy = Therapy::factory()->create([
        'addedby_type' => User::class,
        'addedby_id' => User::factory(),
    ]);
    $discussion = Discussion::factory()->create([
        'addedby_id' => $addedbyUser->id,
        'addedby_type' => User::class,
        'for_id' => $therapy->id,
        'for_type' => Therapy::class,
    ]);
    $addedbyUser->delete();

    $discussion = $discussion->fresh();

    expect((new DiscussionResource($discussion))->toArray(request())['addedby'])
        ->toBeInstanceOf(UserMiniResource::class);
    expect((new DiscussionMiniResource($discussion))->toArray(request())['addedby'])
        ->toBeInstanceOf(UserMiniResource::class);
});

test('GroupTherapyMiniResource and PublicTherapyResource do not crash when addedby has deleted their account', function () {
    $addedbyUser = User::factory()->create();
    // anonymous: false -- this test is about null-safety around a deleted addedby, not
    // anonymity masking (see AnonymityMaskingTest for that), so keep the two independent.
    $groupTherapy = GroupTherapy::factory()->create([
        'addedby_type' => User::class,
        'addedby_id' => $addedbyUser->id,
        'anonymous' => false,
    ]);
    $addedbyUser->delete();

    $groupTherapy = $groupTherapy->fresh();

    $miniArray = (new GroupTherapyMiniResource($groupTherapy))->toArray(request());
    expect($miniArray['userId'])->toBe($addedbyUser->id);

    $publicArray = (new PublicTherapyResource($groupTherapy))->toArray(request());
    expect($publicArray['userId'])->toBe($addedbyUser->id);
});

test('TherapyResource does not crash when addedby has deleted their account', function () {
    $addedbyUser = User::factory()->create();
    $therapy = Therapy::factory()->create([
        'addedby_type' => User::class,
        'addedby_id' => $addedbyUser->id,
        'anonymous' => false,
    ]);
    $addedbyUser->delete();

    $viewer = User::factory()->create();
    $fakeRequest = request();
    $fakeRequest->setUserResolver(fn () => $viewer);

    $array = (new TherapyResource($therapy->fresh()))->toArray($fakeRequest);

    expect($array['user'])->toBeInstanceOf(UserMiniResource::class);
});

test('MessageResource still renders correctly when the counsellor party has deleted their account', function () {
    // Message::from()/to() and Counsellor::user() already resolve withTrashed(), so $counsellor
    // here is the (trashed) Counsellor object, not null -- this exercises the relation-level
    // fix, not the ?-> null-guards below (see the next test for that).
    $counsellorUser = User::factory()->create();
    $counsellor = Counsellor::factory()->create(['user_id' => $counsellorUser->id]);
    $recipient = User::factory()->create();

    $message = Message::factory()->create([
        'from_id' => $counsellor->id,
        'from_type' => Counsellor::class,
        'to_id' => $recipient->id,
        'to_type' => User::class,
        'for_id' => 1,
        'for_type' => Session::class,
        'type' => MessageTypeEnum::normal->value,
        'status' => MessageStatusEnum::sent->value,
    ]);
    $counsellorUser->delete();
    $counsellor->delete();

    $fakeRequest = request();
    $fakeRequest->setUserResolver(fn () => $recipient);

    $array = (new MessageResource($message->fresh()))->toArray($fakeRequest);

    expect($array)->toHaveKey('counsellorAvatar');
    expect($array['counsellorAvatar'])->toBeNull();
});

test('MessageResource does not crash when the recipient of a discussion message is not a counsellor', function () {
    // Genuine null case for the $this->to?->counsellor ?-> chain: the recipient is a plain
    // User with no linked Counsellor at all, unrelated to any soft-delete.
    $fromUser = User::factory()->create();
    $toUser = User::factory()->create();
    $therapy = Therapy::factory()->create([
        'addedby_type' => User::class,
        'addedby_id' => User::factory(),
    ]);
    $discussion = Discussion::factory()->create([
        'addedby_id' => $fromUser->id,
        'addedby_type' => User::class,
        'for_id' => $therapy->id,
        'for_type' => Therapy::class,
    ]);

    $message = Message::factory()->create([
        'from_id' => $fromUser->id,
        'from_type' => User::class,
        'to_id' => $toUser->id,
        'to_type' => User::class,
        'for_id' => $discussion->id,
        'for_type' => Discussion::class,
        'type' => MessageTypeEnum::normal->value,
        'status' => MessageStatusEnum::sent->value,
    ]);

    $fakeRequest = request();
    $fakeRequest->setUserResolver(fn () => $toUser);

    $array = (new MessageResource($message->fresh()))->toArray($fakeRequest);

    expect($array['counsellorAvatar'])->toBeNull();
    expect($array['counsellorName'])->toBeNull();
});

test('TestimonialResource does not crash when addedby has deleted their account', function () {
    $addedbyUser = User::factory()->create();
    $testimonial = Testimonial::query()->forceCreate([
        'addedby_id' => $addedbyUser->id,
        'addedby_type' => User::class,
        'content' => 'great service',
        'use' => true,
    ]);
    $addedbyUser->delete();

    $array = (new TestimonialResource($testimonial->fresh()))->toArray(request());

    expect($array['addedby'])->toBeInstanceOf(UserMiniResource::class);
});
