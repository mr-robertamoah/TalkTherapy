<?php

use App\Actions\User\AnonymiseClosedAccountAction;
use App\Enums\AdministratorTypeEnum;
use App\Enums\TherapyStatusEnum;
use App\Http\Resources\TherapyResource;
use App\Models\Administrator;
use App\Models\Counsellor;
use App\Models\Guardianship;
use App\Models\OutcomeCheckIn;
use App\Models\Therapy;
use App\Models\User;
use App\Notifications\OutcomeCheckInAttentionNotification;
use App\Notifications\OutcomeCheckInCompletedNotification;
use App\Notifications\OutcomeCheckInRequestedNotification;
use App\Support\NotificationCategories;
use App\Support\OutcomeMeasures\Gad7;
use App\Support\OutcomeMeasures\Phq9;
use Database\Seeders\CheckInSeeder;
use Database\Seeders\YourDataSeeder;
use Illuminate\Notifications\Events\NotificationSending;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;

// SCRUM-37 (TT-5.2): outcome check-ins (PHQ-9, GAD-7) on a 1:1 therapy -- its counsellor sends, its client answers.

function ociSetup(array $therapy = []): array
{
    $counsellor = Counsellor::factory()->create(['user_id' => User::factory()->adult()->create()->id]);
    $counsellor->forceFill(['verified_at' => now(), 'verification_expires_at' => now()->addYear()])->save();
    $client = User::factory()->adult()->create();
    $t = Therapy::factory()->make(['anonymous' => false, 'public' => false, 'status' => TherapyStatusEnum::in_session->value, ...$therapy]);
    $t->addedby()->associate($client);
    $t->counsellor_id = $counsellor->id;
    $t->save();

    return ['counsellor' => $counsellor->fresh(), 'client' => $client, 'therapy' => $t];
}

function ociSend($test, array $f, string $measure = 'phq9')
{
    return $test->actingAs($f['counsellor']->user)->postJson(route('api.check_ins.store', $f['therapy']->id), ['measure' => $measure]);
}

function ociAnswer($test, array $f, int $id, array $answers)
{
    return $test->actingAs($f['client'])->postJson(route('api.check_ins.answer', $id), ['answers' => $answers]);
}

test('scoring and levels at every boundary', function (string $class, int $score, string $level) {
    expect((new $class)->band($score))->toBe($level);
})->with([
    [Phq9::class, 0, 'minimal'], [Phq9::class, 4, 'minimal'], [Phq9::class, 5, 'mild'], [Phq9::class, 9, 'mild'],
    [Phq9::class, 10, 'moderate'], [Phq9::class, 14, 'moderate'], [Phq9::class, 15, 'moderately severe'],
    [Phq9::class, 19, 'moderately severe'], [Phq9::class, 20, 'severe'], [Phq9::class, 27, 'severe'],
    [Gad7::class, 0, 'minimal'], [Gad7::class, 4, 'minimal'], [Gad7::class, 5, 'mild'], [Gad7::class, 9, 'mild'],
    [Gad7::class, 10, 'moderate'], [Gad7::class, 14, 'moderate'], [Gad7::class, 15, 'severe'], [Gad7::class, 21, 'severe'],
]);

test('the questionnaires keep their published shape', function () {
    expect((new Phq9)->items())->toHaveCount(9)->and((new Phq9)->maxScore())->toBe(27)->and((new Phq9)->attentionItem())->toBe(8)
        ->and((new Phq9)->items()[8])->toBe('Thoughts that you would be better off dead or of hurting yourself in some way')
        ->and((new Gad7)->items())->toHaveCount(7)->and((new Gad7)->maxScore())->toBe(21)->and((new Gad7)->attentionItem())->toBeNull();
});

