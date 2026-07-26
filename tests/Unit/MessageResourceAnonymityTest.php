<?php

use App\DTOs\CreateMessageDTO;
use App\Enums\DiscussionStatusEnum;
use App\Exceptions\MessageException;
use App\Http\Resources\MessageResource;
use App\Models\Counsellor;
use App\Models\Discussion;
use App\Models\GroupTherapy;
use App\Models\Message;
use App\Models\Session;
use App\Models\Therapy;
use App\Models\User;
use App\Services\MessageService;

function resourceArrayAsViewer(Message $message, ?User $viewer): array
{
    $fakeRequest = request();
    $fakeRequest->setUserResolver(fn () => $viewer);

    return (new MessageResource($message->fresh()))->toArray($fakeRequest);
}

describe('MessageResource anonymity masking -- individual therapy', function () {
    test('masks the sender for a non-owner viewer when the therapy is anonymous', function () {
        $client = User::factory()->create();
        $counsellorUser = User::factory()->create();
        $counsellor = Counsellor::factory()->create(['user_id' => $counsellorUser->id]);
        $therapy = Therapy::factory()->create([
            'addedby_type' => User::class,
            'addedby_id' => $client->id,
            'counsellor_id' => $counsellor->id,
            'anonymous' => true,
        ]);
        $session = Session::factory()->create(['for_id' => $therapy->id, 'for_type' => Therapy::class]);
        $message = Message::factory()->create([
            'from_id' => $client->id,
            'from_type' => User::class,
            'to_id' => $counsellor->id,
            'to_type' => Counsellor::class,
            'for_id' => $session->id,
            'for_type' => Session::class,
        ]);

        $array = resourceArrayAsViewer($message, $counsellorUser);

        expect($array['fromUserId'])->toBeNull();
    });

    test('does not mask the sender when the sender views their own anonymous message', function () {
        $client = User::factory()->create();
        $counsellorUser = User::factory()->create();
        $counsellor = Counsellor::factory()->create(['user_id' => $counsellorUser->id]);
        $therapy = Therapy::factory()->create([
            'addedby_type' => User::class,
            'addedby_id' => $client->id,
            'counsellor_id' => $counsellor->id,
            'anonymous' => true,
        ]);
        $session = Session::factory()->create(['for_id' => $therapy->id, 'for_type' => Therapy::class]);
        $message = Message::factory()->create([
            'from_id' => $client->id,
            'from_type' => User::class,
            'to_id' => $counsellor->id,
            'to_type' => Counsellor::class,
            'for_id' => $session->id,
            'for_type' => Session::class,
        ]);

        $array = resourceArrayAsViewer($message, $client);

        expect($array['fromUserId'])->toBe($client->id);
    });

    test('does not mask a counsellor sender even when the therapy is anonymous', function () {
        $client = User::factory()->create();
        $counsellorUser = User::factory()->create();
        $counsellor = Counsellor::factory()->create(['user_id' => $counsellorUser->id]);
        $therapy = Therapy::factory()->create([
            'addedby_type' => User::class,
            'addedby_id' => $client->id,
            'counsellor_id' => $counsellor->id,
            'anonymous' => true,
        ]);
        $session = Session::factory()->create(['for_id' => $therapy->id, 'for_type' => Therapy::class]);
        $message = Message::factory()->create([
            'from_id' => $counsellor->id,
            'from_type' => Counsellor::class,
            'to_id' => $client->id,
            'to_type' => User::class,
            'for_id' => $session->id,
            'for_type' => Session::class,
        ]);

        $array = resourceArrayAsViewer($message, $client);

        expect($array['fromUserId'])->toBe($counsellorUser->id);
    });

    test('does not mask the sender when the therapy is not anonymous', function () {
        $client = User::factory()->create();
        $counsellorUser = User::factory()->create();
        $counsellor = Counsellor::factory()->create(['user_id' => $counsellorUser->id]);
        $therapy = Therapy::factory()->create([
            'addedby_type' => User::class,
            'addedby_id' => $client->id,
            'counsellor_id' => $counsellor->id,
            'anonymous' => false,
        ]);
        $session = Session::factory()->create(['for_id' => $therapy->id, 'for_type' => Therapy::class]);
        $message = Message::factory()->create([
            'from_id' => $client->id,
            'from_type' => User::class,
            'to_id' => $counsellor->id,
            'to_type' => Counsellor::class,
            'for_id' => $session->id,
            'for_type' => Session::class,
        ]);

        $array = resourceArrayAsViewer($message, $counsellorUser);

        expect($array['fromUserId'])->toBe($client->id);
    });

    test('masks the sender on a soft-deleted anonymous message too', function () {
        $client = User::factory()->create();
        $counsellorUser = User::factory()->create();
        $counsellor = Counsellor::factory()->create(['user_id' => $counsellorUser->id]);
        $therapy = Therapy::factory()->create([
            'addedby_type' => User::class,
            'addedby_id' => $client->id,
            'counsellor_id' => $counsellor->id,
            'anonymous' => true,
        ]);
        $session = Session::factory()->create(['for_id' => $therapy->id, 'for_type' => Therapy::class]);
        $message = Message::factory()->create([
            'from_id' => $client->id,
            'from_type' => User::class,
            'to_id' => $counsellor->id,
            'to_type' => Counsellor::class,
            'for_id' => $session->id,
            'for_type' => Session::class,
        ]);
        $message->delete();

        $array = resourceArrayAsViewer($message, $counsellorUser);

        expect($array['status'])->toBe('deleted for everyone');
        expect($array['fromUserId'])->toBeNull();
    });
});

