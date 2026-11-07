<?php

use App\Actions\Admin\GetAdminToDoCountsAction;
use App\Actions\CounsellorVerification\EnsureCanRevokeCounsellorVerificationAction;
use App\Actions\CounsellorVerification\EnsureCounsellorCanRequestVerificationAction;
use App\Actions\CounsellorVerification\EnsureCounsellorMayTakeOnClientsAction;
use App\Actions\CounsellorVerification\RecordEndedCounsellorVerificationsAction;
use App\Actions\CounsellorVerification\SendCounsellorVerificationRemindersAction;
use App\Actions\Link\PerformDiscussionRequestLinkAction;
use App\Actions\Link\PerformTherapyCounsellorLinkAction;
use App\Actions\Organization\EnsureCounsellorIsPlatformVerifiedAction;
use App\Actions\Payout\EnsureCanOnboardPayoutDestinationAction;
use App\Actions\Post\EnsureCanCreatePostAction;
use App\Actions\Request\CreateRequestAction;
use App\Actions\User\GetCounsellorCreationStepOfUserAction;
use App\DTOs\CreateLinkDTO;
use App\DTOs\CreatePostDTO;
use App\DTOs\CreateRequestDTO;
use App\DTOs\PayoutDestinationDTO;
use App\DTOs\VerifyCounsellorDTO;
use App\Enums\AdministratorTypeEnum;
use App\Enums\CounsellorVerificationEventEnum;
use App\Enums\CounsellorVerificationStateEnum;
use App\Enums\LicensingAuthorityTypeEnum;
use App\Enums\LicensingTypeEnum;
use App\Enums\RequestStatusEnum;
use App\Enums\RequestTypeEnum;
use App\Enums\TherapyStatusEnum;
use App\Exceptions\CounsellorException;
use App\Exceptions\OrganizationException;
use App\Exceptions\PayoutException;
use App\Exceptions\PostException;
use App\Exceptions\VerificationRequestRequirementException;
use App\Http\Resources\CounsellorMiniResource;
use App\Http\Resources\CounsellorResource;
use App\Models\Administrator;
use App\Models\Counsellor;
use App\Models\CounsellorPayoutAccount;
use App\Models\CounsellorVerificationEvent;
use App\Models\Discussion;
use App\Models\GroupTherapy;
use App\Models\License;
use App\Models\LicensingAuthority;
use App\Models\Request;
use App\Models\Therapy;
use App\Models\User;
use App\Notifications\CounsellorVerificationEndedNotification;
use App\Notifications\CounsellorVerificationEndingAdminNotification;
use App\Notifications\CounsellorVerificationEndingNotification;
use App\Notifications\CounsellorVerificationRevokedNotification;
use App\Support\CounsellorVerification;
use Illuminate\Http\Request as HttpRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

// SCRUM-30/31: verification state and badge data, accepting and renewing, the reminder and ended
// sweeps, revoking, the "new work only" gates and the admin endpoints. Helpers are file-local and
// cvr-prefixed (--parallel).

beforeEach(fn () => Notification::fake());

function cvrAdmin(string $type = 'NORMAL'): User
{
    return User::factory()->adult()->has(Administrator::factory()->state(['type' => $type === 'SUPER' ? AdministratorTypeEnum::super->value : AdministratorTypeEnum::normal->value]))->create();
}

function cvrCounsellor(array $state = []): Counsellor
{
    return Counsellor::factory()->create(['user_id' => User::factory()->adult()->create()->id, ...$state]);
}

function cvrLicense(Counsellor $counsellor, string $number): License
{
    $authority = LicensingAuthority::factory()->create(['name' => 'Board '.uniqid(), 'type' => LicensingAuthorityTypeEnum::govermental->value, 'license_type' => LicensingTypeEnum::number->value]);
    $license = new License(['number' => $number]);
    $license->licensingAuthority()->associate($authority);
    $license->for()->associate($counsellor);
    $license->save();

    return $license;
}

function cvrVerificationRequest(Counsellor $counsellor): Request
{
    return CreateRequestAction::new()->execute(CreateRequestDTO::new()->fromArray([
        'from' => $counsellor,
        'to' => cvrAdmin('SUPER'),
        'for' => $counsellor,
        'type' => RequestTypeEnum::counsellor->value,
        'data' => ['nationalIdLicense' => cvrLicense($counsellor, 'NID-'.uniqid())->id, 'otherLicense' => cvrLicense($counsellor, 'PRO-'.uniqid())->id],
    ]));
}

