<?php

use App\DTOs\CreateMessageDTO;
use App\Events\MessageSentEvent;
use App\Exceptions\MessageException;
use App\Http\Resources\MessageResource;
use App\Models\Administrator;
use App\Models\Counsellor;
use App\Models\Discussion;
use App\Models\File;
use App\Models\GroupTherapy;
use App\Models\Message;
use App\Models\MessageNote;
use App\Models\Session;
use App\Models\User;
use App\Services\MessageService;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Storage;

// SCRUM-195: confidential messages must never reach anyone but their parties -- not through a
// shared broadcast channel, and not on page load via the (previously broken) isParty() check.

function cmbGroupSessionWithConfidentialMessage(bool $anonymous = false): array
{
    $creator = User::factory()->adult()->create();
    $groupTherapy = GroupTherapy::factory()->create([
        'addedby_type' => User::class,
        'addedby_id' => $creator->id,
        'anonymous' => $anonymous,
        // Private: a public group's content is readable by any signed-in user (still redacted).
        'public' => false,
    ]);
    $counsellorUser = User::factory()->create();
    $counsellor = Counsellor::factory()->create(['user_id' => $counsellorUser->id]);
    $groupTherapy->counsellors()->attach($counsellor->id, ['state' => 'ACTIVE', 'role' => 'NORMAL']);
    $member = User::factory()->adult()->create();
    $groupTherapy->users()->attach($member->id, ['anonymous' => $anonymous]);
    $session = Session::factory()->create([
        'for_id' => $groupTherapy->id,
        'for_type' => GroupTherapy::class,
        'status' => 'IN_SESSION',
    ]);

    // From the member to the counsellor -- the creator is in the session but not a party.
    $message = Message::factory()->create([
        'for_id' => $session->id,
        'for_type' => Session::class,
        'from_type' => User::class,
        'from_id' => $member->id,
        'to_type' => Counsellor::class,
        'to_id' => $counsellor->id,
        'content' => 'something private for the counsellor',
        'confidential' => true,
    ]);

    // Someone unrelated to this group who has sent a message of their own elsewhere -- the old
    // isParty() treated anyone like this as a party to EVERY message.
    $outsider = User::factory()->create();
    $elsewhere = Session::factory()->create();
    Message::factory()->create(['from_type' => User::class, 'from_id' => $outsider->id, 'for_id' => $elsewhere->id, 'for_type' => Session::class]);

    return compact('creator', 'groupTherapy', 'counsellorUser', 'counsellor', 'member', 'session', 'message', 'outsider', 'elsewhere');
}

function cmbChannelCallback(string $pattern): Closure
{
    $channels = (function () {
        return $this->channels;
    })->call(Broadcast::connection());

    expect($channels)->toHaveKey($pattern);

    return $channels[$pattern];
}

test('isParty is about this message only', function () {
    $data = cmbGroupSessionWithConfidentialMessage();

    expect($data['message']->isParty($data['member']))->toBeTrue()
        ->and($data['message']->isParty($data['counsellorUser']))->toBeTrue()
        ->and($data['message']->isParty($data['creator']))->toBeFalse()
        ->and($data['message']->isParty($data['outsider']))->toBeFalse()
        ->and($data['message']->isParty(null))->toBeFalse();
});

test('on page load a non-party never sees confidential content, even one with messages of their own', function () {
    $data = cmbGroupSessionWithConfidentialMessage();

    foreach ([$data['creator'], $data['outsider']] as $viewer) {
        expect(MessageResource::present($data['message'], $viewer))->not->toHaveKey('content');
    }
});

test('the shared-channel broadcast never carries a confidential message\'s content', function () {
    $data = cmbGroupSessionWithConfidentialMessage();

    $payload = (new MessageSentEvent($data['message']))->broadcastWith()['message'];

    expect($payload)->not->toHaveKeys(['content', 'files', 'replying'])
        ->and($payload['confidential'])->toBeTruthy()
        ->and($payload['toUserId'])->toBe($data['counsellorUser']->id);
});

