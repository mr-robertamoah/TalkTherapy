<?php

use App\Actions\Guardianship\FindMinorsWithoutActiveGuardianAction;
use App\Actions\Guardianship\HandleGuardianLossAction;
use App\Actions\Request\RespondToSafeguardingExceptionRequestAction;
use App\Actions\SafeguardingException\EndSafeguardingExceptionAction;
use App\Actions\SafeguardingException\EnsureCanManageSafeguardingExceptionAction;
use App\Actions\SafeguardingException\GetPausedGroupMembersAction;
use App\Actions\SafeguardingException\GrantSafeguardingExceptionAction;
use App\Actions\SafeguardingException\SendSafeguardingExceptionRequestAction;
use App\Actions\Suspension\ApplySuspensionAction;
use App\Actions\Therapy\EnsureTherapyCanProceedAction;
use App\Actions\User\AnonymiseClosedAccountAction;
use App\Actions\User\EnsureDobChangeIsAllowedAction;
use App\Actions\Video\EnsureVideoIsAvailableForSessionAction;
use App\DTOs\EnsureDobChangeIsAllowedDTO;
use App\DTOs\RequestResponseDTO;
use App\Enums\AdministratorTypeEnum;
use App\Enums\ConstantsEnum;
use App\Enums\ReportStatusEnum;
use App\Enums\RequestStatusEnum;
use App\Enums\RequestTypeEnum;
use App\Enums\SessionStatusEnum;
use App\Enums\SuspensionScopeEnum;
use App\Enums\TherapyStatusEnum;
use App\Events\GuardianAccessChangedEvent;
use App\Events\TherapyAccessChangedEvent;
use App\Exceptions\DobChangeRequiresApprovalException;
use App\Exceptions\GuardianRequiredException;
use App\Exceptions\SafeguardingExceptionRefusedException;
use App\Exceptions\VideoException;
use App\Http\Resources\GroupTherapyResource;
use App\Http\Resources\RequestResource;
use App\Http\Resources\TherapyResource;
use App\Jobs\HandleGuardianLossJob;
use App\Models\Administrator;
use App\Models\Counsellor;
use App\Models\GroupTherapy;
use App\Models\Guardianship;
use App\Models\Report;
use App\Models\Request;
use App\Models\SafeguardingException;
use App\Models\Session;
use App\Models\Suspension;
use App\Models\Therapy;
use App\Models\User;
use App\Notifications\ClientAccessPausedNotification;
use App\Notifications\ClientAccessResumedNotification;
use App\Notifications\GuardianAccessResumedNotification;
use App\Notifications\SafeguardingExceptionEndingSoonNotification;
use App\Notifications\SafeguardingExceptionRequestDecidedNotification;
use App\Notifications\SafeguardingExceptionRequestedNotification;
use App\Notifications\TherapyContinuationNotification;
use App\Services\SafeguardingExceptionService;
use Illuminate\Http\Request as HttpRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;

// SCRUM-381: the super administrator's safeguarding exception for a minor paused for a missing
// guardian, counsellors' requests for one, its sweeps, and group counsellors being told about a
// member's pause. Helpers are file-local and sge-prefixed (--parallel).

const SGE_REASON = 'Confidential: the family situation behind this exception.';

// A 15-year-old with no guardian, in a 1:1 with a counsellor (with a live online session).
function sgeMinor(): array
{
    $minor = User::factory()->create(['dob' => now()->subYears(15)->toDateString(), 'firstName' => 'Kit', 'lastName' => 'Lane', 'email_verified_at' => now()]);
    $counsellorUser = User::factory()->adult()->create(['email_verified_at' => now()]);
    $counsellor = Counsellor::factory()->create(['user_id' => $counsellorUser->id]);
    $therapy = Therapy::factory()->create([
        'addedby_type' => User::class, 'addedby_id' => $minor->id, 'counsellor_id' => $counsellor->id,
        'status' => TherapyStatusEnum::in_session->value, 'anonymous' => false, 'public' => false, 'client_was_minor_at_creation' => true,
    ]);

    return compact('minor', 'counsellorUser', 'counsellor', 'therapy');
}

// A group run by another counsellor, with $minor as a member (anonymous by default).
function sgeGroup(User $minor, bool $anonymous = true): array
{
    $counsellorUser = User::factory()->adult()->create(['email_verified_at' => now()]);
    $counsellor = Counsellor::factory()->create(['user_id' => $counsellorUser->id]);
    $group = GroupTherapy::factory()->create(['addedby_type' => Counsellor::class, 'addedby_id' => $counsellor->id, 'status' => TherapyStatusEnum::in_session->value, 'anonymous' => false]);
    $group->counsellors()->attach($counsellor->id, ['state' => 'ACTIVE', 'role' => 'NORMAL']);
    $group->users()->attach($minor->id, ['anonymous' => $anonymous, 'was_minor_at_join' => true]);

    return ['group' => $group, 'groupCounsellorUser' => $counsellorUser, 'groupCounsellor' => $counsellor];
}

function sgeSuperAdmin(): User
{
    return User::factory()->adult()->has(Administrator::factory()->state(['type' => AdministratorTypeEnum::super->value]))->create();
}

function sgeAdmin(): User
{
    return User::factory()->adult()->has(Administrator::factory()->state(['type' => AdministratorTypeEnum::normal->value]))->create();
}

function sgeException(User $ward, array $attributes = []): SafeguardingException
{
    return SafeguardingException::query()->create([
        'ward_id' => $ward->id, 'granted_by' => sgeSuperAdmin()->id, 'reason' => SGE_REASON,
        'starts_at' => now()->subDay(), 'ends_at' => now()->addDays(10), ...$attributes,
    ]);
}

function sgeGuardian(User $ward): User
{
    $guardian = User::factory()->adult()->create();
    Guardianship::query()->create(['guardian_id' => $guardian->id, 'ward_id' => $ward->id, 'ward_was_minor_at_creation' => true]);

    return $guardian;
}

function sgeView(User $viewer, $resource): array
{
    return $resource->resolve(tap(HttpRequest::create('/'), fn ($r) => $r->setUserResolver(fn () => $viewer)));
}

function sgeMail($notification, User $to): string
{
    $mail = $notification->toMail($to);

    return implode("\n", [$mail->subject, ...$mail->introLines, ...$mail->outroLines]);
}

function sgeSuspendCounsellor(Counsellor $counsellor): void
{
    $client = User::factory()->adult()->create();
    $therapy = Therapy::factory()->create(['addedby_type' => User::class, 'addedby_id' => $client->id, 'counsellor_id' => $counsellor->id]);
    $report = new Report(['description' => 'Conduct', 'data' => ['counsellorId' => $counsellor->id]]);
    $report->addedby()->associate($client);
    $report->reportable()->associate($therapy);
    $report->save();
    $report->forceFill(['status' => ReportStatusEnum::resolved->value, 'outcome' => 'suspension_recommended', 'resolution_note' => 'Serious'])->save();
    ApplySuspensionAction::new()->execute(sgeSuperAdmin(), $report, SuspensionScopeEnum::counsellor, 30, 'A reason the person will read.');
}