// A request made earlier (while they were verified), written directly -- CreateRequestAction would
// refuse it now (SCRUM-389's backstop).
function cvrEarlierRequest(string $type, $from, $to, $for): Request
{
    $request = new Request(['type' => $type, 'data' => [], 'status' => RequestStatusEnum::pending->value]);
    $request->from()->associate($from);
    $request->to()->associate($to);
    $request->for()->associate($for);
    $request->save();

    return $request;
}

function cvrRespond($test, User $admin, Request $request, string $response)
{
    return $test->actingAs($admin)->postJson(route('requests.respond', ['requestId' => $request->id]), ['response' => $response]);
}

function cvrEvents(Counsellor $counsellor, ?CounsellorVerificationEventEnum $event = null)
{
    return CounsellorVerificationEvent::query()->where('counsellor_id', $counsellor->id)
        ->when($event, fn ($query) => $query->where('event', $event->value))->get();
}

test('the state reads the columns: verified, never verified, ended, revoked, and ended at the boundary', function () {
    expect(cvrCounsellor()->verificationState())->toBe(CounsellorVerificationStateEnum::verified)
        ->and(Counsellor::factory()->unverified()->make()->verificationState())->toBe(CounsellorVerificationStateEnum::unverified)
        ->and(Counsellor::factory()->lapsed()->make()->verificationState())->toBe(CounsellorVerificationStateEnum::lapsed)
        ->and(Counsellor::factory()->make(['verification_revoked_at' => now()])->verificationState())->toBe(CounsellorVerificationStateEnum::revoked)
        // A verified_at the backfill never reached counts as ended, the cautious reading.
        ->and(Counsellor::factory()->make(['verification_expires_at' => null])->isVerified())->toBeFalse();

    $counsellor = cvrCounsellor(['verification_expires_at' => now()->addMinute()]);
    expect($counsellor->isVerified())->toBeTrue();
    $this->travel(2)->minutes();
    expect($counsellor->isVerified())->toBeFalse()
        ->and($counsellor->wasEverVerified())->toBeTrue()
        ->and($counsellor->user->isVerifiedCounsellor())->toBeFalse();
});

test('accepting a first verification sets the end date and records who, which licences and until when', function () {
    $counsellor = cvrCounsellor(['verified_at' => null, 'verification_expires_at' => null]);
    $request = cvrVerificationRequest($counsellor);
    $admin = cvrAdmin();

    cvrRespond($this, $admin, $request, 'accepted')->assertSuccessful();

    $counsellor->refresh();
    $event = cvrEvents($counsellor)->sole();
    expect($counsellor->isVerified())->toBeTrue()
        ->and($counsellor->verification_expires_at->diffInDays(now()->addMonths(12), true))->toBeLessThan(1)
        ->and($event->event)->toBe(CounsellorVerificationEventEnum::verified)
        ->and($event->actor_user_id)->toBe($admin->id)
        ->and($event->request_id)->toBe($request->id)
        ->and($event->license_ids)->toBe([$request->data['nationalIdLicense'], $request->data['otherLicense']])
        ->and($event->expires_at->equalTo($counsellor->verification_expires_at))->toBeTrue();

    // Answering it again changes nothing and records nothing.
    cvrRespond($this, $admin, $request, 'accepted');
    expect(cvrEvents($counsellor))->toHaveCount(1);
});

test('renewing early keeps the first verified date and extends from the current end; an ended one restarts from now', function () {
    $firstVerified = now()->subYear()->startOfSecond();
    $renewing = cvrCounsellor(['verified_at' => $firstVerified, 'verification_expires_at' => now()->addDays(10)->startOfSecond()]);
    $oldEnd = $renewing->verification_expires_at->copy();

    cvrRespond($this, cvrAdmin(), cvrVerificationRequest($renewing), 'accepted')->assertSuccessful();

    $renewing->refresh();
    expect($renewing->verified_at->equalTo($firstVerified))->toBeTrue()
        ->and($renewing->verification_expires_at->equalTo($oldEnd->copy()->addMonths(12)))->toBeTrue()
        ->and(cvrEvents($renewing)->sole()->event)->toBe(CounsellorVerificationEventEnum::reverified);

    $revoked = cvrCounsellor(['verification_revoked_at' => now()->subDay()]);
    cvrRespond($this, cvrAdmin(), cvrVerificationRequest($revoked), 'accepted')->assertSuccessful();
    $revoked->refresh();
    expect($revoked->isVerified())->toBeTrue()
        ->and($revoked->verification_revoked_at)->toBeNull()
        ->and($revoked->verification_expires_at->diffInDays(now()->addMonths(12), true))->toBeLessThan(1);
});