test('the counsellor sends, the client is told (never which questionnaire), answers once, and the score is worked out here', function () {
    Notification::fake();
    $f = ociSetup();

    $id = ociSend($this, $f)->assertCreated()->assertJsonPath('checkIn.state', 'requested')->json('checkIn.id');
    Notification::assertSentTo($f['client'], OutcomeCheckInRequestedNotification::class, function ($n) use ($f) {
        $mail = (string) $n->toMail($f['client'])->render();

        return ! str_contains($mail, 'PHQ') && ! str_contains($mail, 'GAD');
    });

    ociAnswer($this, $f, $id, [1, 2, 1, 0, 0, 1, 0, 0, 0])->assertOk()
        ->assertJsonPath('checkIn.score', 5)->assertJsonPath('checkIn.level', 'mild')->assertJsonPath('showCrisisSupport', false);
    Notification::assertSentTo($f['counsellor']->user, OutcomeCheckInCompletedNotification::class);
    Notification::assertNotSentTo($f['counsellor']->user, OutcomeCheckInAttentionNotification::class);

    ociAnswer($this, $f, $id, [0, 0, 0, 0, 0, 0, 0, 0, 0])->assertStatus(409);

    $this->actingAs($f['counsellor']->user)->getJson(route('api.check_ins.index', $f['therapy']->id))->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertJsonPath('checkIns.0.score', 5)->assertJsonPath('checkIns.0.answers', [1, 2, 1, 0, 0, 1, 0, 0, 0]);
});

test('answers, score and level are encrypted at rest', function () {
    Notification::fake();
    $f = ociSetup();
    $id = ociSend($this, $f, 'gad7')->json('checkIn.id');
    ociAnswer($this, $f, $id, [3, 3, 3, 3, 3, 3, 3]);

    $row = DB::table('outcome_check_ins')->find($id);

    expect($row->answers)->not->toContain('[3')->and($row->total_score)->not->toBe('21')->and($row->band)->not->toBe('severe')
        ->and(OutcomeCheckIn::find($id))->total_score->toBe('21')->band->toBe('severe');
});

test('every item must be answered, each 0 to 3', function (mixed $answers) {
    Notification::fake();
    $f = ociSetup();
    $id = ociSend($this, $f)->json('checkIn.id');

    ociAnswer($this, $f, $id, $answers)->assertStatus(422);
    expect(OutcomeCheckIn::find($id)->status)->toBe(OutcomeCheckIn::REQUESTED);
})->with([
    'too few' => [[0, 0, 0]],
    'out of range' => [[0, 0, 0, 0, 0, 0, 0, 0, 4]],
    'not a number' => [[0, 0, 0, 0, 0, 0, 0, 0, '1']],
    'a map' => [['a' => 0]],
]);

test('a self-harm answer: urgent help for the client at once, and an always-sent notice for the counsellor with nothing of the answers', function () {
    Notification::fake();
    $f = ociSetup();
    $id = ociSend($this, $f)->json('checkIn.id');

    ociAnswer($this, $f, $id, [0, 0, 0, 0, 0, 0, 0, 0, 1])->assertOk()->assertJsonPath('showCrisisSupport', true);

    Notification::assertSentTo($f['counsellor']->user, OutcomeCheckInAttentionNotification::class, function ($n) use ($f) {
        $mail = (string) $n->toMail($f['counsellor']->user)->render();

        return ! str_contains($mail, 'PHQ') && ! str_contains($mail, 'hurting') && ! str_contains($mail, 'score');
    });
    expect(NotificationCategories::MAP)->not->toHaveKey(OutcomeCheckInAttentionNotification::class)
        ->and(OutcomeCheckIn::find($id)->needs_attention)->toBeTrue()
        ->and(DB::table('safety_flags')->count())->toBe(0);

    $this->actingAs($f['counsellor']->user)->getJson(route('api.check_ins.index', $f['therapy']->id))->assertJsonPath('checkIns.0.needsAttention', true);
    $this->actingAs($f['client'])->getJson(route('api.check_ins.index', $f['therapy']->id))->assertJsonPath('checkIns.0.needsAttention', false);

    $this->actingAs($f['counsellor']->user)->postJson(route('api.check_ins.acknowledge', $id))->assertOk()->assertJsonPath('checkIn.acknowledgedAt', fn ($v) => $v !== null);
});

