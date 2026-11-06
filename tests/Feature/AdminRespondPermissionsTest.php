<?php

use App\Actions\Request\EnsureUserCanRespondToRequestAction;
use App\Enums\AdministratorTypeEnum;
use App\Enums\RequestStatusEnum;
use App\Enums\RequestTypeEnum;
use App\Enums\TherapyStatusEnum;
use App\Models\Administrator;
use App\Models\Counsellor;
use App\Models\GroupTherapy;
use App\Models\Organization;
use App\Models\Request;
use App\Models\Therapy;
use App\Models\Transaction;
use App\Models\User;
use App\Support\AdminRequestQueue;

// SCRUM-387 (security review): an administrator answers only the admin queue's types
// (App\Support\AdminRequestQueue), who answered is recorded (requests.responded_by_id) and shown to
// administrators, and the queue never carries the super admin's or a payer's dob, gender or country.
// Helpers are file-local and arp-prefixed (--parallel).

function arpAdmin(string $type = 'NORMAL'): User
{
    return User::factory()->adult()->has(Administrator::factory()->state(['type' => $type]))->create([
        'gender' => 'FEMALE', 'country' => 'Ghana',
    ]);
}

function arpRequest(string $type, $from, $to, $for, array $data = []): Request
{
    $request = new Request(['type' => $type, 'data' => $data, 'status' => RequestStatusEnum::pending->value]);
    $request->from()->associate($from);
    if ($to) {
        $request->to()->associate($to);
    }
    if ($for) {
        $request->for()->associate($for);
    }
    $request->save();

    return $request;
}

function arpQueueRow($response, int $id): array
{
    return collect($response->viewData('page')['props']['requests']['data'])->firstWhere('id', $id);
}

test('an administrator who is not a party is refused every type outside the admin queue', function () {
    $person = User::factory()->adult()->create();
    $other = User::factory()->adult()->create();
    $counsellor = Counsellor::factory()->create(['user_id' => User::factory()->adult()->create()->id]);
    $organization = Organization::factory()->create();
    $therapy = Therapy::factory()->create(['addedby_type' => User::class, 'addedby_id' => $person->id, 'status' => TherapyStatusEnum::in_session->value]);
    $group = GroupTherapy::factory()->create(['addedby_type' => User::class, 'addedby_id' => $person->id]);

    $partyOnly = [
        'guardianship' => arpRequest(RequestTypeEnum::guardianship->value, $person, $other, $person),
        'discussion' => arpRequest(RequestTypeEnum::discussion->value, $counsellor, Counsellor::factory()->create(['user_id' => $other->id]), $therapy),
        'therapy' => arpRequest(RequestTypeEnum::therapy->value, $person, $counsellor, $therapy),
        'groupTherapy' => arpRequest(RequestTypeEnum::groupTherapy->value, $person, $counsellor, $group),
        'groupTherapyMembership' => arpRequest(RequestTypeEnum::groupTherapyMembership->value, $other, $person, $group),
        'organizationCounsellorInvite' => arpRequest(RequestTypeEnum::organizationCounsellorInvite->value, $organization, $counsellor, $organization),
        'organizationCounsellorApplication' => arpRequest(RequestTypeEnum::organizationCounsellorApplication->value, $counsellor, $organization, $organization),
        'organizationMemberInvite' => arpRequest(RequestTypeEnum::organizationMemberInvite->value, $organization, $person, $organization),
        'organizationMemberApplication' => arpRequest(RequestTypeEnum::organizationMemberApplication->value, $person, $organization, $organization),
        'organizationCounsellorCompensationChange' => arpRequest(RequestTypeEnum::organizationCounsellorCompensationChange->value, $organization, $counsellor, null),
        'sessionScheduleProposal' => arpRequest(RequestTypeEnum::sessionScheduleProposal->value, $person, $counsellor, $therapy),
        'administrator' => arpRequest(RequestTypeEnum::administrator->value, $person, null, null),
        // A dob change with a guardian is the guardian's, not admin work.
        'dobChangeWithGuardian' => arpRequest(RequestTypeEnum::dobChange->value, $person, $other, $person, ['newDob' => '2010-01-01', 'priorDob' => '1990-01-01']),
    ];

    $respond = EnsureUserCanRespondToRequestAction::new();

    foreach ([arpAdmin(), arpAdmin(AdministratorTypeEnum::super->value)] as $admin) {
        foreach ($partyOnly as $name => $request) {
            expect($respond->userCanRespond($admin, $request->fresh()))->toBeFalse("{$name} ({$admin->administrator->type})");
        }
    }

    // Through the endpoint, too: refused before anything changes.
    $this->actingAs(arpAdmin(AdministratorTypeEnum::super->value))
        ->postJson(route('requests.respond', ['requestId' => $partyOnly['guardianship']->id]), ['response' => 'accepted'])
        ->assertStatus(422)
        ->assertJsonPath('error', EnsureUserCanRespondToRequestAction::NOT_ALLOWED);
    expect($partyOnly['guardianship']->fresh()->status)->toBe(RequestStatusEnum::pending->value);

    // The real party still can.
    expect($respond->userCanRespond($other, $partyOnly['guardianship']->fresh()))->toBeTrue()
        ->and($respond->userCanRespond($other, $partyOnly['dobChangeWithGuardian']->fresh()))->toBeFalse(); // not a guardian of theirs
});

