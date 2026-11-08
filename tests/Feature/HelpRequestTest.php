<?php

use App\Actions\Guardianship\GetWardOpenTherapiesAction;
use App\Actions\Help\AcceptHelpRequestAction;
use App\Actions\Help\SweepHelpRequestsAction;
use App\Actions\Suspension\ApplySuspensionEffectsAction;
use App\Enums\HelpRequestStatusEnum;
use App\Events\HelpMessageSentEvent;
use App\Events\HelpRequestRingingEvent;
use App\Events\HelpRequestUpdatedEvent;
use App\Models\Counsellor;
use App\Models\EmergencyNumber;
use App\Models\GroupTherapy;
use App\Models\Guardianship;
use App\Models\HelpRequest;
use App\Models\Message;
use App\Models\Report;
use App\Models\Session;
use App\Models\Suspension;
use App\Models\Therapy;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Broadcasting\Broadcasters\Broadcaster;
use Illuminate\Broadcasting\BroadcastException;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Event;

// SCRUM-33: the quick help chat -- asking, ringing, the first-to-accept race, the chat, ending and the
// sweeps. Helpers are file-local and hlp-prefixed (--parallel).

function hlpOnCall(array $state = []): Counsellor
{
    return Counsellor::factory()->create(['user_id' => User::factory()->adult()->create()->id, 'urgent_help_available_until' => now()->addHours(2), ...$state]);
}

function hlpStart($test, User $person, array $input = []): array
{
    return $test->actingAs($person)->postJson(route('help.requests.store'), $input)->assertOk()->json('helpRequest');
}

function hlpSuspend(User $person): void
{
    $report = new Report(['description' => 'Something happened']);
    $report->addedby()->associate(User::factory()->adult()->create());
    $report->reportable()->associate(GroupTherapy::factory()->create());
    $report->save();
    Suspension::query()->forceCreate(['user_id' => $person->id, 'report_id' => $report->id, 'scope' => 'account', 'public_reason' => 'Conduct', 'starts_at' => now()->subMinute()]);
}

test('asking rings only on-call, verified, unsuspended counsellors -- never the person -- with ids only', function () {
    Event::fake([HelpRequestRingingEvent::class]);
    $onCall = hlpOnCall();
    hlpOnCall(['urgent_help_available_until' => now()->subMinute()]);
    Counsellor::factory()->unverified()->create(['user_id' => User::factory()->adult()->create()->id, 'urgent_help_available_until' => now()->addHour()]);
    $suspendedCounsellor = hlpOnCall();
    hlpSuspend($suspendedCounsellor->user);
    $asker = User::factory()->adult()->create();
    Counsellor::factory()->create(['user_id' => $asker->id, 'urgent_help_available_until' => now()->addHour()]);

    $help = hlpStart($this, $asker, ['note' => "  Can't   cope tonight  "]);

    expect($help['status'])->toBe('WAITING')->and(HelpRequest::query()->find($help['id'])->note)->toBe("Can't cope tonight");
    Event::assertDispatched(HelpRequestRingingEvent::class, function ($event) use ($onCall, $help) {
        return collect($event->broadcastOn())->map(fn ($channel) => $channel->name)->all() === ["private-counsellors.{$onCall->id}"]
            && $event->broadcastWith() === ['helpRequestId' => $help['id']];
    });
});

test('one live request per person: asking again returns the same one', function () {
    $person = User::factory()->adult()->create();

    $first = hlpStart($this, $person);
    $again = hlpStart($this, $person);

    expect($again['id'])->toBe($first['id'])->and(HelpRequest::query()->count())->toBe(1);
});

test('the first counsellor to accept is connected; a second is told someone answered; a free private help chat is created', function () {
    Event::fake([HelpRequestRingingEvent::class, HelpRequestUpdatedEvent::class, HelpMessageSentEvent::class]);
    $person = User::factory()->adult()->create();
    $first = hlpOnCall();
    $second = hlpOnCall();
    $help = hlpStart($this, $person, ['note' => 'Please help']);

    $this->actingAs($first->user)->postJson(route('help.requests.accept', ['helpRequest' => $help['id']]))->assertOk()->assertJsonPath('helpRequest.status', 'CONNECTED');
    $this->actingAs($second->user)->postJson(route('help.requests.accept', ['helpRequest' => $help['id']]))->assertStatus(409)
        ->assertJsonPath('message', AcceptHelpRequestAction::TAKEN);

    $request = HelpRequest::query()->find($help['id']);
    $therapy = Therapy::query()->find($request->therapy_id);
    $session = Session::query()->find($request->session_id);
    expect($request->counsellor_id)->toBe($first->id)
        ->and($therapy->is_help_chat)->toBeTrue()->and((bool) $therapy->public)->toBeFalse()->and($therapy->payment_type)->toBe('FREE')
        ->and($therapy->counsellor_id)->toBe($first->id)->and($therapy->addedby->is($person))->toBeTrue()
        ->and($therapy->allowsCameraFor($person))->toBeFalse()
        ->and($session->is_help_chat)->toBeTrue()->and($session->status)->toBe('IN_SESSION')
        // The note is the first message.
        ->and(Message::query()->where('for_id', $session->id)->where('for_type', Session::class)->pluck('content')->all())->toBe(['Please help']);
    // Help chats stay out of other lists.
    expect(Therapy::query()->withoutHelpChats()->whereKey($therapy->id)->exists())->toBeFalse();
});