function sgeAsk(User $sender, Therapy|GroupTherapy $therapy, array $payload = [])
{
    $route = $therapy instanceof GroupTherapy
        ? route('api.safeguarding_exception_requests.group', ['groupTherapyId' => $therapy->id])
        : route('api.safeguarding_exception_requests.therapy', ['therapyId' => $therapy->id]);

    return test()->actingAs($sender)->postJson($route, ['reason' => 'They need to keep going.', 'proposedEndsAt' => now()->addDays(14)->toJSON(), ...$payload]);
}

beforeEach(function () {
    Notification::fake();
    Event::fake([GuardianAccessChangedEvent::class, TherapyAccessChangedEvent::class]);
});

// -- The rule ------------------------------------------------------------------------------------

test('only an exception in force lifts the pause: active yes; expired, ended early and not yet started no', function () {
    $f = sgeMinor();
    $paused = fn () => User::query()->find($f['minor']->id)->isPausedForMissingGuardian();

    expect($paused())->toBeTrue();
    $active = sgeException($f['minor']);
    expect($paused())->toBeFalse();

    $active->forceFill(['ended_at' => now(), 'end_kind' => SafeguardingException::END_REVOKED])->save();
    expect($paused())->toBeTrue();

    sgeException($f['minor'], ['starts_at' => now()->subDays(20), 'ends_at' => now()->subMinute()]);
    expect($paused())->toBeTrue();

    sgeException($f['minor'], ['starts_at' => now()->addHour(), 'ends_at' => now()->addDays(3)]);
    expect($paused())->toBeTrue();
});

test('fresh re-reads past the memo, and leaving guardians out still counts an exception', function () {
    $f = sgeMinor();
    $minor = User::query()->find($f['minor']->id);
    expect($minor->isPausedForMissingGuardian())->toBeTrue();

    sgeException($f['minor']);
    expect($minor->isPausedForMissingGuardian())->toBeTrue() // memoised
        ->and($minor->isPausedForMissingGuardian(fresh: true))->toBeFalse();

    $guarded = sgeMinor()['minor'];
    $guardian = sgeGuardian($guarded);
    expect($guarded->isPausedForMissingGuardian(exceptGuardianIds: [$guardian->id]))->toBeTrue();
    sgeException($guarded);
    expect($guarded->isPausedForMissingGuardian(exceptGuardianIds: [$guardian->id]))->toBeFalse();
});

test('a memoised exception stops counting the moment it runs out, before any sweep', function () {
    $this->freezeTime();
    $f = sgeMinor();
    $exception = sgeException($f['minor'], ['ends_at' => now()->addHours(2)]);
    $minor = User::query()->find($f['minor']->id);
    expect($minor->isPausedForMissingGuardian())->toBeFalse();

    $this->travelTo($exception->ends_at->copy()->addSecond());
    expect($minor->isPausedForMissingGuardian())->toBeTrue();
});

test('the write gate refuses, allows once granted, and refuses again right after the end (frozen, no sweep)', function () {
    $this->freezeTime();
    $f = sgeMinor();
    $gate = fn () => EnsureTherapyCanProceedAction::new()->execute(User::query()->find($f['minor']->id), $f['therapy']->fresh(), fresh: true);

    expect($gate)->toThrow(GuardianRequiredException::class);

    $exception = GrantSafeguardingExceptionAction::new()->execute(sgeSuperAdmin(), $f['minor'], 'Needed for now.', now()->addDays(2));
    $gate();

    $this->travelTo($exception->ends_at->copy()->addSecond());
    expect($gate)->toThrow(GuardianRequiredException::class);
});

test('FindMinorsWithoutActiveGuardianAction leaves out a minor covered by an exception', function () {
    $covered = sgeMinor()['minor'];
    $paused = sgeMinor()['minor'];
    sgeException($covered);

    $ids = collect(FindMinorsWithoutActiveGuardianAction::new()->execute())->flatten(1)->pluck('id');

    expect($ids)->toContain($paused->id)->not->toContain($covered->id);
});

test('the therapy and group pages show nobody paused under an exception', function () {
    $f = sgeMinor();
    $g = sgeGroup($f['minor']);

    expect(sgeView($f['counsellorUser'], new TherapyResource($f['therapy']->fresh()))['clientAccessPaused'])->toBeTrue()
        ->and(sgeView($g['groupCounsellorUser'], new GroupTherapyResource($g['group']->fresh()))['pausedMembers'])->toHaveCount(1);

    sgeException($f['minor']);

    expect(sgeView(User::query()->find($f['minor']->id), new TherapyResource($f['therapy']->fresh()))['viewerAccessPaused'])->toBeFalse()
        ->and(sgeView($f['counsellorUser'], new TherapyResource($f['therapy']->fresh()))['clientAccessPaused'])->toBeFalse()
        ->and(sgeView($g['groupCounsellorUser'], new GroupTherapyResource($g['group']->fresh()))['pausedMembers'])->toHaveCount(0);
});

test('video stays blocked under an exception: a guardian\'s consent is still needed', function () {
    $f = sgeMinor();
    $session = Session::factory()->create([
        'for_type' => Therapy::class, 'for_id' => $f['therapy']->id, 'addedby_type' => Counsellor::class, 'addedby_id' => $f['counsellor']->id,
        'status' => SessionStatusEnum::in_session->value, 'type' => 'ONLINE', 'start_time' => now()->subMinutes(5), 'end_time' => now()->addHour(),
    ]);
    sgeException($f['minor']);

    expect(fn () => EnsureVideoIsAvailableForSessionAction::new()->execute($session->fresh(), User::query()->find($f['minor']->id)))
        ->toThrow(VideoException::class);
});

// -- Granting ------------------------------------------------------------------------------------

