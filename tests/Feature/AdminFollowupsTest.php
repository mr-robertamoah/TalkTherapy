<?php

use App\Actions\Counsellor\DeleteCounsellorAction;
use App\Actions\User\AnonymiseClosedAccountAction;
use App\DTOs\DeleteCounsellorDTO;
use App\Enums\AdministratorTypeEnum;
use App\Enums\RequestStatusEnum;
use App\Enums\RequestTypeEnum;
use App\Enums\TherapyStatusEnum;
use App\Models\Administrator;
use App\Models\Counsellor;
use App\Models\GroupTherapy;
use App\Models\Request;
use App\Models\Therapy;
use App\Models\User;
use Illuminate\Support\Facades\Cache;

// SCRUM-388: follow-ups from the SCRUM-387 reviews -- the creator's Membership box on a counsellor-
// created group, narrowed counsellor users on admin queue rows, and the admin to-do counts after
// writes that skip the Request saved hook. Helpers are file-local and afu-prefixed (--parallel).

function afuSuperAdmin(): User
{
    return User::factory()->adult()->has(Administrator::factory()->state(['type' => AdministratorTypeEnum::super->value]))->create();
}

function afuRequest(string $type, $from, $to, $for, array $data = []): Request
{
    $request = new Request(['type' => $type, 'data' => $data, 'status' => RequestStatusEnum::pending->value]);
    $request->from()->associate($from);
    if ($to) {
        $request->to()->associate($to);
    }
    $request->for()->associate($for);
    $request->save();

    return $request;
}

function afuCounsellor(): Counsellor
{
    return Counsellor::factory()->create(['user_id' => User::factory()->adult()->create(['gender' => 'MALE', 'country' => 'Ghana'])->id]);
}

function afuGeneration(): ?string
{
    return Cache::get('admin-todo:generation');
}

test('a counsellor-created group\'s creator finds a membership request addressed to their counsellor profile', function () {
    $creator = afuCounsellor();
    $group = GroupTherapy::factory()->create(['addedby_type' => Counsellor::class, 'addedby_id' => $creator->id]);
    $joiner = User::factory()->adult()->create();
    $request = afuRequest(RequestTypeEnum::groupTherapyMembership->value, $joiner, $creator, $group);

    expect($group->pendingMembershipRequestFor($creator->user)?->id)->toBe($request->id)
        ->and($group->pendingMembershipRequestFor($joiner)?->id)->toBe($request->id);

    // Nobody else's, and not another group's.
    $other = afuCounsellor();
    $otherGroup = GroupTherapy::factory()->create(['addedby_type' => Counsellor::class, 'addedby_id' => $creator->id]);
    expect($group->pendingMembershipRequestFor($other->user))->toBeNull()
        ->and($otherGroup->pendingMembershipRequestFor($creator->user))->toBeNull();

    // The OR stays inside the pending filter.
    $request->update(['status' => RequestStatusEnum::rejected->value]);
    expect($group->pendingMembershipRequestFor($creator->user))->toBeNull();
});

test('counsellor verification and safeguarding rows in the admin queue carry no dob, gender or country for the counsellor', function () {
    $super = afuSuperAdmin();
    $counsellor = afuCounsellor();
    $verification = afuRequest(RequestTypeEnum::counsellor->value, $counsellor, $super, $counsellor);

    $minor = User::factory()->create(['dob' => now()->subYears(15)->toDateString()]);
    $sender = afuCounsellor();
    $therapy = Therapy::factory()->create([
        'addedby_type' => User::class, 'addedby_id' => $minor->id, 'counsellor_id' => $sender->id,
        'status' => TherapyStatusEnum::in_session->value, 'anonymous' => false, 'client_was_minor_at_creation' => true,
    ]);
    $safeguarding = afuRequest(RequestTypeEnum::safeguardingException->value, $sender, null, $therapy, [
        'reason' => 'Needs to keep going.', 'proposedEndsAt' => now()->addDays(10)->toJSON(), 'wardId' => $minor->id,
    ]);

    $rows = collect($this->actingAs($super)->get(route('administrator.requests'))->viewData('page')['props']['requests']['data'])->keyBy('id');

    foreach ([$rows[$verification->id]['from']['user'], $rows[$verification->id]['for']['user'], $rows[$safeguarding->id]['from']['user']] as $user) {
        expect($user)->toHaveKeys(['id', 'fullName', 'username'])
            ->not->toHaveKeys(['dob', 'gender', 'country']);
    }

    // Nor anywhere else in either row -- the safeguarding request's therapy names its counsellor too.
    foreach ([$verification->id, $safeguarding->id] as $id) {
        $json = json_encode($rows[$id]);
        expect($json)->not->toContain('"dob"')->not->toContain('"gender"')->not->toContain('"country"');
    }
});

test('deleting a counsellor with a pending verification request clears the admin to-do counts', function () {
    $counsellor = afuCounsellor();
    afuRequest(RequestTypeEnum::counsellor->value, $counsellor, afuSuperAdmin(), $counsellor);
    Cache::forever('admin-todo:generation', 'before');

    DeleteCounsellorAction::new()->execute(DeleteCounsellorDTO::new()->fromArray(['user' => $counsellor->user, 'counsellor' => $counsellor]));

    expect(afuGeneration())->not->toBe('before');
});

test('anonymising a closed account clears the admin to-do counts', function () {
    $person = User::factory()->adult()->create();
    afuRequest(RequestTypeEnum::dobChange->value, $person, null, $person, ['newDob' => '2010-01-01', 'priorDob' => '1990-01-01']);
    Cache::forever('admin-todo:generation', 'before');

    expect(AnonymiseClosedAccountAction::new()->execute($person))->toBeTrue()
        ->and(afuGeneration())->not->toBe('before');
});