test('a rejected renewal is recorded and leaves the current verification until it ends', function () {
    $counsellor = cvrCounsellor(['verification_expires_at' => now()->addDays(10)]);

    cvrRespond($this, cvrAdmin(), cvrVerificationRequest($counsellor), 'rejected')->assertSuccessful();

    expect($counsellor->fresh()->isVerified())->toBeTrue()
        ->and(cvrEvents($counsellor, CounsellorVerificationEventEnum::rejected))->toHaveCount(1);
});

test('a counsellor may ask to re-verify only within the renewal window, or once it has ended or been revoked', function () {
    $check = fn (Counsellor $counsellor) => EnsureCounsellorCanRequestVerificationAction::new()->execute(VerifyCounsellorDTO::new()->fromArray(['counsellor' => $counsellor]));

    expect(fn () => $check(cvrCounsellor()))->toThrow(VerificationRequestRequirementException::class, 'You can re-verify from 30 days before it ends.');

    $check(cvrCounsellor(['verification_expires_at' => now()->addDays(29)]));
    $check(Counsellor::factory()->unverified()->create(['user_id' => User::factory()->create()->id]));
    $check(Counsellor::factory()->lapsed()->create(['user_id' => User::factory()->create()->id]));
    $check(cvrCounsellor(['verification_revoked_at' => now()]));
    expect(true)->toBeTrue();
});

test('reminders go once per window, the narrowest one after a missed run, and not while a request is waiting', function () {
    $approver = cvrAdmin();
    $counsellor = cvrCounsellor(['verification_expires_at' => now()->addDays(20)]);
    $accepted = cvrVerificationRequest($counsellor);
    $accepted->forceFill(['status' => RequestStatusEnum::accepted->value, 'responded_by_id' => $approver->id, 'responded_at' => now()->subYear()])->save();
    $notYet = cvrCounsellor(['verification_expires_at' => now()->addDays(40)]);
    $waiting = cvrCounsellor(['verification_expires_at' => now()->addDays(5)]);
    cvrVerificationRequest($waiting);

    expect(SendCounsellorVerificationRemindersAction::new()->execute())->toBe(1)
        ->and(SendCounsellorVerificationRemindersAction::new()->execute())->toBe(0);

    $reminder = cvrEvents($counsellor, CounsellorVerificationEventEnum::reminderSent)->sole();
    expect($reminder->window_days)->toBe(30)
        ->and(cvrEvents($notYet))->toBeEmpty()
        ->and(cvrEvents($waiting))->toBeEmpty();
    Notification::assertSentTo($counsellor, CounsellorVerificationEndingNotification::class);
    Notification::assertSentTo($approver, CounsellorVerificationEndingAdminNotification::class);

    // The 7-day one goes once it's within 7 days (and the other one is now within 30).
    $this->travel(14)->days();
    expect(SendCounsellorVerificationRemindersAction::new()->execute())->toBe(2)
        ->and(cvrEvents($counsellor, CounsellorVerificationEventEnum::reminderSent)->pluck('window_days')->all())->toBe([30, 7])
        ->and(cvrEvents($notYet, CounsellorVerificationEventEnum::reminderSent)->pluck('window_days')->all())->toBe([30]);

    // A counsellor first reached with 3 days left gets only the 7-day reminder.
    $late = cvrCounsellor(['verification_expires_at' => now()->addDays(3)]);
    SendCounsellorVerificationRemindersAction::new()->execute();
    expect(cvrEvents($late, CounsellorVerificationEventEnum::reminderSent)->pluck('window_days')->all())->toBe([7]);
});

test('the approver is not told when they are no longer an administrator, or for a verification with no recorded approver', function () {
    $former = cvrAdmin();
    $counsellor = cvrCounsellor(['verification_expires_at' => now()->addDays(20)]);
    $accepted = cvrVerificationRequest($counsellor);
    $accepted->forceFill(['status' => RequestStatusEnum::accepted->value, 'responded_by_id' => $former->id, 'responded_at' => now()->subYear()])->save();
    $former->administrator()->delete();
    cvrCounsellor(['verification_expires_at' => now()->addDays(20)]);

    expect(SendCounsellorVerificationRemindersAction::new()->execute())->toBe(2);
    Notification::assertNotSentTo($former->fresh(), CounsellorVerificationEndingAdminNotification::class);
});