test('only the super administrator grants, and not one who counsels the minor', function () {
    $f = sgeMinor();
    $grant = fn (User $admin) => GrantSafeguardingExceptionAction::new()->execute($admin, $f['minor'], 'Needed.', now()->addDays(7));

    expect(fn () => $grant(sgeAdmin()))->toThrow(SafeguardingExceptionRefusedException::class, EnsureCanManageSafeguardingExceptionAction::NOT_SUPER_ADMIN)
        ->and(fn () => $grant($f['counsellorUser']))->toThrow(SafeguardingExceptionRefusedException::class, EnsureCanManageSafeguardingExceptionAction::NOT_SUPER_ADMIN);

    $counsellingSuper = $f['counsellorUser'];
    Administrator::factory()->create(['user_id' => $counsellingSuper->id, 'type' => AdministratorTypeEnum::super->value]);
    expect(fn () => $grant(User::query()->find($counsellingSuper->id)))->toThrow(SafeguardingExceptionRefusedException::class, EnsureCanManageSafeguardingExceptionAction::COUNSELS_WARD);

    $group = sgeGroup($f['minor']);
    Administrator::factory()->create(['user_id' => $group['groupCounsellorUser']->id, 'type' => AdministratorTypeEnum::super->value]);
    expect(fn () => $grant(User::query()->find($group['groupCounsellorUser']->id)))->toThrow(SafeguardingExceptionRefusedException::class, EnsureCanManageSafeguardingExceptionAction::COUNSELS_WARD);

    expect(SafeguardingException::query()->count())->toBe(0);
    $grant(sgeSuperAdmin());
    expect(SafeguardingException::query()->count())->toBe(1);
});

test('a reason is required, and the end must be more than an hour and at most 30 days away', function () {
    $this->freezeTime();
    $f = sgeMinor();
    $admin = sgeSuperAdmin();
    $grant = fn (?string $reason, $endsAt) => GrantSafeguardingExceptionAction::new()->execute($admin, $f['minor'], $reason, $endsAt);

    expect(fn () => $grant('  ', now()->addDays(7)))->toThrow(SafeguardingExceptionRefusedException::class)
        ->and(fn () => $grant(str_repeat('a', 2001), now()->addDays(7)))->toThrow(SafeguardingExceptionRefusedException::class)
        ->and(fn () => $grant('Needed.', now()->addDays(30)->addMinute()))->toThrow(SafeguardingExceptionRefusedException::class, 'at most 30 days')
        ->and(fn () => $grant('Needed.', now()->addMinutes(59)))->toThrow(SafeguardingExceptionRefusedException::class)
        ->and(fn () => $grant('Needed.', 'not a date'))->toThrow(SafeguardingExceptionRefusedException::class);

    expect($grant('Needed.', now()->addDays(30)->toJSON())->fresh()->ends_at->toDateTimeString())->toBe(now()->addDays(30)->toDateTimeString());
});

test('no exception for someone who has a guardian', function () {
    $f = sgeMinor();
    sgeGuardian($f['minor']);

    expect(fn () => GrantSafeguardingExceptionAction::new()->execute(sgeSuperAdmin(), $f['minor'], 'Needed.', now()->addDays(7)))
        ->toThrow(SafeguardingExceptionRefusedException::class, GrantSafeguardingExceptionAction::NOT_NEEDED);
});

test('granting announces the resume once, neutrally, with the end date and never the reason', function () {
    $f = sgeMinor();
    $g = sgeGroup($f['minor']);

    $exception = GrantSafeguardingExceptionAction::new()->execute(sgeSuperAdmin(), $f['minor'], SGE_REASON, now()->addDays(7));
    $until = SafeguardingException::formatEnd($exception->ends_at);

    Notification::assertSentToTimes($f['minor'], GuardianAccessResumedNotification::class, 1);
    Notification::assertSentTo($f['minor'], GuardianAccessResumedNotification::class, function ($n) use ($f, $until) {
        $text = sgeMail($n, $f['minor']);

        return str_contains($text, "Your therapy can continue for now, until {$until}")
            && ! str_contains($text, 'guardian again') && ! str_contains($text, 'Confidential');
    });
    Notification::assertSentToTimes($f['counsellorUser'], ClientAccessResumedNotification::class, 1);
    Notification::assertSentTo($f['counsellorUser'], ClientAccessResumedNotification::class, fn ($n) => str_contains(sgeMail($n, $f['counsellorUser']), $until) && ! str_contains(sgeMail($n, $f['counsellorUser']), 'Confidential'));
    // The group counsellor too, with the member masked.
    Notification::assertSentTo($g['groupCounsellorUser'], ClientAccessResumedNotification::class, fn ($n) => $n->toArray($g['groupCounsellorUser'])['clientName'] === ConstantsEnum::anonymousUserLabel->value
        && ! str_contains(sgeMail($n, $g['groupCounsellorUser']), 'Kit'));
});

test('renewing ends the old row as renewed, links the new one, and tells the same people the new end', function () {
    $f = sgeMinor();
    $g = sgeGroup($f['minor']);
    $admin = sgeSuperAdmin();
    $first = GrantSafeguardingExceptionAction::new()->execute($admin, $f['minor'], 'First reason.', now()->addDays(7));

    $second = GrantSafeguardingExceptionAction::new()->execute($admin, $f['minor'], 'A new reason.', now()->addDays(20));

    expect($first->fresh())
        ->end_kind->toBe(SafeguardingException::END_RENEWED)
        ->ended_by->toBe($admin->id)
        ->and($second->previous_id)->toBe($first->id)
        ->and($second->reason)->toBe('A new reason.')
        ->and(SafeguardingException::query()->active()->pluck('id')->all())->toBe([$second->id]);

    Notification::assertSentToTimes($f['minor'], GuardianAccessResumedNotification::class, 1);
    $extended = fn ($n) => $n->toArray($f['minor'])['type'] === 'guardian_access.continuation_'.TherapyContinuationNotification::RENEWED
        && str_contains(sgeMail($n, $f['minor']), SafeguardingException::formatEnd($second->ends_at));
    Notification::assertSentTo($f['minor'], TherapyContinuationNotification::class, $extended);
    Notification::assertSentToTimes($f['counsellorUser'], TherapyContinuationNotification::class, 1);
    Notification::assertSentToTimes($g['groupCounsellorUser'], TherapyContinuationNotification::class, 1);
});

// -- Ending --------------------------------------------------------------------------------------

test('ending early pauses again once (the loss handling is queued); not when a guardian is back', function () {
    Queue::fake([HandleGuardianLossJob::class]);
    $f = sgeMinor();
    $admin = sgeSuperAdmin();
    $exception = GrantSafeguardingExceptionAction::new()->execute($admin, $f['minor'], 'Needed.', now()->addDays(7));

    EndSafeguardingExceptionAction::new()->execute($admin, $exception, 'A guardian is no longer being arranged.');

    Queue::assertPushed(HandleGuardianLossJob::class, 1);
    expect($exception->fresh())->end_kind->toBe(SafeguardingException::END_REVOKED)->end_reason->not->toBeNull()
        ->and(fn () => EndSafeguardingExceptionAction::new()->execute($admin, $exception, 'Again.'))->toThrow(SafeguardingExceptionRefusedException::class, EndSafeguardingExceptionAction::NOT_ACTIVE)
        ->and(fn () => EndSafeguardingExceptionAction::new()->execute(sgeAdmin(), sgeException(sgeMinor()['minor']), 'Not mine.'))->toThrow(SafeguardingExceptionRefusedException::class);

    $guarded = sgeMinor()['minor'];
    $other = GrantSafeguardingExceptionAction::new()->execute($admin, $guarded, 'Needed.', now()->addDays(7));
    sgeGuardian($guarded);
    EndSafeguardingExceptionAction::new()->execute($admin, $other, 'A guardian was added.');

    Queue::assertPushed(HandleGuardianLossJob::class, 1);
});

