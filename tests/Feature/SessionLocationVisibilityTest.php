<?php

use App\Enums\CounsellorGroupTherapyStateEnum;
use App\Http\Resources\SessionResource;
use App\Models\Administrator;
use App\Models\Counsellor;
use App\Models\GroupTherapy;
use App\Models\Guardianship;
use App\Models\Session;
use App\Models\Therapy;
use App\Models\User;
use Illuminate\Http\Request as HttpRequest;

// SCRUM-442 (SCRUM-115 hotfix): where an in-person session meets is only for those in the therapy or group, and
// administrators -- the session list is readable by guests for a public therapy.

function slocInPerson(string $forType, int $forId): Session
{
    return Session::factory()->create([
        'for_type' => $forType, 'for_id' => $forId, 'type' => 'IN_PERSON',
        'latitude' => 5.6037, 'longitude' => -0.1870, 'landmark' => 'sloc Room 3, 2nd floor',
    ]);
}

function slocView(Session $session, ?User $viewer): array
{
    return (new SessionResource($session->fresh()))->resolve(tap(HttpRequest::create('/'), fn ($r) => $r->setUserResolver(fn () => $viewer)));
}

function slocPublicTherapy(): array
{
    $client = User::factory()->adult()->create();
    $counsellor = Counsellor::factory()->create(['user_id' => User::factory()->adult()->create()->id]);
    $therapy = Therapy::factory()->create(['addedby_type' => User::class, 'addedby_id' => $client->id, 'counsellor_id' => $counsellor->id, 'public' => true, 'allow_in_person' => true]);

    return [$client, $counsellor, $therapy, slocInPerson(Therapy::class, $therapy->id)];
}

test('a guest and a signed-in outsider see a public therapy\'s sessions, but not where they meet', function () {
    [, , $therapy] = slocPublicTherapy();

    foreach ([null, User::factory()->adult()->create()] as $who) {
        $request = $who ? $this->actingAs($who) : $this;
        $row = $request->getJson(route('api.sessions.get', ['therapyId' => $therapy->id]))->assertOk()->json('data.0');

        expect($row['type'])->toBe('IN_PERSON')
            ->and($row['lat'])->toBeNull()->and($row['lng'])->toBeNull()->and($row['landmark'])->toBeNull();
    }
});

test('the client, the counsellor and an administrator see where it meets', function () {
    [$client, $counsellor, $therapy] = slocPublicTherapy();
    $admin = User::factory()->adult()->has(Administrator::factory())->create();

    foreach ([$client, $counsellor->user, $admin] as $who) {
        $row = $this->actingAs($who)->getJson(route('api.sessions.get', ['therapyId' => $therapy->id]))->assertOk()->json('data.0');

        expect($row['landmark'])->toBe('sloc Room 3, 2nd floor')->and((float) $row['lat'])->toBe(5.6037);
    }
});

test('a guardian of the client is not shown the place (not a participant)', function () {
    [$client, , , $session] = slocPublicTherapy();
    $guardian = User::factory()->adult()->create();
    Guardianship::query()->create(['guardian_id' => $guardian->id, 'ward_id' => $client->id]);

    expect(slocView($session, $guardian)['landmark'])->toBeNull();
});

test('a group: its members and active counsellors see the place; anyone else does not', function () {
    $counsellor = Counsellor::factory()->create(['user_id' => User::factory()->adult()->create()->id]);
    $member = User::factory()->adult()->create();
    $group = GroupTherapy::factory()->create(['addedby_type' => User::class, 'addedby_id' => User::factory()->adult()->create()->id, 'public' => true, 'allow_in_person' => true]);
    $group->counsellors()->attach($counsellor->id, ['state' => CounsellorGroupTherapyStateEnum::active->value, 'role' => 'NORMAL']);
    $group->users()->attach($member->id, ['anonymous' => false, 'background_story' => 'x']);
    $session = slocInPerson(GroupTherapy::class, $group->id);

    expect(slocView($session, $member)['landmark'])->toBe('sloc Room 3, 2nd floor')
        ->and(slocView($session, $counsellor->user)['landmark'])->toBe('sloc Room 3, 2nd floor')
        ->and(slocView($session, User::factory()->adult()->create())['landmark'])->toBeNull()
        ->and(slocView($session, null)['lat'])->toBeNull();
});