test('an ended verification is recorded and the counsellor told once, and the admin counts start again', function () {
    $ended = cvrCounsellor(['verification_expires_at' => now()->subDays(2)]);
    $revoked = cvrCounsellor(['verification_expires_at' => now()->subDays(2), 'verification_revoked_at' => now()->subDays(5)]);
    Cache::forever('admin-todo:generation', 'before');

    expect(RecordEndedCounsellorVerificationsAction::new()->execute())->toBe(1)
        ->and(RecordEndedCounsellorVerificationsAction::new()->execute())->toBe(0)
        ->and(cvrEvents($ended, CounsellorVerificationEventEnum::lapsed))->toHaveCount(1)
        ->and(cvrEvents($revoked))->toBeEmpty()
        ->and(Cache::get('admin-todo:generation'))->not->toBe('before');
    Notification::assertSentToTimes($ended, CounsellorVerificationEndedNotification::class, 1);
});

test('an administrator revokes with a reason: the counsellor is told, a waiting request is closed, it is recorded', function () {
    $admin = cvrAdmin();
    $counsellor = cvrCounsellor(['verification_expires_at' => now()->addDays(10)]);
    $waiting = cvrVerificationRequest($counsellor);

    $this->actingAs($admin)->postJson(route('admin.counsellors.verification.revoke', ['counsellorId' => $counsellor->id]), ['reason' => 'The licence number was reported as fake.'])
        ->assertOk()->assertJsonPath('verification.state', 'revoked');

    $counsellor->refresh();
    $event = cvrEvents($counsellor, CounsellorVerificationEventEnum::revoked)->sole();
    expect($counsellor->isVerified())->toBeFalse()
        ->and($counsellor->verificationState())->toBe(CounsellorVerificationStateEnum::revoked)
        ->and($counsellor->wasEverVerified())->toBeTrue()
        ->and($event->actor_user_id)->toBe($admin->id)
        ->and($event->reason)->toBe('The licence number was reported as fake.')
        ->and($waiting->fresh()->status)->toBe(RequestStatusEnum::inconsequencial->value);
    Notification::assertSentTo($counsellor, CounsellorVerificationRevokedNotification::class);

    // Nothing left to revoke.
    $this->actingAs($admin)->postJson(route('admin.counsellors.verification.revoke', ['counsellorId' => $counsellor->id]), ['reason' => 'Again, for the same reason.'])
        ->assertStatus(422)->assertJsonPath('message', EnsureCanRevokeCounsellorVerificationAction::NOT_VERIFIED);
});

test('revoking needs an administrator who is not the counsellor, and a real reason', function () {
    $counsellor = cvrCounsellor();
    $url = route('admin.counsellors.verification.revoke', ['counsellorId' => $counsellor->id]);

    $this->actingAs(User::factory()->create())->postJson($url, ['reason' => 'Not an administrator here.'])->assertForbidden();
    $this->actingAs(cvrAdmin())->postJson($url, ['reason' => 'short'])->assertStatus(422)->assertJsonPath('message', EnsureCanRevokeCounsellorVerificationAction::REASON);

    $ownAdmin = $counsellor->user;
    Administrator::factory()->create(['user_id' => $ownAdmin->id]);
    $this->actingAs($ownAdmin->fresh())->postJson($url, ['reason' => 'Revoking my own verification.'])->assertForbidden();

    // A super administrator's counsellor profile only by another super administrator.
    $superCounsellor = cvrCounsellor();
    Administrator::factory()->create(['user_id' => $superCounsellor->user_id, 'type' => AdministratorTypeEnum::super->value]);
    $superUrl = route('admin.counsellors.verification.revoke', ['counsellorId' => $superCounsellor->id]);
    $this->actingAs(cvrAdmin())->postJson($superUrl, ['reason' => 'An ordinary admin revoking a super admin.'])->assertForbidden();
    $this->actingAs(cvrAdmin('SUPER'))->postJson($superUrl, ['reason' => 'Another super admin revoking it.'])->assertOk();

    expect($counsellor->fresh()->isVerified())->toBeTrue()->and(cvrEvents($counsellor))->toBeEmpty();
});