test('the expiry sweep records the end once and pauses again once; a guardian back means no pause', function () {
    Queue::fake([HandleGuardianLossJob::class]);
    $f = sgeMinor();
    $expired = sgeException($f['minor'], ['ends_at' => now()->addHours(2)]);
    $guardedMinor = sgeMinor()['minor'];
    $guardedExpired = sgeException($guardedMinor, ['ends_at' => now()->addHours(2)]);
    sgeGuardian($guardedMinor);

    $this->travel(3)->hours();
    $service = SafeguardingExceptionService::new();

    expect($service->endExpiredExceptions())->toBe(2)
        ->and($service->endExpiredExceptions())->toBe(0)
        ->and($expired->fresh()->end_processed_at)->not->toBeNull()
        ->and($guardedExpired->fresh()->end_processed_at)->not->toBeNull()
        ->and($expired->fresh()->ended_at)->toBeNull();
    Queue::assertPushed(HandleGuardianLossJob::class, 1);
});

test('the loss handling after expiry tells the 1:1 and group counsellors, the member masked', function () {
    $f = sgeMinor();
    $g = sgeGroup($f['minor']);
    sgeException($f['minor'], ['ends_at' => now()->addHours(2)]);
    $this->travel(3)->hours();

    SafeguardingExceptionService::new()->endExpiredExceptions();

    Notification::assertSentToTimes($f['counsellorUser'], ClientAccessPausedNotification::class, 1);
    Notification::assertSentTo($g['groupCounsellorUser'], ClientAccessPausedNotification::class, fn ($n) => $n->toArray($g['groupCounsellorUser'])['clientName'] === ConstantsEnum::anonymousUserLabel->value
        && $n->toArray($g['groupCounsellorUser'])['groupTherapyId'] === $g['group']->id
        && ! str_contains(sgeMail($n, $g['groupCounsellorUser']), 'Kit'));
});

test('a group counsellor is told a non-anonymous member\'s pause by name', function () {
    $f = sgeMinor();
    $g = sgeGroup($f['minor'], anonymous: false);

    HandleGuardianLossAction::new()->execute($f['minor'], HandleGuardianLossAction::DOB_CHANGED);

    Notification::assertSentTo($g['groupCounsellorUser'], ClientAccessPausedNotification::class, fn ($n) => $n->toArray($g['groupCounsellorUser'])['clientName'] === $f['minor']->name);
});

// -- The heads-up --------------------------------------------------------------------------------

test('the 3-day heads-up goes once to the minor, their counsellors and the super administrator', function () {
    $f = sgeMinor();
    $g = sgeGroup($f['minor']);
    $super = sgeSuperAdmin();
    $soon = sgeException($f['minor'], ['ends_at' => now()->addDays(2)]);
    sgeException(sgeMinor()['minor'], ['ends_at' => now()->addDays(5)]); // not yet
    $ended = sgeException(sgeMinor()['minor'], ['ends_at' => now()->addDays(2)]);
    $ended->forceFill(['ended_at' => now(), 'end_kind' => SafeguardingException::END_REVOKED])->save();

    $service = SafeguardingExceptionService::new();
    expect($service->sendExpiryReminders())->toBe(1)
        ->and($service->sendExpiryReminders())->toBe(0)
        ->and($soon->fresh()->expiry_reminded_at)->not->toBeNull();

    $endingSoon = fn ($n) => $n->toArray($f['minor'])['type'] === 'guardian_access.continuation_'.TherapyContinuationNotification::ENDING_SOON;
    Notification::assertSentToTimes($f['minor'], TherapyContinuationNotification::class, 1);
    Notification::assertSentTo($f['minor'], TherapyContinuationNotification::class, fn ($n) => $endingSoon($n) && ! str_contains(sgeMail($n, $f['minor']), 'Confidential'));
    Notification::assertSentToTimes($f['counsellorUser'], TherapyContinuationNotification::class, 1);
    Notification::assertSentTo($g['groupCounsellorUser'], TherapyContinuationNotification::class, fn ($n) => $n->toArray($g['groupCounsellorUser'])['clientName'] === ConstantsEnum::anonymousUserLabel->value);
    Notification::assertSentToTimes($super, SafeguardingExceptionEndingSoonNotification::class, 1);
    Notification::assertSentTo($super, SafeguardingExceptionEndingSoonNotification::class, fn ($n) => ! str_contains(sgeMail($n, $super), 'Confidential')
        && $n->toMail($super)->actionUrl === route('administrator.safeguarding_exceptions'));
});

test('the 3-day heads-up skips a super administrator who counsels the minor', function () {
    $f = sgeMinor();
    $involved = sgeMakeSuper($f['counsellorUser']);
    $other = sgeSuperAdmin();
    sgeException($f['minor'], ['ends_at' => now()->addDays(2)]);

    expect(SafeguardingExceptionService::new()->sendExpiryReminders())->toBe(1);
    Notification::assertNotSentTo($involved, SafeguardingExceptionEndingSoonNotification::class);
    Notification::assertSentToTimes($other, SafeguardingExceptionEndingSoonNotification::class, 1);
});

test('no heads-up when a guardian is back (the row is still claimed)', function () {
    $f = sgeMinor();
    $super = sgeSuperAdmin();
    $soon = sgeException($f['minor'], ['ends_at' => now()->addDays(2)]);
    sgeGuardian($f['minor']);

    expect(SafeguardingExceptionService::new()->sendExpiryReminders())->toBe(0)
        ->and($soon->fresh()->expiry_reminded_at)->not->toBeNull();
    Notification::assertNotSentTo($f['minor'], TherapyContinuationNotification::class);
    Notification::assertNotSentTo($super, SafeguardingExceptionEndingSoonNotification::class);
});

// -- Counsellors' requests -----------------------------------------------------------------------

test('a 1:1 counsellor can ask; the super administrator is told, without the reason', function () {
    $f = sgeMinor();
    $super = sgeSuperAdmin();

    sgeAsk($f['counsellorUser'], $f['therapy'], ['reason' => SGE_REASON])->assertCreated();

    $request = Request::query()->where('type', RequestTypeEnum::safeguardingException->value)->sole();
    expect($request)
        ->from_type->toBe(Counsellor::class)->from_id->toBe($f['counsellor']->id)
        ->to_id->toBeNull()->for_type->toBe(Therapy::class)
        ->and($request->data['wardId'])->toBe($f['minor']->id);
    Notification::assertSentTo($super, SafeguardingExceptionRequestedNotification::class, fn ($n) => ! str_contains(sgeMail($n, $super), 'Confidential'));
    expect(sgeView($f['counsellorUser'], new TherapyResource($f['therapy']->fresh()))['safeguardingExceptionRequested'])->toBeTrue();
});