test('the two of them chat; afterwards it is read-only for both; others never see it', function () {
    Event::fake([HelpRequestRingingEvent::class, HelpRequestUpdatedEvent::class, HelpMessageSentEvent::class]);
    $person = User::factory()->adult()->create(['firstName' => 'Ama']);
    $counsellor = hlpOnCall();
    $help = hlpStart($this, $person);
    $this->actingAs($counsellor->user)->postJson(route('help.requests.accept', ['helpRequest' => $help['id']]))->assertOk();

    $this->actingAs($person)->postJson(route('help.requests.messages.store', ['helpRequest' => $help['id']]), ['content' => 'Hello'])->assertOk();
    $this->actingAs($counsellor->user)->postJson(route('help.requests.messages.store', ['helpRequest' => $help['id']]), ['content' => 'I am here'])->assertOk();
    Event::assertDispatched(HelpMessageSentEvent::class, fn ($event) => array_keys($event->broadcastWith()) === ['helpRequestId', 'messageId']);

    $seenByCounsellor = $this->actingAs($counsellor->user)->getJson(route('help.requests.messages', ['helpRequest' => $help['id']]))->json('messages');
    expect(collect($seenByCounsellor)->map(fn ($m) => [$m['sender'], $m['content']])->all())->toBe([['Ama', 'Hello'], ['You', 'I am here']]);

    $this->actingAs($person)->postJson(route('help.requests.end', ['helpRequest' => $help['id']]))->assertOk()->assertJsonPath('helpRequest.status', 'ENDED');
    $this->actingAs($counsellor->user)->postJson(route('help.requests.messages.store', ['helpRequest' => $help['id']]), ['content' => 'Late'])->assertStatus(409);
    expect($this->actingAs($person)->getJson(route('help.requests.messages', ['helpRequest' => $help['id']]))->json('messages'))->toHaveCount(2);

    $outsider = User::factory()->adult()->create();
    $this->actingAs($outsider)->getJson(route('help.requests.messages', ['helpRequest' => $help['id']]))->assertStatus(404);
    $this->actingAs($outsider)->getJson(route('help.requests.messages', ['helpRequest' => 999999]))->assertStatus(404);

    // Free to ask again.
    expect(hlpStart($this, $person)['id'])->not->toBe($help['id']);
});

test('an anonymous person is "Anonymous" to the counsellor, who sees only the short summary', function () {
    Event::fake([HelpRequestRingingEvent::class]);
    $person = User::factory()->adult()->create(['firstName' => 'Secret', 'country' => 'Ghana']);
    $counsellor = hlpOnCall();
    $help = hlpStart($this, $person, ['anonymous' => true, 'note' => 'Scared']);

    $seen = $this->actingAs($counsellor->user)->getJson(route('help.requests.show', ['helpRequest' => $help['id']]))->assertOk()->json('helpRequest.person');

    expect($seen)->toBe(['name' => 'Anonymous', 'isMinor' => false, 'country' => 'Ghana', 'languages' => [], 'note' => 'Scared'])
        ->and(json_encode($seen))->not->toContain('Secret');
    // A counsellor who isn't on call can't look.
    $this->actingAs(Counsellor::factory()->create(['user_id' => User::factory()->adult()->create()->id])->user)
        ->getJson(route('help.requests.show', ['helpRequest' => $help['id']]))->assertStatus(404);
});