test('no one else can see, send or answer: another counsellor, a guardian, an administrator', function () {
    Notification::fake();
    $f = ociSetup();
    $id = ociSend($this, $f)->json('checkIn.id');
    $guardian = User::factory()->adult()->create();
    Guardianship::query()->create(['guardian_id' => $guardian->id, 'ward_id' => $f['client']->id]);
    $other = Counsellor::factory()->create(['user_id' => User::factory()->adult()->create()->id]);
    $admin = User::factory()->adult()->has(Administrator::factory()->state(['type' => AdministratorTypeEnum::super->value]))->create();

    foreach ([$other->user, $guardian, $admin] as $who) {
        $this->actingAs($who)->getJson(route('api.check_ins.index', $f['therapy']->id))->assertNotFound();
        $this->actingAs($who)->postJson(route('api.check_ins.store', $f['therapy']->id), ['measure' => 'gad7'])->assertNotFound();
        $this->actingAs($who)->postJson(route('api.check_ins.answer', $id), ['answers' => array_fill(0, 9, 0)])->assertNotFound();
        $this->actingAs($who)->postJson(route('api.check_ins.cancel', $id))->assertNotFound();
    }

    // The client can't send, cancel or acknowledge; the counsellor can't answer.
    $this->actingAs($f['client'])->postJson(route('api.check_ins.store', $f['therapy']->id), ['measure' => 'gad7'])->assertNotFound();
    $this->actingAs($f['client'])->postJson(route('api.check_ins.cancel', $id))->assertNotFound();
    $this->actingAs($f['counsellor']->user)->postJson(route('api.check_ins.answer', $id), ['answers' => array_fill(0, 9, 0)])->assertNotFound();
});

test('one open per questionnaire per therapy; an expired one makes way; a withdrawn one can\'t be answered', function () {
    Notification::fake();
    $f = ociSetup();

    $first = ociSend($this, $f)->assertCreated()->json('checkIn.id');
    ociSend($this, $f)->assertStatus(409);
    ociSend($this, $f, 'gad7')->assertCreated();

    $this->travel((int) config('check_ins.expires_days') + 1)->days();
    ociAnswer($this, $f, $first, array_fill(0, 9, 0))->assertStatus(409);
    $second = ociSend($this, $f)->assertCreated()->json('checkIn.id');

    $this->actingAs($f['counsellor']->user)->postJson(route('api.check_ins.cancel', $second))->assertOk()->assertJsonPath('checkIn.state', 'cancelled');
    ociAnswer($this, $f, $second, array_fill(0, 9, 0))->assertStatus(409);
});

test('not for an ended therapy or an urgent help chat', function () {
    Notification::fake();
    $ended = ociSetup(['status' => TherapyStatusEnum::ended->value]);
    ociSend($this, $ended)->assertNotFound();

    $help = ociSetup();
    $help['therapy']->forceFill(['is_help_chat' => true])->save();
    ociSend($this, $help)->assertNotFound();
});

test('switched off, every route is a 404', function () {
    $f = ociSetup();
    config(['check_ins.enabled' => false]);

    ociSend($this, $f)->assertNotFound();
    $this->actingAs($f['counsellor']->user)->getJson(route('api.check_ins.index', $f['therapy']->id))->assertNotFound();
});

test('the client\'s own check-ins are counted on "What we hold about you", given in full in their copy, and cleared when the account is erased', function () {
    Notification::fake();
    $f = ociSetup();
    $id = ociSend($this, $f, 'gad7')->json('checkIn.id');
    ociAnswer($this, $f, $id, [1, 1, 1, 1, 1, 1, 1]);

    $this->actingAs($f['client'])->get(route('profile.your-data'))->assertInertia(fn ($page) => $page->where('data.checkIns.answered', 1));
    $copy = json_decode($this->actingAs($f['client'])->post(route('profile.your-data.download'), ['current_password' => 'password'])->assertOk()->streamedContent(), true);
    expect($copy['checkIns'][0])->toMatchArray(['questionnaire' => 'GAD-7', 'score' => 7, 'level' => 'mild'])
        ->and($copy['checkIns'][0]['answers'][0])->toBe(['question' => 'Feeling nervous, anxious, or on edge', 'answer' => 'Several days']);
    // Not the counsellor's: they sent it, it isn't theirs.
    $counsellorCopy = json_decode($this->actingAs($f['counsellor']->user)->post(route('profile.your-data.download'), ['current_password' => 'password'])->assertOk()->streamedContent(), true);
    expect($counsellorCopy['checkIns'])->toBe([]);

    $f['client']->delete();
    AnonymiseClosedAccountAction::new()->execute($f['client']->fresh());
    expect(OutcomeCheckIn::find($id))->answers->toBeNull()->total_score->toBeNull()->band->toBeNull()->status->toBe(OutcomeCheckIn::COMPLETED);
});

test('the demo check-ins seed once', function () {
    $this->seed(YourDataSeeder::class);
    $this->seed(CheckInSeeder::class);
    $this->seed(CheckInSeeder::class);

    expect(OutcomeCheckIn::query()->count())->toBe(5)
        ->and(OutcomeCheckIn::query()->where('needs_attention', true)->count())->toBe(1);
});