test('a group counsellor can ask for a paused member by their key, never their id', function () {
    $f = sgeMinor();
    $g = sgeGroup($f['minor']);
    $members = sgeView($g['groupCounsellorUser'], new GroupTherapyResource($g['group']->fresh()))['pausedMembers'];

    expect($members)->toHaveCount(1)
        ->and($members[0]['name'])->toBe(ConstantsEnum::anonymousUserLabel->value)
        ->and($members[0]['key'])->toBe((string) DB::table('group_therapy_user')->where('group_therapy_id', $g['group']->id)->where('user_id', $f['minor']->id)->value('id'));

    sgeAsk($g['groupCounsellorUser'], $g['group'], ['memberKey' => $members[0]['key']])->assertCreated();
    sgeAsk($g['groupCounsellorUser'], $g['group'], ['memberKey' => 'nobody'])->assertStatus(422);

    expect(Request::query()->where('type', RequestTypeEnum::safeguardingException->value)->sole()->data['wardId'])->toBe($f['minor']->id)
        ->and(sgeView($g['groupCounsellorUser'], new GroupTherapyResource($g['group']->fresh()))['pausedMembers'][0]['exceptionRequested'])->toBeTrue();
});

test('asking is refused for a non-counsellor, a suspended counsellor, an unpaused client, an ended therapy and a duplicate', function () {
    $f = sgeMinor();
    sgeAsk(User::factory()->adult()->create(), $f['therapy'])->assertForbidden()->assertJsonPath('message', SendSafeguardingExceptionRequestAction::NOT_COUNSELLOR);
    sgeAsk($f['minor'], $f['therapy'])->assertForbidden();
    sgeAsk($f['counsellorUser'], $f['therapy'], ['reason' => ''])->assertStatus(422);
    sgeAsk($f['counsellorUser'], $f['therapy'], ['proposedEndsAt' => now()->addDays(31)->toJSON()])->assertStatus(422);

    sgeAsk($f['counsellorUser'], $f['therapy'])->assertCreated();
    sgeAsk($f['counsellorUser'], $f['therapy'])->assertStatus(422)->assertJsonPath('message', SendSafeguardingExceptionRequestAction::ALREADY_REQUESTED);

    $guarded = sgeMinor();
    sgeGuardian($guarded['minor']);
    sgeAsk($guarded['counsellorUser'], $guarded['therapy'])->assertStatus(422)->assertJsonPath('message', SendSafeguardingExceptionRequestAction::NOT_PAUSED);

    $covered = sgeMinor();
    sgeException($covered['minor']);
    sgeAsk($covered['counsellorUser'], $covered['therapy'])->assertStatus(422)->assertJsonPath('message', SendSafeguardingExceptionRequestAction::NOT_PAUSED);

    $ended = sgeMinor();
    $ended['therapy']->forceFill(['status' => TherapyStatusEnum::ended->value])->save();
    sgeAsk($ended['counsellorUser'], $ended['therapy'])->assertStatus(422);

    $suspended = sgeMinor();
    sgeSuspendCounsellor($suspended['counsellor']);
    sgeAsk($suspended['counsellorUser'], $suspended['therapy'])->assertForbidden();

    expect(Request::query()->where('type', RequestTypeEnum::safeguardingException->value)->count())->toBe(1);
});

test('only the super administrator lists and answers a request; a regular administrator can\'t', function () {
    $f = sgeMinor();
    $super = sgeSuperAdmin();
    $admin = sgeAdmin();
    sgeAsk($f['counsellorUser'], $f['therapy'])->assertCreated();
    $request = Request::query()->where('type', RequestTypeEnum::safeguardingException->value)->sole();

    $this->actingAs($admin)->getJson(route('requests.get'))->assertOk()->assertJsonCount(0, 'data');
    $this->actingAs($super)->getJson(route('requests.get'))->assertOk()->assertJsonPath('data.0.id', $request->id)
        ->assertJsonPath('data.0.safeguardingException.isRespondent', true)
        ->assertJsonPath('data.0.safeguardingException.client.fullName', $f['minor']->name);
    $this->actingAs($f['counsellorUser'])->getJson(route('requests.get'))->assertOk()->assertJsonPath('data.0.safeguardingException.isRespondent', false);

    $this->actingAs($admin)->postJson(route('requests.respond', ['requestId' => $request->id]), ['response' => 'accepted', 'reason' => 'Fine.', 'endsAt' => now()->addDays(7)->toJSON()])
        ->assertStatus(422);
    $this->actingAs($f['counsellorUser'])->postJson(route('requests.respond', ['requestId' => $request->id]), ['response' => 'rejected'])
        ->assertStatus(422);
    expect($request->fresh()->status)->toBe(RequestStatusEnum::pending->value);
});

test('accepting creates the exception with the request and the administrator\'s end date, and tells the counsellor', function () {
    $f = sgeMinor();
    $super = sgeSuperAdmin();
    sgeAsk($f['counsellorUser'], $f['therapy'])->assertCreated();
    $request = Request::query()->where('type', RequestTypeEnum::safeguardingException->value)->sole();
    $endsAt = now()->addDays(9)->startOfMinute();

    $this->actingAs($super)->postJson(route('requests.respond', ['requestId' => $request->id]), ['response' => 'accepted'])->assertStatus(422);
    $this->actingAs($super)->postJson(route('requests.respond', ['requestId' => $request->id]), ['response' => 'accepted', 'reason' => SGE_REASON, 'endsAt' => $endsAt->toJSON()])
        ->assertCreated()->assertJsonPath('request.status', RequestStatusEnum::accepted->value);

    $exception = SafeguardingException::query()->sole();
    expect($exception)->request_id->toBe($request->id)->granted_by->toBe($super->id)->reason->toBe(SGE_REASON)
        ->and($exception->ends_at->equalTo($endsAt))->toBeTrue();
    Notification::assertSentTo($f['counsellorUser'], SafeguardingExceptionRequestDecidedNotification::class, fn ($n) => $n->toArray($f['counsellorUser'])['type'] === 'safeguarding_exception.request_approved'
        && ! str_contains(sgeMail($n, $f['counsellorUser']), 'Confidential'));
    Notification::assertSentToTimes($f['minor'], GuardianAccessResumedNotification::class, 1);
});

