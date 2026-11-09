<?php

use App\Actions\User\AnonymiseClosedAccountAction;
use App\Enums\HelpRequestStatusEnum;
use App\Enums\RequestStatusEnum;
use App\Enums\RequestTypeEnum;
use App\Models\Counsellor;
use App\Models\GroupTherapy;
use App\Models\HelpRequest;
use App\Models\Report;
use App\Models\Request;
use App\Models\Suspension;
use App\Models\Therapy;
use App\Models\User;
use App\Notifications\CounsellorUnavailableNotification;
use App\Notifications\HelpTherapyInviteNotification;
use App\Notifications\SessionScheduleProposalRejectedNotification;
use App\Support\WardRef;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

// SCRUM-396: a counsellor named to their clients, and the reason someone gave for declining a session time,
// are taken out of stored notices when that person's closed account is anonymised -- new notices through
// their stored reference, old ones through the backfill. Helpers are file-local and ann-prefixed (--parallel).

function annCounsellor(string $name = 'Dr Zara Quist'): Counsellor
{
    return Counsellor::factory()->for(User::factory()->adult())->create(['name' => $name]);
}

function annData(string $id): array
{
    return json_decode((string) DB::table('notifications')->where('id', $id)->value('data'), true);
}

function annAnonymise(User $user): void
{
    $user->delete();
    $user->forceFill(['deleted_at' => now()->subDays(config('user.closed_account_retention_days') + 1)])->saveQuietly();
    expect(AnonymiseClosedAccountAction::new()->execute($user->fresh()))->toBeTrue();
}

function annDeclined(User $proposer, User $decliner, string $reason): Request
{
    return Request::query()->forceCreate([
        'type' => RequestTypeEnum::sessionScheduleProposal->value, 'status' => RequestStatusEnum::rejected->value,
        'data' => ['reason' => $reason, 'proposedById' => $proposer->id],
        'from_type' => User::class, 'from_id' => $proposer->id, 'to_type' => User::class, 'to_id' => $decliner->id,
        'for_type' => Therapy::class, 'for_id' => 1,
    ]);
}

function annInsert(User $notifiable, string $type, array $data): string
{
    $id = (string) Str::uuid();
    DB::table('notifications')->insert([
        'id' => $id, 'type' => $type, 'notifiable_type' => User::class, 'notifiable_id' => $notifiable->id,
        'data' => json_encode($data), 'created_at' => now(), 'updated_at' => now(),
    ]);

    return $id;
}

test('a closed counsellor\'s name is taken out of their clients\' notices, which then say "your counsellor"', function () {
    $counsellor = annCounsellor();
    $client = User::factory()->adult()->create();

    Notification::sendNow($client, new CounsellorUnavailableNotification($counsellor->getName(), null, 0, false, $counsellor->user), ['database']);
    Notification::sendNow($client, new HelpTherapyInviteNotification(5, $counsellor->getName(), true, $counsellor->user), ['database']);
    [$unavailable, $invite] = [$client->notifications()->where('type', CounsellorUnavailableNotification::class)->value('id'), $client->notifications()->where('type', HelpTherapyInviteNotification::class)->value('id')];
    expect(annData($unavailable)['counsellorName'])->toBe('Dr Zara Quist');

    annAnonymise($counsellor->user);

    expect(json_encode(DB::table('notifications')->whereIn('id', [$unavailable, $invite])->pluck('data')))->not->toContain('Zara');
    $titles = collect($this->actingAs($client)->getJson(route('notifications.index'))->json('notifications'))->pluck('title');
    expect($titles)->toContain('Your counsellor is unavailable');
    expect(CounsellorUnavailableNotification::present(annData($unavailable))->title)->not->toContain('closed account');
});

test('a declined session time\'s reason is cleared when the person who wrote it is anonymised', function () {
    $proposer = User::factory()->adult()->create();
    $decliner = User::factory()->adult()->create();
    $request = annDeclined($proposer, $decliner, 'I see Dr Quist on Tuesdays');

    Notification::sendNow($proposer, new SessionScheduleProposalRejectedNotification($request, $decliner), ['database']);
    $id = $proposer->notifications()->value('id');
    expect(annData($id)['reason'])->toContain('Tuesdays');

    annAnonymise($decliner);

    expect(annData($id)['reason'])->toBeNull()
        ->and(SessionScheduleProposalRejectedNotification::present(annData($id))->message)->toBe('');
});

test('a declined time without a reason carries no reference', function () {
    $request = tap(new Request, fn (Request $request) => $request->forceFill(['id' => 778, 'for_id' => 5, 'data' => []]));

    expect((new SessionScheduleProposalRejectedNotification($request, User::factory()->adult()->create()))->toArray(new User))->not->toHaveKey(WardRef::KEY);
});