test('a suspended account can still ask (at a stricter limit), and isn\'t shown as suspended to the counsellor', function () {
    Event::fake([HelpRequestRingingEvent::class, HelpRequestUpdatedEvent::class]);
    config(['help.suspended_requests_per_hour' => 1]);
    $person = User::factory()->adult()->create();
    hlpSuspend($person);
    $counsellor = hlpOnCall();

    $help = hlpStart($this, $person);
    expect(HelpRequest::query()->find($help['id'])->was_suspended)->toBeTrue();
    $seen = $this->actingAs($counsellor->user)->getJson(route('help.requests.show', ['helpRequest' => $help['id']]))->json();
    expect(json_encode($seen))->not->toContain('uspend');

    $this->actingAs($person)->postJson(route('help.requests.cancel', ['helpRequest' => $help['id']]))->assertOk();
    $this->actingAs($person)->postJson(route('help.requests.store'))->assertStatus(429);
});

test('requests nobody answers expire, and chats past their time end, freeing the person to ask again', function () {
    Event::fake([HelpRequestRingingEvent::class, HelpRequestUpdatedEvent::class]);
    $waiting = User::factory()->adult()->create();
    $chatting = User::factory()->adult()->create();
    $stale = hlpStart($this, $waiting);
    $live = hlpStart($this, $chatting);
    $this->actingAs(hlpOnCall()->user)->postJson(route('help.requests.accept', ['helpRequest' => $live['id']]))->assertOk();

    $this->travel(config('help.session_minutes') + 1)->minutes();
    expect(SweepHelpRequestsAction::new()->execute())->toMatchArray(['expired' => 1, 'ended' => 1]);

    expect(HelpRequest::query()->find($stale['id'])->status)->toBe(HelpRequestStatusEnum::expired)
        ->and(HelpRequest::query()->find($live['id'])->status)->toBe(HelpRequestStatusEnum::ended)
        ->and(HelpRequest::query()->whereNotNull('active_user_id')->count())->toBe(0);
});

test('only a verified, unsuspended counsellor can go on call, and it lapses on its own', function () {
    $counsellor = Counsellor::factory()->create(['user_id' => User::factory()->adult()->create()->id]);
    $this->actingAs($counsellor->user)->postJson(route('help.availability'), ['available' => true])->assertOk();
    expect($counsellor->fresh()->isAvailableForUrgentHelp())->toBeTrue();

    $this->travel(config('help.availability_hours') + 1)->hours();
    expect($counsellor->fresh()->isAvailableForUrgentHelp())->toBeFalse();

    $unverified = Counsellor::factory()->unverified()->create(['user_id' => User::factory()->adult()->create()->id]);
    $this->actingAs($unverified->user)->postJson(route('help.availability'), ['available' => true])->assertStatus(422);
    $this->actingAs(User::factory()->adult()->create())->postJson(route('help.availability'), ['available' => true])->assertStatus(403);
});

test('the demo data seeds an on-call counsellor, a fake (non-production) number and an ended, read-only chat', function () {
    (new DatabaseSeeder)->createHelpDemoData();

    $user = User::query()->where('username', 'help_demo_user')->sole();
    $chat = HelpRequest::query()->where('user_id', $user->id)->sole();

    expect(Counsellor::query()->availableForUrgentHelp()->count())->toBe(2)
        ->and(EmergencyNumber::query()->where('country_code', 'GH')->value('label'))->toContain('TEST ONLY')
        ->and($chat->status)->toBe(HelpRequestStatusEnum::ended)
        ->and($this->actingAs($user)->getJson(route('help.requests.messages', ['helpRequest' => $chat->id]))->json('messages'))->toHaveCount(3);
});

test('if live updates fail (Reverb down), asking, answering and the sweep still work', function () {
    Broadcast::extend('down', fn () => new class extends Broadcaster
    {
        public function auth($request) {}

        public function validAuthenticationResponse($request, $result) {}

        public function broadcast(array $channels, $event, array $payload = [])
        {
            throw new BroadcastException('down');
        }
    });
    config(['broadcasting.connections.down' => ['driver' => 'down'], 'broadcasting.default' => 'down']);
    $person = User::factory()->adult()->create();
    $counsellor = hlpOnCall();

    $help = hlpStart($this, $person);
    $this->actingAs($counsellor->user)->postJson(route('help.requests.accept', ['helpRequest' => $help['id']]))->assertOk();
    $this->actingAs($person)->postJson(route('help.requests.messages.store', ['helpRequest' => $help['id']]), ['content' => 'Hi'])->assertOk();

    $waiting = hlpStart($this, User::factory()->adult()->create());
    $this->travel(config('help.session_minutes') + 1)->minutes();
    expect(SweepHelpRequestsAction::new()->execute())->toMatchArray(['expired' => 1, 'ended' => 1])
        ->and(HelpRequest::query()->find($waiting['id'])->status)->toBe(HelpRequestStatusEnum::expired);
});