test('rejecting closes the request and tells the counsellor', function () {
    $f = sgeMinor();
    sgeAsk($f['counsellorUser'], $f['therapy'])->assertCreated();
    $request = Request::query()->where('type', RequestTypeEnum::safeguardingException->value)->sole();

    $this->actingAs(sgeSuperAdmin())->postJson(route('requests.respond', ['requestId' => $request->id]), ['response' => 'rejected'])->assertCreated();

    expect($request->fresh()->status)->toBe(RequestStatusEnum::rejected->value)->and(SafeguardingException::query()->count())->toBe(0);
    Notification::assertSentTo($f['counsellorUser'], SafeguardingExceptionRequestDecidedNotification::class, fn ($n) => $n->toArray($f['counsellorUser'])['type'] === 'safeguarding_exception.request_rejected');
});

test('accepting is refused with 422 once the client is no longer paused', function () {
    $f = sgeMinor();
    sgeAsk($f['counsellorUser'], $f['therapy'])->assertCreated();
    $request = Request::query()->where('type', RequestTypeEnum::safeguardingException->value)->sole();
    sgeGuardian($f['minor']);

    $this->actingAs(sgeSuperAdmin())->postJson(route('requests.respond', ['requestId' => $request->id]), ['response' => 'accepted', 'reason' => 'Fine.', 'endsAt' => now()->addDays(7)->toJSON()])
        ->assertStatus(422)->assertJsonPath('error', GrantSafeguardingExceptionAction::NO_LONGER_NEEDED);

    expect($request->fresh()->status)->toBe(RequestStatusEnum::pending->value)->and(SafeguardingException::query()->count())->toBe(0);
});

test('a direct grant closes other counsellors\' pending requests for the minor', function () {
    $f = sgeMinor();
    $g = sgeGroup($f['minor']);
    sgeAsk($f['counsellorUser'], $f['therapy'])->assertCreated();
    sgeAsk($g['groupCounsellorUser'], $g['group'], ['memberKey' => GetPausedGroupMembersAction::new()->execute($g['group'])->first()['key']])->assertCreated();

    GrantSafeguardingExceptionAction::new()->execute(sgeSuperAdmin(), $f['minor'], 'Needed.', now()->addDays(7));

    expect(Request::query()->where('type', RequestTypeEnum::safeguardingException->value)->pluck('status')->unique()->all())->toBe([RequestStatusEnum::inconsequencial->value]);
});

test('an anonymous client\'s identity never reaches the counsellor through the request', function () {
    $f = sgeMinor();
    $f['therapy']->forceFill(['anonymous' => true])->save();
    sgeAsk($f['counsellorUser'], $f['therapy'])->assertCreated();
    $request = Request::query()->where('type', RequestTypeEnum::safeguardingException->value)->sole();

    $json = json_encode(sgeView($f['counsellorUser'], new RequestResource($request->fresh())));

    expect($json)->not->toContain($f['minor']->name)->not->toContain($f['minor']->username)
        ->not->toContain('"wardId"')->toContain(ConstantsEnum::anonymousUserLabel->value);
});

// -- Admin page and audit ------------------------------------------------------------------------

test('every administrator sees the page; only the super administrator can grant or end through it', function () {
    $f = sgeMinor();
    $admin = sgeAdmin();
    $super = sgeSuperAdmin();

    $this->actingAs($admin)->get(route('administrator.safeguarding_exceptions'))->assertOk()
        ->assertInertia(fn ($page) => $page->component('Admin/SafeguardingExceptions')->where('canManage', false)->where('pausedMinors.data.0.id', $f['minor']->id));
    $this->actingAs($f['counsellorUser'])->get(route('administrator.safeguarding_exceptions'))->assertRedirect(route('home'));

    $this->actingAs($admin)->post(route('admin.safeguarding_exceptions.grant', ['ward' => $f['minor']->id]), ['reason' => 'Needed.', 'endsAt' => now()->addDays(7)->toJSON()])
        ->assertSessionHasErrors('alert');
    expect(SafeguardingException::query()->count())->toBe(0);

    $this->actingAs($super)->post(route('admin.safeguarding_exceptions.grant', ['ward' => $f['minor']->id]), ['reason' => 'Needed.', 'endsAt' => now()->addDays(7)->toJSON()])
        ->assertSessionHasNoErrors();
    $exception = SafeguardingException::query()->sole();

    $this->actingAs($admin)->post(route('admin.safeguarding_exceptions.end', ['exception' => $exception->id]), ['reason' => 'Stop.'])->assertSessionHasErrors('alert');
    $this->actingAs($super)->post(route('admin.safeguarding_exceptions.end', ['exception' => $exception->id]), ['reason' => 'Stop.'])->assertSessionHasNoErrors();
    expect($exception->fresh()->end_kind)->toBe(SafeguardingException::END_REVOKED);

    $this->actingAs($admin)->get(route('administrator.safeguarding_exceptions'))
        ->assertInertia(fn ($page) => $page->where('exceptions.data.0.reason', 'Needed.')->where('exceptions.data.0.status', 'revoked'));
});

test('anonymising the minor\'s closed account keeps the exception but removes the reasons', function () {
    $f = sgeMinor();
    $exception = sgeException($f['minor']);
    $exception->forceFill(['ended_at' => now(), 'end_kind' => SafeguardingException::END_REVOKED, 'end_reason' => 'Private end reason.'])->save();
    sgeAsk($f['counsellorUser'], $f['therapy'])->assertCreated();

    expect(AnonymiseClosedAccountAction::new()->execute(User::query()->find($f['minor']->id)))->toBeTrue();

    expect($exception->fresh())->not->toBeNull()->reason->toBeNull()->end_reason->toBeNull()
        ->and(Request::query()->where('type', RequestTypeEnum::safeguardingException->value)->sole())
        ->data->not->toHaveKey('reason')
        ->status->toBe(RequestStatusEnum::inconsequencial->value)
        ->responded_at->not->toBeNull();
});

test('a minor under an exception (no therapy left) still needs approval to change their date of birth to an adult one', function () {
    $minor = User::factory()->create(['dob' => now()->subYears(15)->toDateString()]);
    sgeException($minor);
    $minor = User::query()->find($minor->id);
    expect($minor->isPausedForMissingGuardian())->toBeFalse();

    expect(fn () => EnsureDobChangeIsAllowedAction::new()->execute(EnsureDobChangeIsAllowedDTO::new()->fromArray([
        'user' => $minor, 'actor' => $minor, 'newDob' => now()->subYears(19)->toDateString(),
    ])))->toThrow(DobChangeRequiresApprovalException::class);
});

// -- SCRUM-382 review follow-ups -----------------------------------------------------------------

