<?php

use App\Actions\Report\EnsureCanReportReportableAction;
use App\Actions\Report\EnsureReportDataIsValidAction;
use App\Models\Counsellor;
use App\Models\GroupTherapy;
use App\Models\Post;
use App\Models\Report;
use App\Models\Session;
use App\Models\Therapy;
use App\Models\User;
use Illuminate\Support\Facades\Notification;

// SCRUM-347: only a therapy's participants may report it, so the errors can no longer be used to
// test who is in a therapy. Also: the client can report (one person at a time), and group reports work.

function aCounselledTherapyFor347(): array
{
    $client = User::factory()->create();
    $counsellor = Counsellor::factory()->create(['user_id' => User::factory()->create()->id]);
    // Not anonymous: TherapyFactory defaults to anonymous, which hides the client's id (SCRUM-348).
    $therapy = Therapy::factory()->create(['addedby_type' => User::class, 'addedby_id' => $client->id, 'counsellor_id' => $counsellor->id, 'anonymous' => false]);

    return [$therapy, $client, $counsellor];
}

function reportPayloadFor347(User $reporter, string $type, int $id, array $data = []): array
{
    return [
        'description' => 'Something happened',
        'reportableType' => $type,
        'reportableId' => $id,
        'addedbyType' => 'user',
        'addedbyId' => $reporter->id,
        'data' => $data,
    ];
}

beforeEach(fn () => Notification::fake());

test('the client can report their counsellor, and the counsellor their client', function () {
    [$therapy, $client, $counsellor] = aCounselledTherapyFor347();

    $this->actingAs($client)->postJson(route('api.reports.create'), reportPayloadFor347($client, 'therapy', $therapy->id, ['counsellorId' => $counsellor->id]))->assertOk();
    $this->actingAs($counsellor->user)->postJson(route('api.reports.create'), reportPayloadFor347($counsellor->user, 'therapy', $therapy->id, ['userId' => $client->id]))->assertOk();

    expect(Report::count())->toBe(2);
});

test('an outsider, a wrong person and a missing therapy all get the same refusal', function () {
    [$therapy, $client, $counsellor] = aCounselledTherapyFor347();
    $outsider = User::factory()->create();

    $guesses = [
        reportPayloadFor347($outsider, 'therapy', $therapy->id, ['userId' => $client->id, 'counsellorId' => $counsellor->id]),
        reportPayloadFor347($outsider, 'therapy', $therapy->id, ['userId' => $outsider->id, 'counsellorId' => $counsellor->id]),
        reportPayloadFor347($outsider, 'therapy', 999999, ['userId' => $client->id]),
    ];

    foreach ($guesses as $payload) {
        $this->actingAs($outsider)->postJson(route('api.reports.create'), $payload)
            ->assertStatus(422)->assertJsonPath('message', EnsureCanReportReportableAction::NOT_ALLOWED);
    }

    expect(Report::count())->toBe(0);
    Notification::assertNothingSent();
});

test('a participant naming someone outside the therapy gets one generic error', function () {
    [$therapy, $client] = aCounselledTherapyFor347();
    $stranger = Counsellor::factory()->create(['user_id' => User::factory()->create()->id]);

    foreach ([['counsellorId' => $stranger->id], ['userId' => $stranger->user->id]] as $data) {
        $this->actingAs($client)->postJson(route('api.reports.create'), reportPayloadFor347($client, 'therapy', $therapy->id, $data))
            ->assertStatus(422)->assertJsonPath('message', EnsureReportDataIsValidAction::NOT_A_PARTICIPANT);
    }
});

test('a reporter cannot re-point their report at a therapy they are not in', function () {
    [$therapy, $client, $counsellor] = aCounselledTherapyFor347();
    [$otherTherapy] = aCounselledTherapyFor347();
    $report = Report::create(['description' => 'x']);
    $report->addedby()->associate($client);
    $report->reportable()->associate($therapy);
    $report->save();

    $this->actingAs($client)->postJson(route('api.reports.update', ['reportId' => $report->id]), [
        'reportableType' => 'therapy', 'reportableId' => $otherTherapy->id,
    ])->assertStatus(422)->assertJsonPath('message', EnsureCanReportReportableAction::NOT_ALLOWED);

    expect($report->fresh()->reportable_id)->toBe($therapy->id);
});