test('an administrator may answer each admin queue type, but never their own age verification', function () {
    $super = arpAdmin(AdministratorTypeEnum::super->value);
    $admin = arpAdmin();
    $person = User::factory()->adult()->create();
    $counsellor = Counsellor::factory()->create(['user_id' => User::factory()->adult()->create()->id]);
    $organization = Organization::factory()->create();
    $transaction = Transaction::factory()->create(['user_id' => $person->id, 'for_type' => Therapy::class, 'for_id' => Therapy::factory()->create(['addedby_type' => User::class, 'addedby_id' => $person->id])->id, 'status' => 'SUCCESS']);
    $respond = EnsureUserCanRespondToRequestAction::new();

    $queue = [
        arpRequest(RequestTypeEnum::counsellor->value, $counsellor, $super, $counsellor),
        arpRequest(RequestTypeEnum::organization->value, $organization, $super, $organization),
        arpRequest(RequestTypeEnum::ageVerification->value, $person, null, $person, ['attestation' => 'x', 'attestedDob' => '1990-01-01']),
        arpRequest(RequestTypeEnum::dobChange->value, $person, null, $person, ['newDob' => '2010-01-01', 'priorDob' => '1990-01-01']),
        arpRequest(RequestTypeEnum::refund->value, $person, null, $transaction, ['reason' => 'No show.']),
    ];

    foreach ($queue as $request) {
        expect(AdminRequestQueue::includes($request->type))->toBeTrue()
            ->and($respond->userCanRespond($admin, $request->fresh()))->toBeTrue($request->type);
    }

    $own = arpRequest(RequestTypeEnum::ageVerification->value, $admin, null, $admin, ['attestation' => 'x', 'attestedDob' => '1990-01-01']);
    expect($respond->userCanRespond($admin, $own))->toBeFalse()
        ->and($own->isOwnAgeVerificationOf($admin))->toBeTrue()
        ->and(Request::query()->ownAgeVerificationOf($admin)->pluck('id')->all())->toBe([$own->id])
        ->and($respond->userCanRespond($super, $own))->toBeTrue();
});

test('an administrator may not answer a queue request they raised or that is about them', function () {
    $admin = arpAdmin();
    $other = arpAdmin();
    $adminCounsellor = Counsellor::factory()->create(['user_id' => $admin->id]);
    $transaction = Transaction::factory()->create(['user_id' => $admin->id, 'for_type' => Therapy::class, 'for_id' => Therapy::factory()->create(['addedby_type' => User::class, 'addedby_id' => $admin->id])->id, 'status' => 'SUCCESS']);
    $respond = EnsureUserCanRespondToRequestAction::new();

    $own = [
        arpRequest(RequestTypeEnum::counsellor->value, $adminCounsellor, arpAdmin(AdministratorTypeEnum::super->value), $adminCounsellor),
        arpRequest(RequestTypeEnum::refund->value, $admin, null, $transaction, ['reason' => 'No show.']),
        arpRequest(RequestTypeEnum::dobChange->value, $admin, null, $admin, ['newDob' => '2010-01-01', 'priorDob' => '1990-01-01']),
    ];

    foreach ($own as $request) {
        expect($respond->userCanRespond($admin, $request->fresh()))->toBeFalse($request->type)
            ->and($respond->userCanRespond($other, $request->fresh()))->toBeTrue($request->type);
    }
});

test('answering an organisation verification never returns the super admin\'s profile', function () {
    $super = arpAdmin(AdministratorTypeEnum::super->value);
    $admin = arpAdmin();
    $organization = Organization::factory()->create();
    $request = arpRequest(RequestTypeEnum::organization->value, $organization, $super, $organization);

    $response = $this->actingAs($admin)->postJson(route('requests.respond', ['requestId' => $request->id]), ['response' => 'rejected'])->assertSuccessful();

    expect(json_encode($response->json()))->not->toContain('"gender"')->not->toContain('"dob"')->not->toContain('Ghana');
});

test('who answered is recorded and shown to administrators in the queue, by name only', function () {
    $super = arpAdmin(AdministratorTypeEnum::super->value);
    $admin = arpAdmin();
    $organization = Organization::factory()->create();
    $request = arpRequest(RequestTypeEnum::organization->value, $organization, $super, $organization);

    $this->actingAs($admin)->postJson(route('requests.respond', ['requestId' => $request->id]), ['response' => 'rejected'])->assertSuccessful();

    expect($request->fresh()->responded_by_id)->toBe($admin->id);

    $response = $this->actingAs(arpAdmin())->get(route('administrator.requests', ['status' => 'all']));
    expect(arpQueueRow($response, $request->id)['respondedBy'])->toBe([
        'id' => $admin->id, 'fullName' => $admin->name, 'username' => $admin->username, 'isUser' => true,
    ]);
});