// Makes $user a super administrator (keeping any counsellor profile) and returns a fresh instance.
function sgeMakeSuper(User $user): User
{
    Administrator::factory()->create(['user_id' => $user->id, 'type' => AdministratorTypeEnum::super->value]);

    return User::query()->find($user->id);
}

function sgeSuspendAccount(User $user): void
{
    $client = User::factory()->adult()->create();
    $report = new Report(['description' => 'Conduct', 'data' => []]);
    $report->addedby()->associate($client);
    $report->reportable()->associate($user);
    $report->save();
    Suspension::query()->forceCreate([
        'user_id' => $user->id, 'report_id' => $report->id, 'scope' => SuspensionScopeEnum::account->value,
        'starts_at' => now()->subHour(), 'public_reason' => 'A reason the person will read.',
    ]);
}

function sgeRequestFor(array $f): Request
{
    sgeAsk($f['counsellorUser'], $f['therapy'])->assertCreated();

    return Request::query()->where('type', RequestTypeEnum::safeguardingException->value)->latest('id')->firstOrFail();
}

test('a super administrator who co-counsels the minor can\'t answer the request, and sees only that it exists', function () {
    $f = sgeMinor();
    $g = sgeGroup($f['minor'], anonymous: false);
    $coSuper = sgeMakeSuper($g['groupCounsellorUser']);
    $request = sgeRequestFor($f);

    $listed = $this->actingAs($coSuper)->getJson(route('requests.get'))->assertOk()
        ->assertJsonPath('data.0.id', $request->id)
        ->assertJsonPath('data.0.safeguardingException.involved', true)
        ->assertJsonPath('data.0.safeguardingException.isRespondent', false)
        ->assertJsonPath('data.0.for', null)
        ->assertJsonPath('data.0.from', null);
    expect($listed->getContent())->not->toContain($f['minor']->name)->not->toContain((string) $f['minor']->username)
        ->not->toContain('They need to keep going.')->not->toContain($f['therapy']->name);

    $this->actingAs($coSuper)->postJson(route('requests.respond', ['requestId' => $request->id]), ['response' => 'rejected'])->assertStatus(422);
    $this->actingAs($coSuper)->postJson(route('requests.respond', ['requestId' => $request->id]), ['response' => 'accepted', 'reason' => 'Fine.', 'endsAt' => now()->addDays(7)->toJSON()])->assertStatus(422);

    // The action itself refuses too, past the respond check.
    expect(fn () => RespondToSafeguardingExceptionRequestAction::new()->execute(RequestResponseDTO::new()->fromArray(['user' => $coSuper, 'request' => $request, 'response' => 'rejected'])))
        ->toThrow(SafeguardingExceptionRefusedException::class, EnsureCanManageSafeguardingExceptionAction::COUNSELS_WARD);
    expect($request->fresh()->status)->toBe(RequestStatusEnum::pending->value);
    Notification::assertNotSentTo($f['counsellorUser'], SafeguardingExceptionRequestDecidedNotification::class);

    // An uninvolved super administrator still sees it in full.
    $this->actingAs(sgeSuperAdmin())->getJson(route('requests.get'))->assertOk()
        ->assertJsonPath('data.0.safeguardingException.involved', false)
        ->assertJsonPath('data.0.safeguardingException.client.fullName', $f['minor']->name);
});

test('on the admin page, an administrator involved with the minor sees summaries, not who, the reasons or the therapy', function () {
    $f = sgeMinor();
    $g = sgeGroup($f['minor'], anonymous: false);
    $coSuper = sgeMakeSuper($g['groupCounsellorUser']);
    sgeRequestFor($f);
    $other = sgeMinor();
    $other['minor']->forceFill(['firstName' => 'Sam', 'lastName' => 'Other'])->save();

    $response = $this->actingAs($coSuper)->get(route('administrator.safeguarding_exceptions'))->assertOk()
        ->assertInertia(fn ($page) => $page->where('canManage', true)
            ->where('pausedMinors.data', fn ($rows) => collect($rows)->where('involved', true)->count() === 1
                && collect($rows)->where('involved', true)->every(fn ($row) => ! isset($row['id']) && ! isset($row['name']) && ! isset($row['requests']) && $row['canManage'] === false)
                && collect($rows)->firstWhere('id', $other['minor']->id)['canManage'] === true));
    expect($response->getContent())->not->toContain($f['minor']->name)->not->toContain('They need to keep going.')->not->toContain($f['therapy']->name);

    $exception = sgeException($f['minor']);
    $response = $this->actingAs($coSuper)->get(route('administrator.safeguarding_exceptions'))
        ->assertInertia(fn ($page) => $page->where('exceptions.data.0.id', $exception->id)
            ->where('exceptions.data.0.involved', true)
            ->where('exceptions.data.0.canManage', false)
            ->missing('exceptions.data.0.ward')
            ->missing('exceptions.data.0.reason'));
    expect($response->getContent())->not->toContain(SGE_REASON);

    // The 1:1 counsellor who asked, as an ordinary administrator, is involved too.
    $sender = User::query()->find($f['counsellorUser']->id);
    Administrator::factory()->create(['user_id' => $sender->id, 'type' => AdministratorTypeEnum::normal->value]);
    $this->actingAs(User::query()->find($sender->id))->get(route('administrator.safeguarding_exceptions'))
        ->assertInertia(fn ($page) => $page->where('exceptions.data.0.involved', true));

    $this->actingAs(sgeSuperAdmin())->get(route('administrator.safeguarding_exceptions'))
        ->assertInertia(fn ($page) => $page->where('exceptions.data.0.involved', false)
            ->where('exceptions.data.0.reason', SGE_REASON)
            ->where('exceptions.data.0.canManage', true));
});

test('a late reject (the request was already answered) is refused with 422 and tells nobody again', function () {
    $f = sgeMinor();
    $super = sgeSuperAdmin();
    $request = sgeRequestFor($f);
    $this->actingAs($super)->postJson(route('requests.respond', ['requestId' => $request->id]), ['response' => 'accepted', 'reason' => 'Fine.', 'endsAt' => now()->addDays(7)->toJSON()])->assertCreated();

    expect(fn () => RespondToSafeguardingExceptionRequestAction::new()->execute(RequestResponseDTO::new()->fromArray(['user' => $super, 'request' => $request, 'response' => 'rejected'])))
        ->toThrow(SafeguardingExceptionRefusedException::class, GrantSafeguardingExceptionAction::ALREADY_ANSWERED);

    expect($request->fresh()->status)->toBe(RequestStatusEnum::accepted->value);
    Notification::assertSentToTimes($f['counsellorUser'], SafeguardingExceptionRequestDecidedNotification::class, 1);
});