function hlpConnected($test, ?User $person = null, ?Counsellor $counsellor = null): array
{
    Event::fake([HelpRequestRingingEvent::class, HelpRequestUpdatedEvent::class, HelpMessageSentEvent::class]);
    $person ??= User::factory()->adult()->create();
    $counsellor ??= hlpOnCall();
    $help = hlpStart($test, $person);
    $test->actingAs($counsellor->user)->postJson(route('help.requests.accept', ['helpRequest' => $help['id']]))->assertOk();

    return [$person, $counsellor, HelpRequest::query()->find($help['id'])];
}

test('a help chat can\'t be reached or changed through the ordinary therapy, session and message routes', function () {
    [$person, $counsellor, $help] = hlpConnected($this);
    $guardian = User::factory()->adult()->create();
    Guardianship::query()->create(['guardian_id' => $guardian->id, 'ward_id' => $person->id]);

    // A guardian, or anyone else, can't see it; its participants are sent to the help chat.
    $this->actingAs($guardian)->get(route('therapies.get', ['therapyId' => $help->therapy_id]))->assertRedirect(route('home'));
    $this->actingAs($person)->get(route('therapies.get', ['therapyId' => $help->therapy_id]))->assertRedirect(route('help.chat', ['helpRequest' => $help->id]));

    // The person can't make it public (or change it at all); nobody writes in it the ordinary way.
    $this->actingAs($person)->patchJson(route('api.therapies.update', ['therapyId' => $help->therapy_id]), ['public' => true])->assertStatus(422);
    expect((bool) $help->therapy->fresh()->public)->toBeFalse();
    $this->actingAs($counsellor->user)->postJson(route('api.messages.create'), [
        'content' => 'Generic', 'forType' => 'session', 'forId' => $help->session_id, 'fromType' => 'counsellor', 'fromId' => $counsellor->id,
    ])->assertStatus(422);

    // Outsiders can't read its messages through the ordinary route either.
    $this->actingAs($person)->postJson(route('help.requests.messages.store', ['helpRequest' => $help->id]), ['content' => 'Private words'])->assertOk();
    $outsider = $this->actingAs(User::factory()->adult()->create())->getJson(route('api.session.messages.get', ['sessionId' => $help->session_id]));
    expect($outsider->getContent())->not->toContain('Private words');
});

test('a counsellor suspended mid-chat is taken out of it; the chat ends for both and they can\'t write', function () {
    [$person, $counsellor, $help] = hlpConnected($this);
    $report = new Report(['description' => 'Conduct']);
    $report->addedby()->associate(User::factory()->adult()->create());
    $report->reportable()->associate($help->therapy);
    $report->save();
    $suspension = Suspension::query()->forceCreate(['user_id' => $counsellor->user_id, 'report_id' => $report->id, 'scope' => 'counsellor', 'public_reason' => 'Conduct', 'starts_at' => now()->subMinute()]);

    ApplySuspensionEffectsAction::new()->execute($suspension);

    expect($help->fresh()->status)->toBe(HelpRequestStatusEnum::ended)
        ->and($help->fresh()->ended_by)->toBe('suspension')
        ->and($counsellor->fresh()->isAvailableForUrgentHelp())->toBeFalse();
    $this->actingAs($counsellor->user)->postJson(route('help.requests.messages.store', ['helpRequest' => $help->id]), ['content' => 'Still here'])->assertStatus(409);
});

test('a guardian who is a counsellor on call is never rung for their ward, and only a rung counsellor can accept', function () {
    Event::fake([HelpRequestRingingEvent::class, HelpRequestUpdatedEvent::class]);
    $ward = User::factory()->create(['dob' => now()->subYears(15)->toDateString()]);
    $guardianCounsellor = hlpOnCall();
    Guardianship::query()->create(['guardian_id' => $guardianCounsellor->user_id, 'ward_id' => $ward->id]);
    $other = hlpOnCall();

    $help = hlpStart($this, $ward);

    Event::assertDispatched(HelpRequestRingingEvent::class, fn ($event) => collect($event->broadcastOn())->map(fn ($c) => $c->name)->all() === ["private-counsellors.{$other->id}"]);
    $this->actingAs($guardianCounsellor->user)->getJson(route('help.requests.show', ['helpRequest' => $help['id']]))->assertStatus(404);
    // The same answer as for a request that doesn't exist.
    $this->actingAs($guardianCounsellor->user)->postJson(route('help.requests.accept', ['helpRequest' => $help['id']]))->assertStatus(409);
    $this->actingAs($guardianCounsellor->user)->postJson(route('help.requests.accept', ['helpRequest' => 999999]))->assertStatus(409);
    // A counsellor not on call (and not rung directly) gets the same answer, whether it exists or not.
    $offCall = Counsellor::factory()->create(['user_id' => User::factory()->adult()->create()->id]);
    $this->actingAs($offCall->user)->postJson(route('help.requests.accept', ['helpRequest' => $help['id']]))->assertStatus(409);
    $this->actingAs($offCall->user)->postJson(route('help.requests.accept', ['helpRequest' => 999999]))->assertStatus(409);
    // An unverified one is refused for their own state, whatever the id.
    $unverified = Counsellor::factory()->unverified()->create(['user_id' => User::factory()->adult()->create()->id]);
    $this->actingAs($unverified->user)->postJson(route('help.requests.accept', ['helpRequest' => $help['id']]))->assertStatus(422);
    $this->actingAs($unverified->user)->postJson(route('help.requests.accept', ['helpRequest' => 999999]))->assertStatus(422);
});