test('the therapy page says whether to show check-ins: its counsellor and client, while the feature is on', function () {
    $f = ociSetup();
    $guardian = User::factory()->adult()->create();
    Guardianship::query()->create(['guardian_id' => $guardian->id, 'ward_id' => $f['client']->id]);
    $flag = fn (User $who) => (new TherapyResource($f['therapy']->fresh()))->toArray(request()->setUserResolver(fn () => $who))['checkInsEnabled'] ?? null;

    expect($flag($f['counsellor']->user))->toBeTrue()->and($flag($f['client']))->toBeTrue()
        ->and($flag($guardian))->not->toBeTrue();
    config(['check_ins.enabled' => false]);
    expect($flag($f['counsellor']->user))->not->toBeTrue();
});

test('after the client\'s account is erased, the counsellor sees it was answered -- never a made-up score', function () {
    Notification::fake();
    $f = ociSetup();
    $id = ociSend($this, $f)->json('checkIn.id');
    ociAnswer($this, $f, $id, [0, 0, 0, 0, 0, 0, 0, 0, 2]);
    $f['client']->delete();
    AnonymiseClosedAccountAction::new()->execute($f['client']->fresh());

    $this->actingAs($f['counsellor']->user)->getJson(route('api.check_ins.index', $f['therapy']->id))->assertOk()
        ->assertJsonPath('checkIns.0.state', 'erased')->assertJsonPath('checkIns.0.score', null)->assertJsonPath('checkIns.0.answers', null);
});

test('refusals: an unknown questionnaire, the daily limit, an unverified counsellor, the client acknowledging', function () {
    Notification::fake();
    $f = ociSetup();
    ociSend($this, $f, 'made-up')->assertStatus(422);
    $this->actingAs($f['counsellor']->user)->postJson(route('api.check_ins.store', $f['therapy']->id), ['measure' => ['phq9']])->assertStatus(422);

    config(['check_ins.per_counsellor_per_day' => 1]);
    $id = ociSend($this, $f)->assertCreated()->json('checkIn.id');
    ociSend($this, $f, 'gad7')->assertStatus(429);
    $this->actingAs($f['client'])->postJson(route('api.check_ins.acknowledge', $id))->assertNotFound();

    $unverified = ociSetup();
    $unverified['counsellor']->forceFill(['verified_at' => null])->save();
    ociSend($this, ['counsellor' => $unverified['counsellor']->fresh(), 'therapy' => $unverified['therapy']])->assertNotFound();
});

test('a self-harm answer that comes too late to be saved still tells the counsellor (nothing of what it was)', function () {
    Notification::fake();
    $f = ociSetup();
    $id = ociSend($this, $f)->json('checkIn.id');
    $this->actingAs($f['counsellor']->user)->postJson(route('api.check_ins.cancel', $id))->assertOk();

    ociAnswer($this, $f, $id, [0, 0, 0, 0, 0, 0, 0, 0, 3])->assertStatus(409)->assertJsonPath('message', 'Your counsellor withdrew this check-in.');

    Notification::assertSentTo($f['counsellor']->user, OutcomeCheckInAttentionNotification::class);
    Notification::assertNotSentTo($f['counsellor']->user, OutcomeCheckInCompletedNotification::class);
    expect(OutcomeCheckIn::find($id))->status->toBe(OutcomeCheckIn::CANCELLED)->answers->toBeNull();
});

test('a notice that fails to send never fails the client\'s answer', function () {
    $f = ociSetup();
    $id = ociSend($this, $f)->json('checkIn.id');
    Event::listen(NotificationSending::class, function ($event) {
        if ($event->notification instanceof OutcomeCheckInCompletedNotification) {
            throw new RuntimeException('mail down');
        }
    });

    ociAnswer($this, $f, $id, array_fill(0, 9, 1))->assertOk();

    expect(OutcomeCheckIn::find($id)->status)->toBe(OutcomeCheckIn::COMPLETED);
});

test('a self-harm answer sends the counsellor one notice (attention), not two', function () {
    Notification::fake();
    $f = ociSetup();
    $id = ociSend($this, $f)->json('checkIn.id');

    ociAnswer($this, $f, $id, [0, 0, 0, 0, 0, 0, 0, 0, 1])->assertOk();

    Notification::assertSentTo($f['counsellor']->user, OutcomeCheckInAttentionNotification::class);
    Notification::assertNotSentTo($f['counsellor']->user, OutcomeCheckInCompletedNotification::class);
});