test('a verification that has already ended can still be revoked; a never-verified counsellor has nothing to revoke', function () {
    $lapsed = cvrCounsellor(['verification_expires_at' => now()->subDays(5)]);
    $this->actingAs(cvrAdmin())->postJson(route('admin.counsellors.verification.revoke', ['counsellorId' => $lapsed->id]), ['reason' => 'Fraud found after it ended.'])
        ->assertOk()->assertJsonPath('verification.state', 'revoked');

    $never = Counsellor::factory()->unverified()->create(['user_id' => User::factory()->create()->id]);
    $this->actingAs(cvrAdmin())->postJson(route('admin.counsellors.verification.revoke', ['counsellorId' => $never->id]), ['reason' => 'Nothing to revoke here.'])
        ->assertStatus(422)->assertJsonPath('message', EnsureCanRevokeCounsellorVerificationAction::NOT_VERIFIED);
});

test('an administrator who is also a counsellor can\'t read their own verification history', function () {
    $counsellor = cvrCounsellor();
    Administrator::factory()->create(['user_id' => $counsellor->user_id]);

    $this->actingAs($counsellor->user->fresh())->getJson(route('admin.counsellors.verification.events', ['counsellorId' => $counsellor->id]))->assertNotFound();
});

test('a counsellor who is not currently verified takes on no new clients, whoever asks', function () {
    $gate = EnsureCounsellorMayTakeOnClientsAction::new();

    foreach ([Counsellor::factory()->unverified()->make(), Counsellor::factory()->lapsed()->make(), Counsellor::factory()->make(['verification_revoked_at' => now()])] as $counsellor) {
        expect(fn () => $gate->execute($counsellor))->toThrow(CounsellorException::class, EnsureCounsellorMayTakeOnClientsAction::NOT_VERIFIED)
            ->and(fn () => $gate->execute($counsellor, asTarget: true))->toThrow(CounsellorException::class, EnsureCounsellorMayTakeOnClientsAction::TARGET_NOT_VERIFIED);
    }

    $gate->execute(Counsellor::factory()->make());
    $gate->execute(null);
    expect(true)->toBeTrue();
});

test('a client can\'t ask a lapsed counsellor to assist, and a lapsed counsellor can\'t offer or accept', function () {
    $client = User::factory()->adult()->create();
    $lapsed = cvrCounsellor(['verification_expires_at' => now()->subDay()]);
    $therapy = Therapy::factory()->create(['addedby_type' => User::class, 'addedby_id' => $client->id, 'status' => TherapyStatusEnum::pending->value, 'public' => true, 'counsellor_id' => null]);

    $this->actingAs($client)->postJson(route('therapies.assist', ['therapyId' => $therapy->id]), ['counsellorIds' => [$lapsed->id]])
        ->assertStatus(422)->assertJsonPath('message', EnsureCounsellorMayTakeOnClientsAction::TARGET_NOT_VERIFIED);
    $this->actingAs($lapsed->user)->postJson(route('therapies.assist', ['therapyId' => $therapy->id]))
        ->assertStatus(422)->assertJsonPath('message', EnsureCounsellorMayTakeOnClientsAction::NOT_VERIFIED);

    // A request sent while they were verified can't be accepted once it has ended.
    $request = cvrEarlierRequest(RequestTypeEnum::therapy->value, $client, $lapsed, $therapy);
    $this->actingAs($lapsed->user)->postJson(route('requests.respond', ['requestId' => $request->id]), ['response' => 'accepted'])->assertStatus(422);
    expect($therapy->fresh()->counsellor_id)->toBeNull()
        ->and($request->fresh()->status)->toBe(RequestStatusEnum::pending->value);

    // Declining stays open to them.
    $this->actingAs($lapsed->user)->postJson(route('requests.respond', ['requestId' => $request->id]), ['response' => 'rejected'])->assertSuccessful();
});

test('after it ends: the onboarding step drops back, a first payout destination is refused, changing one is not', function () {
    $lapsed = cvrCounsellor(['verification_expires_at' => now()->subDay()]);
    $dto = fn (Counsellor $counsellor) => PayoutDestinationDTO::new()->fromArray(['user' => $counsellor->user->fresh()]);

    expect(GetCounsellorCreationStepOfUserAction::new()->execute($lapsed->user))->toBe(1)
        ->and(fn () => EnsureCanOnboardPayoutDestinationAction::new()->execute($dto($lapsed)))->toThrow(PayoutException::class);

    CounsellorPayoutAccount::factory()->create(['counsellor_id' => $lapsed->id, 'currency' => 'GHS']);
    EnsureCanOnboardPayoutDestinationAction::new()->execute($dto($lapsed));

    $revoked = cvrCounsellor(['verification_revoked_at' => now()]);
    CounsellorPayoutAccount::factory()->create(['counsellor_id' => $revoked->id, 'currency' => 'GHS']);
    expect(fn () => EnsureCanOnboardPayoutDestinationAction::new()->execute($dto($revoked)))->toThrow(PayoutException::class);
});