test('a non-confidential broadcast still carries its content', function () {
    $data = cmbGroupSessionWithConfidentialMessage();
    $data['message']->update(['confidential' => false]);

    $payload = (new MessageSentEvent($data['message']->fresh()))->broadcastWith()['message'];

    expect($payload['content'])->toBe('something private for the counsellor')
        ->and($payload['fromUserId'])->toBe($data['member']->id);
});

test('the broadcast never carries an anonymous sender\'s user id', function () {
    $data = cmbGroupSessionWithConfidentialMessage(anonymous: true);
    $data['message']->update(['confidential' => false]);

    $payload = (new MessageSentEvent($data['message']->fresh()))->broadcastWith()['message'];

    expect($payload['fromUserId'])->toBeNull();
});

test('a message\'s own channel admits the session\'s participants but not outsiders', function () {
    $data = cmbGroupSessionWithConfidentialMessage();
    $callback = cmbChannelCallback('messages.{messageId}');

    expect($callback($data['member'], $data['message']->id))->toBeTrue()
        ->and($callback($data['counsellorUser'], $data['message']->id))->toBeTrue()
        ->and($callback($data['creator'], $data['message']->id))->toBeTrue()
        ->and($callback($data['outsider'], $data['message']->id))->toBeFalse()
        ->and($callback($data['member'], 999999))->toBeFalse();
});

test('the recipient fetches the full confidential message; other participants get the redacted one', function () {
    $data = cmbGroupSessionWithConfidentialMessage();
    $url = route('api.messages.show', $data['message']->id);

    $this->actingAs($data['counsellorUser'])->getJson($url)
        ->assertOk()
        ->assertJsonPath('data.content', 'something private for the counsellor');

    $this->actingAs($data['creator'])->getJson($url)
        ->assertOk()
        ->assertJsonMissingPath('data.content');
});

test('an outsider or a guest cannot fetch a message at all', function () {
    $data = cmbGroupSessionWithConfidentialMessage();
    $url = route('api.messages.show', $data['message']->id);

    $this->getJson($url)->assertUnauthorized();
    $this->actingAs($data['outsider'])->getJson($url)->assertNotFound();
    $this->actingAs($data['outsider'])->getJson(route('api.messages.show', 999999))->assertNotFound();
});

// --- Security-review follow-ups (SCRUM-195) ---

test('isParty and recipientUserId hold in the counsellor-to-member direction too', function () {
    $data = cmbGroupSessionWithConfidentialMessage();
    $reply = Message::factory()->create([
        'for_id' => $data['session']->id, 'for_type' => Session::class,
        'from_type' => Counsellor::class, 'from_id' => $data['counsellor']->id,
        'to_type' => User::class, 'to_id' => $data['member']->id,
    ]);

    expect($reply->isParty($data['counsellorUser']))->toBeTrue()
        ->and($reply->isParty($data['member']))->toBeTrue()
        ->and($reply->recipientUserId())->toBe($data['member']->id)
        ->and($data['message']->recipientUserId())->toBe($data['counsellorUser']->id);
});

test('a reply can only point at a message in the same conversation', function () {
    $data = cmbGroupSessionWithConfidentialMessage();
    $foreign = Message::factory()->create(['for_id' => $data['elsewhere']->id, 'for_type' => Session::class, 'content' => 'someone else\'s secret']);

    expect(fn () => MessageService::new()->createMessage(CreateMessageDTO::new()->fromArray([
        'user' => $data['member'],
        'from' => $data['member'],
        'for' => $data['session'],
        'content' => 'hi',
        'reply' => $foreign,
    ])))->toThrow(MessageException::class, 'You can only reply to a message in the same conversation.');
});

test('replying to a confidential message never re-publishes its content to non-parties', function () {
    $data = cmbGroupSessionWithConfidentialMessage();
    $reply = Message::factory()->create([
        'for_id' => $data['session']->id, 'for_type' => Session::class,
        'from_type' => Counsellor::class, 'from_id' => $data['counsellor']->id,
        'message_id' => $data['message']->id,
        'confidential' => false,
        'content' => 'a public reply',
    ]);

    $broadcast = (new MessageSentEvent($reply))->broadcastWith()['message'];
    $forCreator = MessageResource::present($reply, $data['creator']);
    $forMember = MessageResource::present($reply, $data['member']);

    expect($broadcast['replying'])->not->toHaveKey('content')
        ->and($forCreator['replying'])->not->toHaveKey('content')
        ->and($forMember['replying']['content'])->toBe('something private for the counsellor');
});

