<?php

use App\Http\Resources\AdminUserResource;
use App\Models\AdminContentAccess;
use App\Models\Administrator;
use App\Models\Counsellor;
use App\Models\Language;
use App\Models\Message;
use App\Models\Report;
use App\Models\Session;
use App\Models\Suspension;
use App\Models\Therapy;
use App\Models\User;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\DB;

// SCRUM-364/365/366 + SCRUM-209/205/199/192/271.

function mhTherapy(bool $public = false): array
{
    $client = User::factory()->adult()->create();
    $counsellor = Counsellor::factory()->create(['user_id' => User::factory()->adult()->create()->id]);
    $therapy = Therapy::factory()->create(['addedby_type' => User::class, 'addedby_id' => $client->id, 'counsellor_id' => $counsellor->id, 'public' => $public]);
    $session = Session::factory()->create(['for_id' => $therapy->id, 'for_type' => Therapy::class, 'status' => 'HELD']);

    return compact('client', 'counsellor', 'therapy', 'session');
}

function mhMessage(array $t, string $content = 'hello', array $extra = []): Message
{
    return Message::factory()->create([
        'for_id' => $t['session']->id, 'for_type' => Session::class,
        'from_type' => User::class, 'from_id' => $t['client']->id,
        'content' => $content, 'confidential' => false, 'type' => 'NORMAL', ...$extra,
    ]);
}

test('an announced message is fetched by a participant, refused to an outsider', function () {
    $t = mhTherapy();
    $message = mhMessage($t, 'live message');

    $this->actingAs($t['counsellor']->user)->getJson(route('api.messages.show', ['messageId' => $message->id]))
        ->assertOk()->assertJsonPath('data.content', 'live message');
    $this->actingAs(User::factory()->create())->getJson(route('api.messages.show', ['messageId' => $message->id]))
        ->assertNotFound();
});

test('a suspended participant can not fetch an announced message', function () {
    $t = mhTherapy();
    $message = mhMessage($t, 'live message');
    $report = new Report(['description' => 'x', 'data' => []]);
    $report->addedby()->associate($t['client']);
    $report->save();
    Suspension::create(['user_id' => $t['counsellor']->user_id, 'report_id' => $report->id, 'scope' => 'account', 'starts_at' => now(), 'public_reason' => 'For the test.']);

    $this->actingAs($t['counsellor']->user)->getJson(route('api.messages.show', ['messageId' => $message->id]))
        ->assertForbidden()->assertJsonPath('code', 'account_suspended');
});

test('a client who has not paid a strict-gated therapy can not fetch its live messages (SCRUM-271)', function () {
    $client = User::factory()->adult()->create();
    $counsellor = Counsellor::factory()->create(['user_id' => User::factory()->adult()->create()->id]);
    $therapy = Therapy::factory()->create([
        'addedby_type' => User::class, 'addedby_id' => $client->id, 'counsellor_id' => $counsellor->id, 'public' => false,
        'payment_type' => 'PAID',
        'payment_data' => ['per' => 'PER_THERAPY', 'amount' => 50, 'currency' => 'GHS', 'inPersonAmount' => 60, 'strictPaymentGate' => true],
    ]);
    $session = Session::factory()->create(['for_id' => $therapy->id, 'for_type' => Therapy::class, 'status' => 'HELD']);
    $message = Message::factory()->create([
        'for_id' => $session->id, 'for_type' => Session::class,
        'from_type' => Counsellor::class, 'from_id' => $counsellor->id,
        'content' => 'paid content', 'confidential' => false, 'type' => 'NORMAL',
    ]);

    $this->actingAs($client)->getJson(route('api.messages.show', ['messageId' => $message->id]))->assertNotFound();
});

test('an admin who could only see a message as an admin is kept off its channel; a party is not', function () {
    $t = mhTherapy();
    $message = mhMessage($t);
    $admin = User::factory()->has(Administrator::factory())->create();
    $rule = Broadcast::getChannels()->get('messages.{messageId}');

    expect($rule($admin, $message->id))->toBeFalse()
        ->and((bool) $rule($t['counsellor']->user, $message->id))->toBeTrue();
});

test('topic messages can be narrowed to one session (no session_id column involved)', function () {
    $t = mhTherapy();
    $other = Session::factory()->create(['for_id' => $t['therapy']->id, 'for_type' => Therapy::class, 'status' => 'HELD']);
    $topic = $t['therapy']->topics()->create(['name' => 'Sleep', 'description' => 'About sleep', 'counsellor_id' => $t['counsellor']->id]);
    $topic->sessions()->attach([$t['session']->id, $other->id]);
    $inFirst = mhMessage($t, 'first', ['therapy_topic_id' => $topic->id]);
    mhMessage(['session' => $other, 'client' => $t['client']], 'second', ['therapy_topic_id' => $topic->id]);

    $ids = collect($this->actingAs($t['client'])->getJson(route('api.topic.messages.get', ['topicId' => $topic->id, 'sessionId' => $t['session']->id]))->assertOk()->json('data'))->pluck('id');

    expect($ids->all())->toBe([$inFirst->id]);
});

test('a session\'s message list loads files and replies in batches, not one query per message', function () {
    $t = mhTherapy();
    $first = mhMessage($t);
    foreach (range(1, 6) as $i) {
        mhMessage($t, "reply {$i}", ['message_id' => $first->id]);
    }

    $count = function () use ($t) {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($t['client'])->getJson(route('api.session.messages.get', ['sessionId' => $t['session']->id]))->assertOk();

        return collect(DB::getQueryLog())->filter(fn ($q) => str_contains($q['query'], '"fileables"') || preg_match('/from "messages" where "messages"."id" =/', $q['query']))->count();
    };

    expect($count())->toBeLessThanOrEqual(2);
});

test('session status, topic and note changes and image uploads are rate limited', function () {
    $routes = app('router')->getRoutes();

    foreach (['api.sessions.end', 'api.sessions.in_session', 'api.sessions.fail', 'api.sessions.abandon', 'api.session.topic.set', 'api.session.topic.unset', 'api.session.notes.store', 'api.session.notes.update', 'api.session.notes.destroy', 'organizations.update', 'counsellor.update', 'profile.avatar.update'] as $name) {
        expect(collect($routes->getByName($name)->gatherMiddleware())->contains(fn ($m) => str_starts_with($m, 'throttle:')))->toBeTrue($name);
    }
});

test('reference-data search matches % and _ literally', function () {
    Language::factory()->create(['name' => '100% Twi']);
    Language::factory()->create(['name' => 'Ewe']);

    $response = $this->getJson(route('languages.get', ['name' => '%']))->assertOk();
    $names = collect($response->json('data') ?? $response->json())->pluck('name');

    expect($names->all())->toBe(['100% Twi']);
});

test('the read log can be narrowed to one subject, and admins in the user list link to theirs', function () {
    $t = mhTherapy();
    $admin = User::factory()->has(Administrator::factory())->create();
    $this->actingAs($admin)->getJson(route('api.session.messages.get', ['sessionId' => $t['session']->id]))->assertOk();
    $this->actingAs($admin)->getJson(route('api.therapies.get', ['therapyId' => $t['therapy']->id]))->assertOk();

    $this->actingAs($admin)->get(route('administrator.content-accesses', ['subject' => 'session', 'subject_id' => $t['session']->id]))->assertOk()
        ->assertInertia(fn ($page) => $page->has('accesses.data', 1)->where('accesses.data.0.resource', AdminContentAccess::SESSION_MESSAGES));

    expect((new AdminUserResource($admin->load('administrator')))->resolve()['isAdmin'])->toBeTrue();
});