test('anyone sees only the public state; the counsellor and administrators see the dates; a revocation reads as ended', function () {
    $revoked = cvrCounsellor(['verification_revoked_at' => now()]);
    $resolve = function ($resource, ?User $viewer) {
        $request = HttpRequest::create('/');
        $request->setUserResolver(fn () => $viewer);

        return $resource->toArray($request)['verification'];
    };

    expect($resolve(new CounsellorResource($revoked), null))->toBe(['state' => 'lapsed'])
        ->and($resolve(new CounsellorResource($revoked), User::factory()->create()))->toBe(['state' => 'lapsed'])
        ->and($resolve(new CounsellorMiniResource($revoked), cvrAdmin()))->toBe(['state' => 'lapsed']);

    $own = $resolve(new CounsellorResource($revoked), $revoked->user);
    expect($own['state'])->toBe('revoked')
        ->and($own)->toHaveKeys(['verifiedSince', 'expiresAt', 'revokedAt', 'renewalOpen', 'mayRequest', 'hasPendingRequest']);

    $asAdmin = $resolve(new CounsellorResource($revoked), cvrAdmin());
    expect($asAdmin['state'])->toBe('revoked')->and($asAdmin)->not->toHaveKey('hasPendingRequest');

    // No licence data on the public profile any more.
    $request = HttpRequest::create('/');
    expect((new CounsellorResource($revoked))->toArray($request))->not->toHaveKeys(['verified', 'hasNationalIdentification', 'hasPendingCounsellorVerificationRequest']);
});

test('the history is append-only', function () {
    $counsellor = cvrCounsellor(['verification_revoked_at' => now()]);
    $event = CounsellorVerificationEvent::query()->create(['counsellor_id' => $counsellor->id, 'event' => CounsellorVerificationEventEnum::revoked, 'reason' => 'Kept.']);

    expect(fn () => $event->update(['reason' => 'Changed.']))->toThrow(LogicException::class)
        ->and(fn () => $event->delete())->toThrow(LogicException::class)
        ->and(fn () => DB::table('counsellor_verification_events')->where('id', $event->id)->update(['reason' => 'Changed.']))->toThrow(Exception::class)
        ->and(fn () => DB::table('counsellor_verification_events')->where('id', $event->id)->delete())->toThrow(Exception::class);
})->group('mysql-triggers');

test('administrators get the renewals list, the history (no licence numbers) and the renewals count; nobody else does', function () {
    $admin = cvrAdmin();
    $due = cvrCounsellor(['verification_expires_at' => now()->addDays(10)]);
    $dueAsked = cvrCounsellor(['verification_expires_at' => now()->addDays(10)]);
    cvrVerificationRequest($dueAsked);
    $ended = cvrCounsellor(['verification_expires_at' => now()->subDays(3)]);
    $fine = cvrCounsellor();
    Counsellor::factory()->unverified()->create(['user_id' => User::factory()->create()->id]);

    $rows = collect($this->actingAs($admin)->getJson(route('admin.counsellors.verification.renewals'))->assertOk()->json('data'))->keyBy('id');
    expect($rows->keys()->sort()->values()->all())->toBe(collect([$due->id, $dueAsked->id])->sort()->values()->all())
        ->and($rows[$dueAsked->id]['hasPendingRequest'])->toBeTrue();
    $endedRows = $this->actingAs($admin)->getJson(route('admin.counsellors.verification.renewals', ['filter' => 'ended']))->assertOk()->json('data');
    expect(collect($endedRows)->pluck('id')->all())->toBe([$ended->id])
        ->and($endedRows[0]['verification']['state'])->toBe('lapsed');

    cvrRespond($this, $admin, cvrVerificationRequest($fine), 'rejected');
    $events = $this->actingAs($admin)->getJson(route('admin.counsellors.verification.events', ['counsellorId' => $fine->id]))->assertOk()->json('data');
    expect($events)->toHaveCount(1)
        ->and($events[0]['event'])->toBe('rejected')
        ->and(json_encode($events))->not->toContain('NID-')->not->toContain('PRO-');

    // Due, with nothing asked for yet: one.
    expect(GetAdminToDoCountsAction::new()->counts($admin)['counsellorVerificationRenewals'])->toBe(1);

    $user = User::factory()->create();
    $this->actingAs($user)->getJson(route('admin.counsellors.verification.renewals'))->assertForbidden();
    $this->actingAs($user)->getJson(route('admin.counsellors.verification.events', ['counsellorId' => $fine->id]))->assertForbidden();
});