test('accepting while an exception is already in force is no longer needed, and the request stays pending', function () {
    $f = sgeMinor();
    $request = sgeRequestFor($f);
    sgeException($f['minor']);

    $this->actingAs(sgeSuperAdmin())->postJson(route('requests.respond', ['requestId' => $request->id]), ['response' => 'accepted', 'reason' => 'Fine.', 'endsAt' => now()->addDays(7)->toJSON()])
        ->assertStatus(422)->assertJsonPath('error', GrantSafeguardingExceptionAction::NO_LONGER_NEEDED);

    expect($request->fresh()->status)->toBe(RequestStatusEnum::pending->value)
        ->and(SafeguardingException::query()->count())->toBe(1);
});

test('a grant after one ran out but before the sweep: the sweep records the old end and queues no pause', function () {
    Queue::fake([HandleGuardianLossJob::class]);
    $f = sgeMinor();
    $expired = sgeException($f['minor'], ['ends_at' => now()->addHours(2)]);
    $this->travel(3)->hours();

    $new = GrantSafeguardingExceptionAction::new()->execute(sgeSuperAdmin(), User::query()->find($f['minor']->id), 'Granted again.', now()->addDays(5));

    expect($new->previous_id)->toBeNull(); // not a renewal: nothing was in force
    expect(SafeguardingExceptionService::new()->endExpiredExceptions())->toBe(1)
        ->and($expired->fresh()->end_processed_at)->not->toBeNull()
        ->and($new->fresh()->end_processed_at)->toBeNull();
    Queue::assertNotPushed(HandleGuardianLossJob::class);
});

test('a renewal can bring the end forward, and its notice says the date changed, not "for longer"', function () {
    $f = sgeMinor();
    $admin = sgeSuperAdmin();
    GrantSafeguardingExceptionAction::new()->execute($admin, $f['minor'], 'First reason.', now()->addDays(20));

    $shorter = GrantSafeguardingExceptionAction::new()->execute($admin, $f['minor'], 'Brought forward.', now()->addDays(5));

    expect(SafeguardingException::query()->active()->sole()->id)->toBe($shorter->id);
    Notification::assertSentTo($f['counsellorUser'], TherapyContinuationNotification::class, function ($n) use ($f, $shorter) {
        $mail = sgeMail($n, $f['counsellorUser']);

        return str_contains($mail, 'has changed') && ! str_contains($mail, 'for longer')
            && str_contains($mail, SafeguardingException::formatEnd($shorter->ends_at));
    });
});

test('the end the notices give is the stored ends_at, to the second', function () {
    $f = sgeMinor();
    $exception = GrantSafeguardingExceptionAction::new()->execute(sgeSuperAdmin(), $f['minor'], 'Needed.', now()->addDays(3)->setMicrosecond(654321)->toJSON());

    expect($exception->ends_at->micro)->toBe(0)
        ->and($exception->fresh()->ends_at->equalTo($exception->ends_at))->toBeTrue();
    Notification::assertSentTo($f['minor'], GuardianAccessResumedNotification::class, fn ($n) => str_contains(sgeMail($n, $f['minor']), SafeguardingException::formatEnd($exception->fresh()->ends_at)));
});

test('granting and ending early refresh the memo on the instance the caller holds', function () {
    $f = sgeMinor();
    $admin = sgeSuperAdmin();
    $minor = User::query()->find($f['minor']->id);
    expect($minor->isPausedForMissingGuardian())->toBeTrue();

    $exception = GrantSafeguardingExceptionAction::new()->execute($admin, $minor, 'Needed.', now()->addDays(7));
    expect($minor->isPausedForMissingGuardian())->toBeFalse();

    $exception->setRelation('ward', $minor);
    EndSafeguardingExceptionAction::new()->execute($admin, $exception, 'Ending it.');
    expect($minor->isPausedForMissingGuardian())->toBeTrue();

    // Without the hook, a held instance keeps its memo until told (forgetSafeguardingException()).
    $held = User::query()->find($f['minor']->id);
    expect($held->isPausedForMissingGuardian())->toBeTrue();
    sgeException($f['minor']);
    expect($held->isPausedForMissingGuardian())->toBeTrue()
        ->and($held->forgetSafeguardingException()->isPausedForMissingGuardian())->toBeFalse();
});

test('the "requested" notice skips a super administrator who counsels the minor and one whose account is suspended', function () {
    $f = sgeMinor();
    $g = sgeGroup($f['minor']);
    $coSuper = sgeMakeSuper($g['groupCounsellorUser']);
    $suspendedSuper = sgeSuperAdmin();
    sgeSuspendAccount($suspendedSuper);
    $super = sgeSuperAdmin();

    sgeRequestFor($f);

    Notification::assertSentTo($super, SafeguardingExceptionRequestedNotification::class);
    Notification::assertNotSentTo($coSuper, SafeguardingExceptionRequestedNotification::class);
    Notification::assertNotSentTo($suspendedSuper, SafeguardingExceptionRequestedNotification::class);
});

test('a minor group member can\'t join group video, with or without an exception (no group consent yet)', function () {
    $f = sgeMinor();
    $g = sgeGroup($f['minor']);
    $session = Session::factory()->create([
        'for_type' => GroupTherapy::class, 'for_id' => $g['group']->id, 'addedby_type' => Counsellor::class, 'addedby_id' => $g['groupCounsellor']->id,
        'status' => SessionStatusEnum::in_session->value, 'type' => 'ONLINE', 'start_time' => now()->subMinutes(5), 'end_time' => now()->addHour(),
    ]);
    sgeGuardian($f['minor']);
    $join = fn () => EnsureVideoIsAvailableForSessionAction::new()->execute($session->fresh(), User::query()->find($f['minor']->id));

    expect($join)->toThrow(VideoException::class, EnsureVideoIsAvailableForSessionAction::GROUP_NEEDS_GUARDIAN_CONSENT);

    $unguarded = sgeMinor()['minor'];
    $g['group']->users()->attach($unguarded->id, ['anonymous' => false, 'was_minor_at_join' => true]);
    sgeException($unguarded);
    expect(fn () => EnsureVideoIsAvailableForSessionAction::new()->execute($session->fresh(), User::query()->find($unguarded->id)))
        ->toThrow(VideoException::class, EnsureVideoIsAvailableForSessionAction::GROUP_NEEDS_GUARDIAN_CONSENT)
        ->and(sgeView(User::query()->find($unguarded->id), new GroupTherapyResource($g['group']->fresh()))['viewerNeedsGuardianVideoConsent'])->toBeTrue();

    $adult = User::factory()->adult()->create();
    $g['group']->users()->attach($adult->id, ['anonymous' => false, 'was_minor_at_join' => false]);
    EnsureVideoIsAvailableForSessionAction::new()->execute($session->fresh(), $adult);
    expect(sgeView($adult, new GroupTherapyResource($g['group']->fresh()))['viewerNeedsGuardianVideoConsent'])->toBeFalse();
});