function annSuspend(Counsellor $counsellor, ?Carbon $endsAt): void
{
    $report = new Report(['description' => 'Something happened']);
    $report->addedby()->associate(User::factory()->adult()->create());
    $report->reportable()->associate(GroupTherapy::factory()->create());
    $report->save();
    Suspension::query()->forceCreate(['user_id' => $counsellor->user_id, 'report_id' => $report->id, 'scope' => 'counsellor', 'public_reason' => 'Conduct', 'starts_at' => now()->subDay(), 'ends_at' => $endsAt, 'created_at' => now()->subDay()]);
}

function annBackfill(): void
{
    (require database_path('migrations/2026_11_20_100000_backfill_person_refs_in_notifications.php'))->up();
}

test('the backfill traces old notices to the person -- the suspension for "unavailable", even once the name is erased', function () {
    $client = User::factory()->adult()->create();
    $live = annCounsellor('Dr Live Person');
    $gone = annCounsellor('Dr Gone Person');
    [$twinA, $twinB] = [annCounsellor('Dr Twin'), annCounsellor('Dr Twin')];
    $liveEnds = now()->addDays(5)->startOfSecond();
    $goneEnds = now()->addDays(9)->startOfSecond();
    $twinEnds = now()->addDays(12)->startOfSecond();
    annSuspend($live, $liveEnds);
    annSuspend($gone, $goneEnds);
    annSuspend($twinA, $twinEnds);
    annSuspend($twinB, $twinEnds);

    $help = HelpRequest::query()->forceCreate(['user_id' => $client->id, 'status' => HelpRequestStatusEnum::ended->value, 'counsellor_id' => $live->id]);
    $decliner = User::factory()->adult()->create();
    $declined = annDeclined($client, $decliner, 'Busy');

    $rows = [
        'invite' => annInsert($client, HelpTherapyInviteNotification::class, ['helpRequestId' => $help->id, 'counsellorName' => 'Dr Live Person']),
        'unavailable' => annInsert($client, CounsellorUnavailableNotification::class, ['counsellorName' => 'Dr Live Person', 'until' => $liveEnds->toJSON()]),
        'gone' => annInsert($client, CounsellorUnavailableNotification::class, ['counsellorName' => 'Dr Gone Person', 'until' => $goneEnds->toJSON()]),
        'twin' => annInsert($client, CounsellorUnavailableNotification::class, ['counsellorName' => 'Dr Twin', 'until' => $twinEnds->toJSON()]),
        'declined' => annInsert($client, SessionScheduleProposalRejectedNotification::class, ['requestId' => $declined->id, 'reason' => 'Busy']),
    ];
    // Already anonymised: their name is gone from the profile, so only the suspension can find them.
    $gone->forceFill(['name' => null])->saveQuietly();
    $gone->user->forceFill(['anonymised_at' => now()])->saveQuietly();

    annBackfill();

    expect(annData($rows['invite'])[WardRef::KEY])->toBe(WardRef::of($live->user))
        ->and(annData($rows['unavailable'])[WardRef::KEY])->toBe(WardRef::of($live->user))
        ->and(annData($rows['declined'])[WardRef::KEY])->toBe(WardRef::of($decliner))
        ->and(annData($rows['gone'])['counsellorName'])->toBe(AnonymiseClosedAccountAction::CLOSED_ACCOUNT_NAME)
        // Two suspensions ending then, and the same name: left alone rather than guessed.
        ->and(annData($rows['twin']))->not->toHaveKey(WardRef::KEY);

    // And anonymising the live counsellor now takes them out of the old notices too.
    annAnonymise($live->user);
    expect(json_encode(DB::table('notifications')->whereIn('id', [$rows['invite'], $rows['unavailable']])->pluck('data')))->not->toContain('Live Person');
});

test('the backfill pages by id, so more than a page of rows are all referenced', function () {
    $client = User::factory()->adult()->create();
    $counsellor = annCounsellor();
    $help = HelpRequest::query()->forceCreate(['user_id' => $client->id, 'status' => HelpRequestStatusEnum::ended->value, 'counsellor_id' => $counsellor->id]);
    $rows = collect(range(1, 520))->map(fn () => [
        'id' => (string) Str::uuid(), 'type' => HelpTherapyInviteNotification::class, 'notifiable_type' => User::class, 'notifiable_id' => $client->id,
        'data' => json_encode(['helpRequestId' => $help->id, 'counsellorName' => 'Dr Zara Quist']), 'created_at' => now(), 'updated_at' => now(),
    ]);
    $rows->chunk(100)->each(fn ($chunk) => DB::table('notifications')->insert($chunk->all()));

    annBackfill();

    expect(DB::table('notifications')->where('type', HelpTherapyInviteNotification::class)->whereNull('ward_ref')->count())->toBe(0);
});

test('closing an account clears the reason they gave for declining, on the request too', function () {
    $proposer = User::factory()->adult()->create();
    $decliner = User::factory()->adult()->create();
    $request = annDeclined($proposer, $decliner, 'I see Dr Quist on Tuesdays');

    annAnonymise($decliner);

    expect($request->fresh()->data)->not->toHaveKey('reason')
        ->and($request->fresh()->data['proposedById'])->toBe($proposer->id);
});
