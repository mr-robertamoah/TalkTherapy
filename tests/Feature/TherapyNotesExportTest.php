<?php

use App\Enums\AdministratorTypeEnum;
use App\Enums\CounsellorGroupTherapyStateEnum;
use App\Enums\MessageTypeEnum;
use App\Enums\SuspensionScopeEnum;
use App\Enums\TherapyStatusEnum;
use App\Http\Resources\GroupTherapyResource;
use App\Http\Resources\TherapyResource;
use App\Models\Administrator;
use App\Models\Counsellor;
use App\Models\GroupTherapy;
use App\Models\Message;
use App\Models\MessageNote;
use App\Models\Report;
use App\Models\Session;
use App\Models\SessionNote;
use App\Models\Suspension;
use App\Models\Therapy;
use App\Models\User;
use App\Notifications\TherapyNotesExportedNotification;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\View;

// SCRUM-438: a counsellor exports the notes they wrote on one therapy as a PDF.

function tneSetup(array $therapy = []): array
{
    $counsellor = Counsellor::factory()->create(['user_id' => User::factory()->adult()->create()->id]);
    $client = User::factory()->adult()->create(['firstName' => 'Tneclient', 'lastName' => 'Realname']);
    $t = Therapy::factory()->make(['anonymous' => false, 'public' => false, ...$therapy]);
    $t->addedby()->associate($client);
    $t->counsellor_id = $counsellor->id;
    $t->forceFill(array_intersect_key($therapy, ['is_help_chat' => true]))->save();
    $session = Session::factory()->create(['for_type' => Therapy::class, 'for_id' => $t->id, 'name' => 'tne first session', 'start_time' => now()->subDay(), 'end_time' => now()->subDay()->addHour()]);

    return ['counsellor' => $counsellor, 'client' => $client, 'therapy' => $t, 'session' => $session];
}

function tneMessage(Session $session, User $from, string $content): Message
{
    $message = Message::factory()->make(['type' => MessageTypeEnum::normal->value, 'content' => $content]);
    $message->from()->associate($from);
    $message->for()->associate($session);
    $message->save();

    return $message;
}

function tneExport($test, User $user, Therapy $therapy, array $body = ['current_password' => 'password'])
{
    return $test->actingAs($user)->postJson(route('api.notes_export.therapy', $therapy->id), $body);
}

// What the PDF is made from (the rendered page), for checking what's in it.
function tneCapture(): ArrayObject
{
    $seen = new ArrayObject;
    View::composer('pdf.therapy-notes', fn ($view) => $seen['data'] = $view->getData());

    return $seen;
}

test('the counsellor gets a PDF of only their own notes on this therapy -- never the client\'s words -- and is told', function () {
    Notification::fake();
    $f = tneSetup();
    SessionNote::factory()->create(['session_id' => $f['session']->id, 'counsellor_id' => $f['counsellor']->id, 'content' => 'tne my session note']);
    $message = tneMessage($f['session'], $f['client'], 'tne-CLIENT-WORDS');
    MessageNote::factory()->create(['message_id' => $message->id, 'counsellor_id' => $f['counsellor']->id, 'content' => 'tne my message note']);
    // Not theirs: another counsellor's note on the same session; their own note on another therapy; a deleted one.
    SessionNote::factory()->create(['session_id' => $f['session']->id, 'content' => 'tne-OTHER-COUNSELLOR']);
    $other = tneSetup();
    SessionNote::factory()->create(['session_id' => $other['session']->id, 'counsellor_id' => $f['counsellor']->id, 'content' => 'tne-OTHER-THERAPY']);
    SessionNote::factory()->create(['session_id' => $f['session']->id, 'counsellor_id' => $f['counsellor']->id, 'content' => 'tne-DELETED'])->delete();
    $seen = tneCapture();

    $response = tneExport($this, $f['counsellor']->user, $f['therapy'])->assertOk();

    expect($response->headers->get('Content-Type'))->toBe('application/pdf')
        ->and($response->headers->get('Content-Disposition'))->toContain('talktherapy-notes-'.$f['therapy']->id.'-')->not->toContain('Tneclient')
        ->and(substr($response->getContent(), 0, 5))->toBe('%PDF-');
    $notes = collect($seen['data']['sessions'])->flatMap(fn ($s) => collect($s['notes'])->pluck('content'))->sort()->values()->all();
    expect($notes)->toBe(['tne my message note', 'tne my session note'])
        ->and(json_encode($seen['data']))->not->toContain('CLIENT-WORDS')->not->toContain('OTHER')->not->toContain('DELETED');
    Notification::assertSentTo($f['counsellor']->user, TherapyNotesExportedNotification::class);
});