test('the backfill gives counsellors already verified 12 months from when it runs plus a spread of up to two months, and a baseline entry', function () {
    $this->travelTo(now()->startOfSecond());
    $old = collect(range(1, 5))->map(fn () => cvrCounsellor(['verified_at' => now()->subYears(3), 'verification_expires_at' => null]));
    $never = Counsellor::factory()->unverified()->create(['user_id' => User::factory()->create()->id]);
    $migration = require database_path('migrations/2026_11_10_300000_backfill_counsellor_verification_expiry.php');

    $migration->up();
    $migration->up();

    foreach ($old as $counsellor) {
        $counsellor = $counsellor->fresh();
        // SCRUM-389: 12 months, plus 0-60 days fixed by the counsellor's id.
        $expected = now()->addMonths(12)->addDays($counsellor->id % 61);
        $baseline = cvrEvents($counsellor, CounsellorVerificationEventEnum::baseline);

        expect($counsellor->isVerified())->toBeTrue()
            ->and($counsellor->verification_expires_at->equalTo($expected))->toBeTrue()
            ->and($counsellor->verification_expires_at->gte(now()->addMonths(12)))->toBeTrue()
            ->and($counsellor->verification_expires_at->lte(now()->addMonths(12)->addDays(60)))->toBeTrue()
            ->and($baseline)->toHaveCount(1)
            ->and($baseline->sole()->expires_at->equalTo($expected))->toBeTrue();
    }

    expect($old->map(fn ($counsellor) => $counsellor->fresh()->verification_expires_at->toDateString())->unique())->toHaveCount(5)
        ->and($never->fresh()->verification_expires_at)->toBeNull()
        ->and(cvrEvents($never))->toBeEmpty();
});

test('the public badge state costs no query, so a list of counsellors adds none for it', function () {
    $counsellors = collect([cvrCounsellor(), cvrCounsellor(['verification_expires_at' => now()->subDay()]), Counsellor::factory()->unverified()->create(['user_id' => User::factory()->create()->id])])
        ->map(fn (Counsellor $counsellor) => Counsellor::query()->find($counsellor->id));

    DB::enableQueryLog();
    $states = $counsellors->map(fn (Counsellor $counsellor) => CounsellorVerification::projection($counsellor)['state'])->all();

    expect(DB::getQueryLog())->toBeEmpty()
        ->and($states)->toBe(['verified', 'lapsed', 'unverified']);
});

