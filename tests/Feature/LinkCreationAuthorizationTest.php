<?php

use App\Actions\Link\EnsureUserCanCreateLinkForAction;
use App\DTOs\CreateLinkDTO;
use App\Exceptions\LinkException;
use App\Models\Administrator;
use App\Models\Counsellor;
use App\Models\Discussion;
use App\Models\GroupTherapy;
use App\Models\Link;
use App\Models\Therapy;
use App\Models\User;
use App\Services\LinkService;
use Illuminate\Support\Str;

// SCRUM-350: a link can only be created for a target the requester controls -- before, anyone could
// create a GUARDIANSHIP link for any user, a THERAPY_COUNSELLOR link for any therapy, or a
// DISCUSSION link for any discussion.

function linkPayloadFor350(User $creator, string $type, string $forType, int $forId, string $addedbyType = 'User', ?int $addedbyId = null): array
{
    return [
        'type' => $type,
        'addedbyType' => $addedbyType,
        'addedbyId' => $addedbyId ?? $creator->id,
        'forType' => $forType,
        'forId' => $forId,
    ];
}

function refusedFor350($response): void
{
    $response->assertStatus(403)->assertJsonPath('message', EnsureUserCanCreateLinkForAction::NOT_ALLOWED);
}

test('a guardianship link can only be for yourself', function () {
    $user = User::factory()->create();
    $victim = User::factory()->create();

    refusedFor350($this->actingAs($user)->postJson(route('api.links.create'), linkPayloadFor350($user, 'GUARDIANSHIP', 'User', $victim->id)));
    $this->actingAs($user)->postJson(route('api.links.create'), linkPayloadFor350($user, 'GUARDIANSHIP', 'User', $user->id))->assertSuccessful();

    expect(Link::query()->where('for_id', $victim->id)->exists())->toBeFalse();
});

test('a therapy counsellor link can only be created by that therapy\'s client', function () {
    $client = User::factory()->create();
    $therapy = Therapy::factory()->create(['addedby_type' => User::class, 'addedby_id' => $client->id, 'counsellor_id' => null]);
    $stranger = User::factory()->create();

    refusedFor350($this->actingAs($stranger)->postJson(route('api.links.create'), linkPayloadFor350($stranger, 'THERAPY_COUNSELLOR', 'Therapy', $therapy->id)));
    $this->actingAs($client)->postJson(route('api.links.create'), linkPayloadFor350($client, 'THERAPY_COUNSELLOR', 'Therapy', $therapy->id))->assertSuccessful();
});

test('group links can only be created by the group\'s creator, a user or a counsellor', function () {
    $creator = User::factory()->create();
    $group = GroupTherapy::factory()->create(['addedby_type' => User::class, 'addedby_id' => $creator->id]);
    $counsellorCreator = Counsellor::factory()->create(['user_id' => User::factory()->create()->id]);
    $counsellorGroup = GroupTherapy::factory()->create(['addedby_type' => Counsellor::class, 'addedby_id' => $counsellorCreator->id]);
    $member = User::factory()->create();
    $group->users()->attach($member->id, ['anonymous' => false]);

    refusedFor350($this->actingAs($member)->postJson(route('api.links.create'), linkPayloadFor350($member, 'GROUP_THERAPY_COUNSELLOR', 'GroupTherapy', $group->id)));
    $this->actingAs($creator)->postJson(route('api.links.create'), linkPayloadFor350($creator, 'GROUP_THERAPY_COUNSELLOR', 'GroupTherapy', $group->id))->assertSuccessful();
    $this->actingAs($counsellorCreator->user)->postJson(route('api.links.create'), linkPayloadFor350($counsellorCreator->user, 'GROUP_THERAPY_COUNSELLOR', 'GroupTherapy', $counsellorGroup->id))->assertSuccessful();
});

test('a discussion link can only be created by someone who manages the discussion', function () {
    $owner = Counsellor::factory()->create(['user_id' => User::factory()->create()->id]);
    $discussion = Discussion::factory()->create(['addedby_type' => Counsellor::class, 'addedby_id' => $owner->id]);
    $other = Counsellor::factory()->create(['user_id' => User::factory()->create()->id]);

    refusedFor350($this->actingAs($other->user)->postJson(route('api.links.create'), linkPayloadFor350($other->user, 'DISCUSSION', 'Discussion', $discussion->id, 'Counsellor', $other->id)));
    $this->actingAs($owner->user)->postJson(route('api.links.create'), linkPayloadFor350($owner->user, 'DISCUSSION', 'Discussion', $discussion->id, 'Counsellor', $owner->id))->assertSuccessful();
});

test('a missing target gets the same refusal as someone else\'s', function () {
    $user = User::factory()->create();

    refusedFor350($this->actingAs($user)->postJson(route('api.links.create'), linkPayloadFor350($user, 'THERAPY_COUNSELLOR', 'Therapy', 999999)));
    refusedFor350($this->actingAs($user)->postJson(route('api.links.create'), linkPayloadFor350($user, 'DISCUSSION', 'Discussion', 999999)));
});

// Security review: links created before the fix -- or whose creator has since lost authority --
// must not work when used, and a creator can't make one by naming someone else as its creator.

function anOldLinkFor350(User $creator, string $type, $for): Link
{
    return Link::query()->forceCreate([
        'uuid' => (string) Str::uuid(), 'type' => $type, 'state' => 'ACTIVE',
        'addedby_type' => User::class, 'addedby_id' => $creator->id,
        'for_type' => $for::class, 'for_id' => $for->id,
    ]);
}

test('an old guardianship link for someone else can\'t be used, even though it is active', function () {
    $attacker = User::factory()->create();
    $victim = User::factory()->create();
    $link = anOldLinkFor350($attacker, 'GUARDIANSHIP', $victim);
    $accomplice = User::factory()->create();

    expect(fn () => LinkService::new()->performAction(CreateLinkDTO::new()->fromArray(['user' => $accomplice, 'link' => $link])))
        ->toThrow(LinkException::class, 'This link is no longer active.');
    expect($accomplice->wards()->where('ward_id', $victim->id)->exists())->toBeFalse();

    // A legitimate one still works.
    $own = anOldLinkFor350($victim, 'GUARDIANSHIP', $victim);
    LinkService::new()->performAction(CreateLinkDTO::new()->fromArray(['user' => $accomplice, 'link' => $own]));
    expect($accomplice->wards()->where('ward_id', $victim->id)->exists())->toBeTrue();
});

test('an admin can\'t issue a link in someone else\'s name', function () {
    $admin = User::factory()->has(Administrator::factory())->create();
    $owner = Counsellor::factory()->create(['user_id' => User::factory()->create()->id]);
    $discussion = Discussion::factory()->create(['addedby_type' => Counsellor::class, 'addedby_id' => $owner->id]);

    refusedFor350($this->actingAs($admin)->postJson(route('api.links.create'), linkPayloadFor350($admin, 'DISCUSSION', 'Discussion', $discussion->id, 'Counsellor', $owner->id)));
});

test('the clean-up migration removes links their creators could never have issued', function () {
    $attacker = User::factory()->create();
    $bad = anOldLinkFor350($attacker, 'GUARDIANSHIP', User::factory()->create());
    $good = anOldLinkFor350($attacker, 'GUARDIANSHIP', $attacker);

    (require database_path('migrations/2026_10_31_150000_remove_links_their_creators_could_not_issue.php'))->up();

    expect(Link::find($bad->id))->toBeNull()->and(Link::find($good->id))->not->toBeNull();
});