test('an anonymous client stays anonymous in the PDF', function () {
    Notification::fake();
    $f = tneSetup(['anonymous' => true]);
    SessionNote::factory()->create(['session_id' => $f['session']->id, 'counsellor_id' => $f['counsellor']->id]);
    $seen = tneCapture();

    tneExport($this, $f['counsellor']->user, $f['therapy'])->assertOk();

    expect($seen['data']['client'])->not->toContain('Tneclient')->not->toContain('Realname')
        ->and(json_encode($seen['data']))->not->toContain($f['client']->username);
});

test('no one else: the client, another counsellor, an administrator; never an urgent help chat', function () {
    Notification::fake();
    $f = tneSetup();
    SessionNote::factory()->create(['session_id' => $f['session']->id, 'counsellor_id' => $f['counsellor']->id]);
    $admin = User::factory()->adult()->has(Administrator::factory()->state(['type' => AdministratorTypeEnum::super->value]))->create();

    foreach ([$f['client'], Counsellor::factory()->create(['user_id' => User::factory()->adult()->create()->id])->user, $admin] as $who) {
        tneExport($this, $who, $f['therapy'])->assertNotFound();
    }

    $help = tneSetup(['is_help_chat' => true]);
    tneExport($this, $help['counsellor']->user, $help['therapy'])->assertNotFound();
    Notification::assertNothingSent();
});

test('the same checks as the journal: wrong password, nothing to export, too much -- and nobody is told', function () {
    Notification::fake();
    $f = tneSetup();

    tneExport($this, $f['counsellor']->user, $f['therapy'], ['current_password' => 'wrong'])->assertJsonValidationErrors('current_password');
    tneExport($this, $f['counsellor']->user, $f['therapy'])->assertJsonValidationErrors('form');

    RateLimiter::clear('notes-export'.sha1($f['counsellor']->user->id));
    config(['limits.notes_export_max_characters' => 10]);
    SessionNote::factory()->create(['session_id' => $f['session']->id, 'counsellor_id' => $f['counsellor']->id, 'content' => str_repeat('a', 20)]);
    tneExport($this, $f['counsellor']->user, $f['therapy'])->assertJsonValidationErrors('form');

    Notification::assertNothingSent();
});

test('the therapy page offers the export to its counsellor only', function () {
    $f = tneSetup();
    $data = fn (User $who) => (new TherapyResource($f['therapy']->fresh()))->toArray(request()->setUserResolver(fn () => $who));

    expect($data($f['counsellor']->user)['notesExport'] ?? null)->toBeArray()
        ->and($data($f['client'])['notesExport'] ?? null)->not->toBeArray();
});

// A counsellor-role suspension, written directly (the suspension flow is tested on its own).
function tneSuspendAsCounsellor(User $person): void
{
    $report = new Report(['description' => 'tne conduct']);
    $report->addedby()->associate(User::factory()->adult()->create());
    $report->reportable()->associate($person);
    $report->save();
    Suspension::query()->create([
        'user_id' => $person->id, 'report_id' => $report->id, 'scope' => SuspensionScopeEnum::counsellor->value,
        'starts_at' => now()->subMinute(), 'ends_at' => now()->addDays(30), 'public_reason' => 'tne reason',
    ]);
}

function tneGroup(): array
{
    $counsellor = Counsellor::factory()->create(['user_id' => User::factory()->adult()->create()->id]);
    $coCounsellor = Counsellor::factory()->create(['user_id' => User::factory()->adult()->create()->id]);
    $group = GroupTherapy::factory()->create(['addedby_type' => User::class, 'addedby_id' => User::factory()->adult()->create()->id]);
    $group->counsellors()->attach($counsellor->id, ['state' => CounsellorGroupTherapyStateEnum::active->value, 'role' => 'NORMAL']);
    $group->counsellors()->attach($coCounsellor->id, ['state' => CounsellorGroupTherapyStateEnum::active->value, 'role' => 'NORMAL']);
    $session = Session::factory()->create(['for_type' => GroupTherapy::class, 'for_id' => $group->id, 'name' => 'tne group session']);

    return compact('counsellor', 'coCounsellor', 'group', 'session');
}