test('every way of taking on new work refuses a counsellor whose verification has ended', function () {
    $lapsed = cvrCounsellor(['verification_expires_at' => now()->subDay()]);
    $client = User::factory()->adult()->create();

    // A client starting a therapy with them, or a group inviting them.
    $this->actingAs($client)->postJson('api/therapies', [
        'name' => 'New therapy', 'backgroundStory' => 'A story.', 'anonymous' => false, 'allowInPerson' => false, 'public' => true,
        'sessionType' => 'ONCE', 'paymentType' => 'FREE', 'maxSessions' => 1, 'counsellorId' => $lapsed->id,
    ])->assertStatus(422)->assertJsonPath('message', EnsureCounsellorMayTakeOnClientsAction::TARGET_NOT_VERIFIED);
    $group = ['name' => 'New group', 'about' => 'About it.', 'anonymous' => false, 'allowInPerson' => false, 'allowAnyone' => true, 'public' => true, 'sessionType' => 'ONCE', 'paymentType' => 'FREE'];
    $this->actingAs($client)->postJson('api/group-therapies', [...$group, 'counsellorIds' => [$lapsed->id]])
        ->assertStatus(422)->assertJsonPath('message', EnsureCounsellorMayTakeOnClientsAction::TARGET_NOT_VERIFIED);
    // Starting one as its counsellor.
    $this->actingAs($lapsed->user)->postJson('api/group-therapies', [...$group, 'counsellorId' => true])
        ->assertStatus(422)->assertJsonPath('message', EnsureCounsellorMayTakeOnClientsAction::NOT_VERIFIED);
    expect(Therapy::query()->where('name', 'New therapy')->exists())->toBeFalse()
        ->and(GroupTherapy::query()->where('name', 'New group')->exists())->toBeFalse();

    // A therapy's counsellor link, and a discussion link.
    expect(fn () => PerformTherapyCounsellorLinkAction::new()->execute(CreateLinkDTO::new()->fromArray(['user' => $lapsed->user])))
        ->toThrow(CounsellorException::class, EnsureCounsellorMayTakeOnClientsAction::NOT_VERIFIED)
        ->and(fn () => PerformDiscussionRequestLinkAction::new()->execute(CreateLinkDTO::new()->fromArray(['user' => $lapsed->user])))
        ->toThrow(CounsellorException::class, EnsureCounsellorMayTakeOnClientsAction::NOT_VERIFIED_FOR_DISCUSSION);

    // Posting, and a new organisation link.
    expect(fn () => EnsureCanCreatePostAction::new()->execute(CreatePostDTO::new()->fromArray(['user' => $lapsed->user, 'addedby' => $lapsed])))
        ->toThrow(PostException::class)
        ->and(fn () => EnsureCounsellorIsPlatformVerifiedAction::new()->execute($lapsed))
        ->toThrow(OrganizationException::class);

    // A client accepting the offer they made while verified is told it's the counsellor's.
    $therapy = Therapy::factory()->create(['addedby_type' => User::class, 'addedby_id' => $client->id, 'status' => TherapyStatusEnum::pending->value, 'counsellor_id' => null]);
    $offer = cvrEarlierRequest(RequestTypeEnum::therapy->value, $lapsed, $client, $therapy);
    $this->actingAs($client)->postJson(route('requests.respond', ['requestId' => $offer->id]), ['response' => 'accepted'])
        ->assertStatus(422)->assertJsonPath('error', EnsureCounsellorMayTakeOnClientsAction::TARGET_NOT_VERIFIED);
    expect($therapy->fresh()->counsellor_id)->toBeNull();
});

test('a discussion invitation can\'t be accepted once the counsellor\'s verification has ended', function () {
    $lapsed = cvrCounsellor(['verification_expires_at' => now()->subDay()]);
    $owner = cvrCounsellor();
    $discussion = Discussion::factory()->create(['addedby_type' => Counsellor::class, 'addedby_id' => $owner->id]);
    $invite = cvrEarlierRequest(RequestTypeEnum::discussion->value, $owner, $lapsed, $discussion);

    $this->actingAs($lapsed->user)->postJson(route('requests.respond', ['requestId' => $invite->id]), ['response' => 'accepted'])
        ->assertStatus(422)->assertJsonPath('error', EnsureCounsellorMayTakeOnClientsAction::NOT_VERIFIED_FOR_DISCUSSION);
    expect($invite->fresh()->status)->toBe(RequestStatusEnum::pending->value)
        ->and(DB::table('counsellor_discussion')->where('counsellor_id', $lapsed->id)->exists())->toBeFalse();
});

test('a group run only by counsellors whose verification isn\'t current takes no new members, until one is verified', function () {
    $lapsed = cvrCounsellor(['verification_expires_at' => now()->subDay()]);
    $group = GroupTherapy::factory()->create(['addedby_type' => Counsellor::class, 'addedby_id' => $lapsed->id, 'allow_anyone' => true, 'max_users' => 10, 'status' => TherapyStatusEnum::pending->value]);
    $joiner = User::factory()->adult()->create();

    $this->actingAs($joiner)->postJson("api/group-therapies/{$group->id}/join")
        ->assertStatus(422)->assertJsonPath('message', EnsureCounsellorMayTakeOnClientsAction::GROUP_NOT_ADMITTING);
    expect($group->fresh()->isParticipant($joiner))->toBeFalse();

    $lapsed->forceFill(['verification_expires_at' => now()->addYear()])->save();
    $this->actingAs($joiner)->postJson("api/group-therapies/{$group->id}/join")->assertSuccessful();
    expect($group->fresh()->isParticipant($joiner))->toBeTrue();
});

test('a revocation reason can\'t add a heading or a link to the email', function () {
    $counsellor = cvrCounsellor();
    $html = (string) (new CounsellorVerificationRevokedNotification("# You are verified again\n[Click here](https://example.com)"))
        ->toMail($counsellor)->render();

    expect($html)->not->toMatch('/<h[1-6][^>]*>\s*You are verified again/')
        ->not->toContain('href="https://example.com"');
});