test('group members and counsellors can report the group, optionally naming a counsellor', function () {
    $creator = User::factory()->create();
    $member = User::factory()->create();
    $counsellor = Counsellor::factory()->create(['user_id' => User::factory()->create()->id]);
    $group = GroupTherapy::factory()->create(['addedby_type' => User::class, 'addedby_id' => $creator->id]);
    $group->users()->attach($member->id, ['anonymous' => false]);
    $group->counsellors()->attach($counsellor->id, ['state' => 'ACTIVE', 'role' => 'NORMAL']);

    $this->actingAs($member)->postJson(route('api.reports.create'), reportPayloadFor347($member, 'GroupTherapy', $group->id))->assertOk();
    $this->actingAs($member)->postJson(route('api.reports.create'), reportPayloadFor347($member, 'GroupTherapy', $group->id, ['counsellorId' => $counsellor->id]))->assertOk();
    $this->actingAs($counsellor->user)->postJson(route('api.reports.create'), reportPayloadFor347($counsellor->user, 'GroupTherapy', $group->id))->assertOk();

    expect(Report::query()->where('reportable_type', GroupTherapy::class)->count())->toBe(3);

    $outsider = User::factory()->create();
    $this->actingAs($outsider)->postJson(route('api.reports.create'), reportPayloadFor347($outsider, 'GroupTherapy', $group->id))
        ->assertStatus(422)->assertJsonPath('message', EnsureCanReportReportableAction::NOT_ALLOWED);
});

test('session reports follow the same rule: participants yes, outsiders no', function () {
    [$therapy, $client, $counsellor] = aCounselledTherapyFor347();
    $session = Session::factory()->create(['for_id' => $therapy->id, 'for_type' => Therapy::class]);

    $this->actingAs($client)->postJson(route('api.reports.create'), reportPayloadFor347($client, 'session', $session->id, ['counsellorId' => $counsellor->id]))->assertOk();
    $outsider = User::factory()->create();
    $this->actingAs($outsider)->postJson(route('api.reports.create'), reportPayloadFor347($outsider, 'session', $session->id))
        ->assertStatus(422)->assertJsonPath('message', EnsureCanReportReportableAction::NOT_ALLOWED);
});

test('anyone signed in can still report a post, but cannot name people on it', function () {
    $post = Post::query()->forceCreate(['content' => 'Hello', 'addedby_type' => User::class, 'addedby_id' => User::factory()->create()->id]);
    $reader = User::factory()->create();

    $this->actingAs($reader)->postJson(route('api.reports.create'), reportPayloadFor347($reader, 'post', $post->id))->assertOk();
    $this->actingAs($reader)->postJson(route('api.reports.create'), reportPayloadFor347($reader, 'post', $post->id, ['userId' => $post->addedby_id]))
        ->assertStatus(422)->assertJsonPath('message', EnsureReportDataIsValidAction::INVALID_DATA);
});

test('an individual therapy report names the other party by default, never yourself, and only known keys', function () {
    [$therapy, $client, $counsellor] = aCounselledTherapyFor347();

    // SCRUM-348: naming nobody means the other party -- how a counsellor reports an anonymous client.
    $toCounsellor = $this->actingAs($client)->postJson(route('api.reports.create'), reportPayloadFor347($client, 'therapy', $therapy->id))->assertOk();
    expect(Report::find($toCounsellor->json('report.id'))->data)->toEqual(['counsellorId' => $counsellor->id]);
    $toClient = $this->actingAs($counsellor->user)->postJson(route('api.reports.create'), reportPayloadFor347($counsellor->user, 'therapy', $therapy->id))->assertOk();
    expect(Report::find($toClient->json('report.id'))->data)->toEqual(['userId' => $client->id]);
    $this->actingAs($client)->postJson(route('api.reports.create'), reportPayloadFor347($client, 'therapy', $therapy->id, ['userId' => $client->id]))
        ->assertStatus(422)->assertJsonPath('message', 'You cannot report yourself.');
    $this->actingAs($client)->postJson(route('api.reports.create'), reportPayloadFor347($client, 'therapy', $therapy->id, ['counsellorId' => $counsellor->id, 'notes' => '<script>']))
        ->assertStatus(422)->assertJsonPath('message', EnsureReportDataIsValidAction::INVALID_DATA);
});