test('a group: each counsellor gets only their own notes; one no longer active in it gets nothing', function () {
    Notification::fake();
    $g = tneGroup();
    SessionNote::factory()->create(['session_id' => $g['session']->id, 'counsellor_id' => $g['counsellor']->id, 'content' => 'tne my group note']);
    SessionNote::factory()->create(['session_id' => $g['session']->id, 'counsellor_id' => $g['coCounsellor']->id, 'content' => 'tne-CO-COUNSELLOR']);
    $seen = tneCapture();

    $response = $this->actingAs($g['counsellor']->user)->postJson(route('api.notes_export.group', $g['group']->id), ['current_password' => 'password'])->assertOk();

    expect($response->headers->get('Content-Disposition'))->toContain('talktherapy-notes-group-'.$g['group']->id.'-')
        ->and($seen['data']['isGroup'])->toBeTrue()
        ->and(json_encode($seen['data']))->toContain('tne my group note')->not->toContain('CO-COUNSELLOR');

    $g['group']->counsellors()->updateExistingPivot($g['coCounsellor']->id, ['state' => CounsellorGroupTherapyStateEnum::inactive->value]);
    $this->actingAs($g['coCounsellor']->user)->postJson(route('api.notes_export.group', $g['group']->id), ['current_password' => 'password'])->assertNotFound();
});

test('the group page offers the export to its active counsellors only', function () {
    $g = tneGroup();
    $data = fn (User $who) => (new GroupTherapyResource($g['group']->fresh()))->toArray(request()->setUserResolver(fn () => $who));

    expect($data($g['counsellor']->user)['notesExport'] ?? null)->toBeArray()
        ->and($data($g['group']->addedby)['notesExport'] ?? null)->not->toBeArray();
    $g['group']->counsellors()->updateExistingPivot($g['counsellor']->id, ['state' => CounsellorGroupTherapyStateEnum::inactive->value]);
    expect($data($g['counsellor']->user)['notesExport'] ?? null)->not->toBeArray();
});

test('an ended therapy can be exported; a deleted one cannot', function () {
    Notification::fake();
    $f = tneSetup(['status' => TherapyStatusEnum::ended->value]);
    SessionNote::factory()->create(['session_id' => $f['session']->id, 'counsellor_id' => $f['counsellor']->id]);

    tneExport($this, $f['counsellor']->user, $f['therapy'])->assertOk();

    RateLimiter::clear('notes-export'.sha1($f['counsellor']->user->id));
    $f['therapy']->delete();
    tneExport($this, $f['counsellor']->user, $f['therapy'])->assertNotFound();
});

test('a counsellor whose role is suspended is neither offered the export nor given it', function () {
    Notification::fake();
    $f = tneSetup();
    SessionNote::factory()->create(['session_id' => $f['session']->id, 'counsellor_id' => $f['counsellor']->id]);
    tneSuspendAsCounsellor($f['counsellor']->user);
    $user = $f['counsellor']->user->fresh();

    expect((new TherapyResource($f['therapy']->fresh()))->toArray(request()->setUserResolver(fn () => $user))['notesExport'] ?? null)->not->toBeArray();
    tneExport($this, $user, $f['therapy'])->assertForbidden();
    Notification::assertNothingSent();
});

test('too many notes for one PDF is refused', function () {
    Notification::fake();
    config(['limits.notes_export_max_notes' => 2]);
    $f = tneSetup();
    SessionNote::factory()->count(3)->create(['session_id' => $f['session']->id, 'counsellor_id' => $f['counsellor']->id]);

    tneExport($this, $f['counsellor']->user, $f['therapy'])->assertJsonValidationErrors(['form' => 'That is too much for one PDF.']);
    Notification::assertNothingSent();
});

test('one PDF at a time per person, whichever kind: a journal export in progress holds a notes export back', function () {
    Notification::fake();
    $f = tneSetup();
    SessionNote::factory()->create(['session_id' => $f['session']->id, 'counsellor_id' => $f['counsellor']->id]);
    $lock = Cache::lock('pdf-export:'.$f['counsellor']->user->id, 120);
    $lock->get();

    tneExport($this, $f['counsellor']->user, $f['therapy'])->assertJsonValidationErrors(['form' => 'Your PDF is already being made. Please wait a moment.']);
    $lock->release();
    Notification::assertNothingSent();
});

test('after a recent email change the address it replaced is told too', function () {
    Notification::fake();
    $f = tneSetup();
    SessionNote::factory()->create(['session_id' => $f['session']->id, 'counsellor_id' => $f['counsellor']->id]);
    $f['counsellor']->user->forceFill(['last_verified_email' => 'tne-old@example.com', 'last_verified_email_at' => now()])->save();

    tneExport($this, $f['counsellor']->user->fresh(), $f['therapy'])->assertOk();

    Notification::assertSentOnDemand(TherapyNotesExportedNotification::class, fn ($n, $channels, $notifiable) => $notifiable->routes['mail'] === 'tne-old@example.com');
});