test('a request closed by the system, or a no-op re-answer, records no one', function () {
    $admin = arpAdmin();
    $person = User::factory()->adult()->create();
    $request = arpRequest(RequestTypeEnum::dobChange->value, $person, null, $person, ['newDob' => '2010-01-01', 'priorDob' => '1990-01-01']);

    $request->update(['status' => RequestStatusEnum::superseded->value]);
    expect($request->fresh()->responded_by_id)->toBeNull();

    // Already decided: refused as no longer pending, and still nobody recorded.
    $this->actingAs($admin)->postJson(route('requests.respond', ['requestId' => $request->id]), ['response' => 'accepted'])->assertStatus(422);
    expect($request->fresh()->responded_by_id)->toBeNull();
});

test('an ordinary admin\'s queue never carries the super admin\'s or a payer\'s dob, gender or country', function () {
    $super = arpAdmin(AdministratorTypeEnum::super->value);
    $payer = User::factory()->adult()->create(['gender' => 'MALE', 'country' => 'Ghana']);
    $counsellor = Counsellor::factory()->create(['user_id' => User::factory()->adult()->create()->id]);
    $organization = Organization::factory()->create();
    $transaction = Transaction::factory()->create(['user_id' => $payer->id, 'for_type' => Therapy::class, 'for_id' => Therapy::factory()->create(['addedby_type' => User::class, 'addedby_id' => $payer->id])->id, 'status' => 'SUCCESS']);

    $verification = arpRequest(RequestTypeEnum::counsellor->value, $counsellor, $super, $counsellor);
    $organisation = arpRequest(RequestTypeEnum::organization->value, $organization, $super, $organization);
    $refund = arpRequest(RequestTypeEnum::refund->value, $payer, null, $transaction, ['reason' => 'No show.']);

    $response = $this->actingAs(arpAdmin())->get(route('administrator.requests'));

    foreach ([$verification, $organisation, $refund] as $request) {
        $row = arpQueueRow($response, $request->id);
        expect($row['to'])->toBeNull($request->type);
        foreach (['from', 'for'] as $side) {
            expect(is_array($row[$side]) ? array_intersect_key($row[$side], array_flip(['dob', 'gender', 'country'])) : [])->toBe([], "{$request->type} {$side}");
        }
    }
    expect(arpQueueRow($response, $refund->id)['from'])->toBe(['id' => $payer->id, 'fullName' => $payer->name, 'username' => $payer->username, 'isUser' => true]);

    // The refund requests page narrows the payer the same way.
    $this->actingAs(arpAdmin())->get(route('administrator.refund_requests'))->assertInertia(fn ($page) => $page
        ->where('refundRequests.data.0.from', ['id' => $payer->id, 'fullName' => $payer->name, 'username' => $payer->username, 'isUser' => true]));
});

test('respondedBy is for administrators only, and never on a row the viewer is involved in', function () {
    $counsellorUser = User::factory()->adult()->create();
    $counsellor = Counsellor::factory()->create(['user_id' => $counsellorUser->id]);
    $super = arpAdmin(AdministratorTypeEnum::super->value);
    $request = arpRequest(RequestTypeEnum::counsellor->value, $counsellor, $super, $counsellor);

    $this->actingAs(arpAdmin())->postJson(route('requests.respond', ['requestId' => $request->id]), ['response' => 'rejected'])->assertSuccessful();

    // The counsellor who asked sees their answered request, but not which administrator decided.
    $own = collect($this->actingAs($counsellorUser)->getJson(route('requests.get', ['status' => 'REJECTED']))->json('data'))->firstWhere('id', $request->id);
    expect($own)->not->toBeNull()->not->toHaveKey('respondedBy');

    // A super admin who counsels the minor sees only that a safeguarding request exists -- not who
    // answered it either.
    $involved = arpAdmin(AdministratorTypeEnum::super->value);
    $minor = User::factory()->create(['dob' => now()->subYears(15)->toDateString()]);
    $therapy = Therapy::factory()->create([
        'addedby_type' => User::class, 'addedby_id' => $minor->id,
        'counsellor_id' => Counsellor::factory()->create(['user_id' => $involved->id])->id,
        'status' => TherapyStatusEnum::in_session->value, 'client_was_minor_at_creation' => true,
    ]);
    $safeguarding = arpRequest(RequestTypeEnum::safeguardingException->value, $counsellor, null, $therapy, ['reason' => 'x', 'proposedEndsAt' => now()->addDays(5)->toJSON(), 'wardId' => $minor->id]);
    $this->actingAs($super)->postJson(route('requests.respond', ['requestId' => $safeguarding->id]), ['response' => 'rejected'])->assertSuccessful();
    expect($safeguarding->fresh()->responded_by_id)->toBe($super->id);

    $row = arpQueueRow($this->actingAs($involved)->get(route('administrator.requests', ['status' => 'all'])), $safeguarding->id);
    expect($row['safeguardingException']['involved'])->toBeTrue()
        ->and($row)->not->toHaveKey('respondedBy');
});