test('editing a confidential message without the flag keeps it confidential', function () {
    $data = cmbGroupSessionWithConfidentialMessage();

    $this->actingAs($data['member'])
        ->postJson(route('api.messages.update', $data['message']->id), ['content' => 'edited'])
        ->assertSuccessful();

    expect((bool) $data['message']->fresh()->confidential)->toBeTrue()
        ->and($data['message']->fresh()->content)->toBe('edited');
});

test('an edit can only delete the message\'s own files', function () {
    Storage::fake('local');
    $data = cmbGroupSessionWithConfidentialMessage();
    $otherFile = File::factory()->create(['storage' => 'local', 'path' => 'messages', 'name' => 'someone-elses.jpg']);
    Storage::disk('local')->put('messages/someone-elses.jpg', 'x');

    $this->actingAs($data['member'])
        ->postJson(route('api.messages.update', $data['message']->id), ['content' => 'edited', 'deletedFiles' => [$otherFile->id]])
        ->assertSuccessful();

    expect(File::find($otherFile->id))->not->toBeNull()
        ->and(Storage::disk('local')->exists('messages/someone-elses.jpg'))->toBeTrue();
});

test('an anonymous recipient\'s id is masked for everyone but themselves', function () {
    $data = cmbGroupSessionWithConfidentialMessage(anonymous: true);
    $toMember = Message::factory()->create([
        'for_id' => $data['session']->id, 'for_type' => Session::class,
        'from_type' => Counsellor::class, 'from_id' => $data['counsellor']->id,
        'to_type' => User::class, 'to_id' => $data['member']->id,
        'confidential' => true,
    ]);

    $broadcast = (new MessageSentEvent($toMember))->broadcastWith()['message'];

    expect($broadcast['toUserId'])->toBeNull()
        ->and($broadcast['recipientMasked'])->toBeTrue()
        ->and(MessageResource::present($toMember, $data['creator'])['toUserId'])->toBeNull()
        ->and(MessageResource::present($toMember, $data['member'])['toUserId'])->toBe($data['member']->id);
});

test('an admin gets the redacted view of a confidential message', function () {
    $data = cmbGroupSessionWithConfidentialMessage();
    $admin = User::factory()->has(Administrator::factory())->create();

    $this->actingAs($admin)->getJson(route('api.messages.show', $data['message']->id))
        ->assertOk()
        ->assertJsonMissingPath('data.content');
});

test('a discussion message\'s channel admits its counsellors only', function () {
    $owner = Counsellor::factory()->create(['user_id' => User::factory()->create()->id]);
    $stranger = Counsellor::factory()->create(['user_id' => User::factory()->create()->id]);
    $discussion = Discussion::factory()->create(['addedby_type' => Counsellor::class, 'addedby_id' => $owner->id]);
    $message = Message::factory()->create([
        'for_id' => $discussion->id, 'for_type' => Discussion::class,
        'from_type' => Counsellor::class, 'from_id' => $owner->id,
    ]);
    $callback = cmbChannelCallback('messages.{messageId}');

    expect($callback($owner->user, $message->id))->toBeTrue()
        ->and($callback($stranger->user, $message->id))->toBeFalse();
});

test('a broadcast never carries a counsellor\'s private note, even if notes were loaded', function () {
    $data = cmbGroupSessionWithConfidentialMessage();
    $data['message']->update(['confidential' => false]);
    MessageNote::factory()->create(['message_id' => $data['message']->id, 'counsellor_id' => $data['counsellor']->id]);

    $payload = (new MessageSentEvent($data['message']->fresh()->load('notes')))->broadcastWith()['message'];

    expect($payload)->not->toHaveKey('note');
});