test('help chats stay out of the public feed and a ward\'s open therapies', function () {
    [$person, $counsellor, $help] = hlpConnected($this);
    $help->therapy->forceFill(['public' => true])->save();

    expect(Therapy::query()->publicForOutsider(User::factory()->adult()->create())->whereKey($help->therapy_id)->exists())->toBeFalse()
        ->and(GetWardOpenTherapiesAction::new()->execute($person)['therapies'])->toBeEmpty()
        ->and($counsellor->fresh()->sessions_count)->toBe(0);
});

test('a counsellor who comes on call (or reloads) sees who is already waiting; others don\'t', function () {
    Event::fake([HelpRequestRingingEvent::class]);
    $waiting = hlpStart($this, User::factory()->adult()->create());
    $counsellor = hlpOnCall();

    expect(collect($this->actingAs($counsellor->user)->getJson(route('help.requests.ringing'))->assertOk()->json('helpRequests'))->pluck('id')->all())
        ->toBe([$waiting['id']]);
    $offCall = Counsellor::factory()->create(['user_id' => User::factory()->adult()->create()->id]);
    expect($this->actingAs($offCall->user)->getJson(route('help.requests.ringing'))->json('helpRequests'))->toBe([]);
});

test('the order of things: cancelling after an accept, accepting after a cancel, and a busy counsellor', function () {
    [$person, $counsellor, $help] = hlpConnected($this);

    $this->actingAs($person)->postJson(route('help.requests.cancel', ['helpRequest' => $help->id]))->assertStatus(409);

    // Busy in one chat: can't take another.
    $another = hlpStart($this, User::factory()->adult()->create());
    $this->actingAs($counsellor->user)->postJson(route('help.requests.accept', ['helpRequest' => $another['id']]))->assertStatus(409);

    // Cancelled: nobody can take it.
    $cancelled = hlpStart($this, $asker = User::factory()->adult()->create());
    $this->actingAs($asker)->postJson(route('help.requests.cancel', ['helpRequest' => $cancelled['id']]))->assertOk();
    $this->actingAs(hlpOnCall()->user)->postJson(route('help.requests.accept', ['helpRequest' => $cancelled['id']]))->assertStatus(409);
});

test('a suspended person can open their help chat and its channel -- and only that channel', function () {
    [$person, $counsellor, $help] = hlpConnected($this);
    hlpSuspend($person);
    $person = $person->fresh();

    $this->actingAs($person)->get(route('help.chat', ['helpRequest' => $help->id]))->assertOk();
    $this->actingAs($person)->post('/broadcasting/auth', ['socket_id' => '1.1', 'channel_name' => "private-help-requests.{$help->id}"])->assertOk();
    $this->actingAs($person)->postJson('/broadcasting/auth', ['socket_id' => '1.1', 'channel_name' => "private-users.{$person->id}"])->assertStatus(403);
    $this->actingAs($person)->postJson('/broadcasting/auth', ['socket_id' => '1.1', 'channel_name' => "private-help-requests.{$help->id}x"])->assertStatus(403);
    // Text only: audio isn't offered to a suspended account.
    expect($this->actingAs($person)->getJson(route('help.requests.show', ['helpRequest' => $help->id]))->json('helpRequest.audioPossible'))->toBeFalse();
});

test('a minor\'s chat is text only, and says audio needs a guardian\'s consent', function () {
    [$minor, $counsellor, $help] = hlpConnected($this, User::factory()->create(['dob' => now()->subYears(15)->toDateString()]));

    expect($this->actingAs($minor)->getJson(route('help.requests.show', ['helpRequest' => $help->id]))->json('helpRequest'))
        ->toMatchArray(['audioPossible' => false, 'audioUnavailable' => 'minor']);
});