test('group reports never accept a member id, member or not, and only the group\'s active counsellors', function () {
    $creator = User::factory()->create();
    $member = User::factory()->create();
    $reporter = User::factory()->create();
    $activeCounsellor = Counsellor::factory()->create(['user_id' => User::factory()->create()->id]);
    $leftCounsellor = Counsellor::factory()->create(['user_id' => User::factory()->create()->id]);
    $group = GroupTherapy::factory()->create(['addedby_type' => User::class, 'addedby_id' => $creator->id, 'anonymous' => true]);
    $group->users()->attach([$member->id => ['anonymous' => true], $reporter->id => ['anonymous' => true]]);
    $group->counsellors()->attach($activeCounsellor->id, ['state' => 'ACTIVE', 'role' => 'NORMAL']);
    $group->counsellors()->attach($leftCounsellor->id, ['state' => 'INACTIVE', 'role' => 'NORMAL']);

    // A member and a non-member get the same answer -- membership can't be probed.
    $asMember = $this->actingAs($reporter)->postJson(route('api.reports.create'), reportPayloadFor347($reporter, 'GroupTherapy', $group->id, ['userId' => $member->id]));
    $asStranger = $this->actingAs($reporter)->postJson(route('api.reports.create'), reportPayloadFor347($reporter, 'GroupTherapy', $group->id, ['userId' => User::factory()->create()->id]));
    expect($asMember->status())->toBe(422)
        ->and($asMember->json('message'))->toBe($asStranger->json('message'))
        ->and($asMember->json('message'))->toBe(EnsureReportDataIsValidAction::NOT_A_PARTICIPANT);

    $this->actingAs($reporter)->postJson(route('api.reports.create'), reportPayloadFor347($reporter, 'GroupTherapy', $group->id, ['counsellorId' => $leftCounsellor->id]))
        ->assertStatus(422)->assertJsonPath('message', EnsureReportDataIsValidAction::NOT_A_PARTICIPANT);
    $this->actingAs($reporter)->postJson(route('api.reports.create'), reportPayloadFor347($reporter, 'GroupTherapy', $group->id, ['counsellorId' => $activeCounsellor->id]))->assertOk();
});

test('updates are validated against the report\'s own therapy, and a re-point replaces who it names', function () {
    [$therapy, $client, $counsellor] = aCounselledTherapyFor347();
    $report = Report::create(['description' => 'x', 'data' => ['counsellorId' => $counsellor->id]]);
    $report->addedby()->associate($client);
    $report->reportable()->associate($therapy);
    $report->save();

    // No reportable sent: still checked against this report's therapy.
    $stranger = Counsellor::factory()->create(['user_id' => User::factory()->create()->id]);
    $this->actingAs($client)->postJson(route('api.reports.update', ['reportId' => $report->id]), ['data' => ['counsellorId' => $stranger->id]])
        ->assertStatus(422)->assertJsonPath('message', EnsureReportDataIsValidAction::NOT_A_PARTICIPANT);
    $this->actingAs($client)->postJson(route('api.reports.update', ['reportId' => $report->id]), ['description' => 'More detail'])->assertOk();

    // A therapy id that doesn't exist answers like one you're not in.
    $this->actingAs($client)->postJson(route('api.reports.update', ['reportId' => $report->id]), ['reportableType' => 'therapy', 'reportableId' => 999999])
        ->assertStatus(422)->assertJsonPath('message', EnsureCanReportReportableAction::NOT_ALLOWED);

    // Re-pointed to another therapy the client is in: only the new people are kept.
    $otherCounsellor = Counsellor::factory()->create(['user_id' => User::factory()->create()->id]);
    $other = Therapy::factory()->create(['addedby_type' => User::class, 'addedby_id' => $client->id, 'counsellor_id' => $otherCounsellor->id]);
    $this->actingAs($client)->postJson(route('api.reports.update', ['reportId' => $report->id]), [
        'reportableType' => 'therapy', 'reportableId' => $other->id, 'data' => ['counsellorId' => $otherCounsellor->id],
    ])->assertOk();

    expect($report->fresh()->data)->toEqual(['counsellorId' => $otherCounsellor->id])
        ->and($report->fresh()->reportable_id)->toBe($other->id);
});