describe('MessageResource anonymity masking -- group therapy', function () {
    test('masks the sender for a non-owner viewer when only the member\'s own pivot is anonymous', function () {
        $anonymousMember = User::factory()->create();
        $otherMember = User::factory()->create();
        $groupTherapy = GroupTherapy::factory()->create(['anonymous' => false]);
        $groupTherapy->users()->attach($anonymousMember->id, ['anonymous' => true]);
        $groupTherapy->users()->attach($otherMember->id, ['anonymous' => false]);
        $session = Session::factory()->create(['for_id' => $groupTherapy->id, 'for_type' => GroupTherapy::class]);
        $message = Message::factory()->create([
            'from_id' => $anonymousMember->id,
            'from_type' => User::class,
            'for_id' => $session->id,
            'for_type' => Session::class,
        ]);

        $array = resourceArrayAsViewer($message, $otherMember);

        expect($array['fromUserId'])->toBeNull();
    });

    test('does not mask the sender when they view their own anonymous group message', function () {
        $anonymousMember = User::factory()->create();
        $groupTherapy = GroupTherapy::factory()->create(['anonymous' => false]);
        $groupTherapy->users()->attach($anonymousMember->id, ['anonymous' => true]);
        $session = Session::factory()->create(['for_id' => $groupTherapy->id, 'for_type' => GroupTherapy::class]);
        $message = Message::factory()->create([
            'from_id' => $anonymousMember->id,
            'from_type' => User::class,
            'for_id' => $session->id,
            'for_type' => Session::class,
        ]);

        $array = resourceArrayAsViewer($message, $anonymousMember);

        expect($array['fromUserId'])->toBe($anonymousMember->id);
    });

    test('does not mask when neither the group nor the member pivot is anonymous', function () {
        $member = User::factory()->create();
        $otherMember = User::factory()->create();
        $groupTherapy = GroupTherapy::factory()->create(['anonymous' => false]);
        $groupTherapy->users()->attach($member->id, ['anonymous' => false]);
        $session = Session::factory()->create(['for_id' => $groupTherapy->id, 'for_type' => GroupTherapy::class]);
        $message = Message::factory()->create([
            'from_id' => $member->id,
            'from_type' => User::class,
            'for_id' => $session->id,
            'for_type' => Session::class,
        ]);

        $array = resourceArrayAsViewer($message, $otherMember);

        expect($array['fromUserId'])->toBe($member->id);
    });
});

// Documents the confirmed invariant (see EnsureCanSendMessageToForAction::validateForDiscussion())
// that a Discussion message can only ever come from a Counsellor, never a client User -- so
// MessageResource deliberately never applies anonymity masking to Discussion messages at all.
test('a client User can never successfully send a Discussion message (from_type is never User::class for a Discussion)', function () {
    $client = User::factory()->create();
    $counsellorUser = User::factory()->create();
    $counsellor = Counsellor::factory()->create(['user_id' => $counsellorUser->id]);
    $therapy = Therapy::factory()->create([
        'addedby_type' => User::class,
        'addedby_id' => $client->id,
        'counsellor_id' => $counsellor->id,
    ]);
    $discussion = Discussion::factory()->create([
        'addedby_id' => $counsellor->id,
        'addedby_type' => Counsellor::class,
        'for_id' => $therapy->id,
        'for_type' => Therapy::class,
        'status' => DiscussionStatusEnum::in_session->value,
    ]);

    expect(fn () => MessageService::new()->createMessage(
        CreateMessageDTO::new()->fromArray([
            'from' => $client,
            'user' => $client,
            'for' => $discussion,
            'content' => 'hello',
        ])
    ))->toThrow(MessageException::class);

    $this->assertDatabaseMissing('messages', [
        'for_type' => Discussion::class,
        'from_type' => User::class,
    ]);
});
