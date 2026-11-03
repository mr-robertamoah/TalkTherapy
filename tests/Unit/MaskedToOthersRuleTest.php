<?php

use App\Enums\ConstantsEnum;
use App\Models\Counsellor;
use App\Models\GroupTherapy;
use App\Models\Therapy;
use App\Models\User;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\DB;

// SCRUM-379: Therapy/GroupTherapy::isMaskedToOthers() decides "is this person shown as Anonymous to
// everyone else" for video names and tokens, presence channels, and creator masking on pages, requests
// and notifications. It replaced three copies (VideoDisplayName, the presence channels in
// routes/channels.php, and addedByUserIsMaskedFor()'s own isAnonymousFor() call). Message and session
// id masking and comments still use isAnonymousFor()/isAnonymousParticipant() -- SCRUM-380. Each case below is
// one the old copies decided, checked through the rule, the name it gives, and -- where the case can
// reach them -- the presence channel and the creator-masking used by the resources.

function mtorTherapy(bool $anonymous): array
{
    $client = User::factory()->create(['firstName' => 'Real', 'lastName' => 'Client']);
    $counsellorUser = User::factory()->create();
    $counsellor = Counsellor::factory()->create(['user_id' => $counsellorUser->id]);
    $therapy = Therapy::factory()->create([
        'addedby_type' => User::class,
        'addedby_id' => $client->id,
        'counsellor_id' => $counsellor->id,
        'anonymous' => $anonymous,
    ]);

    return compact('client', 'counsellorUser', 'therapy');
}

function mtorGroup(bool $anonymous): array
{
    $creator = User::factory()->create(['firstName' => 'Group', 'lastName' => 'Creator']);
    $counsellorUser = User::factory()->create();
    $counsellor = Counsellor::factory()->create(['user_id' => $counsellorUser->id]);
    $group = GroupTherapy::factory()->create([
        'addedby_type' => User::class,
        'addedby_id' => $creator->id,
        'anonymous' => $anonymous,
    ]);
    $group->counsellors()->attach($counsellor->id, ['state' => 'ACTIVE', 'role' => 'NORMAL']);

    return compact('creator', 'counsellorUser', 'counsellor', 'group');
}

function mtorMember(GroupTherapy $group, bool $anonymous): User
{
    $member = User::factory()->create(['firstName' => 'Some', 'lastName' => 'Member']);
    $group->users()->attach($member->id, ['anonymous' => $anonymous]);

    return $member;
}

function mtorPresenceName(string $pattern, User $user, int $id): ?string
{
    $channels = (fn () => $this->channels)->call(Broadcast::connection());

    return $channels[$pattern]($user, $id)['name'] ?? null;
}

describe('1:1 therapy', function () {
    test('an anonymous client is masked to others, by name, on the presence channel and to the counsellor', function () {
        $data = mtorTherapy(anonymous: true);
        $therapy = $data['therapy'];

        expect($therapy->isMaskedToOthers($data['client']))->toBeTrue()
            ->and($therapy->displayNameOf($data['client']))->toBe(ConstantsEnum::anonymousUserLabel->value)
            ->and(mtorPresenceName('therapies.{therapyId}', $data['client'], $therapy->id))->toBe(ConstantsEnum::anonymousUserLabel->value)
            ->and($therapy->addedByUserIsMaskedFor($data['counsellorUser']))->toBeTrue()
            // Never to themselves.
            ->and($therapy->addedByUserIsMaskedFor($data['client']))->toBeFalse();
    });

    test('a client of a non-anonymous therapy is shown by name', function () {
        $data = mtorTherapy(anonymous: false);

        expect($data['therapy']->isMaskedToOthers($data['client']))->toBeFalse()
            ->and($data['therapy']->displayNameOf($data['client']))->toBe('Real Client')
            ->and($data['therapy']->addedByUserIsMaskedFor($data['counsellorUser']))->toBeFalse();
    });

    test('the counsellor is never masked, even on an anonymous therapy', function () {
        $data = mtorTherapy(anonymous: true);

        expect($data['therapy']->isMaskedToOthers($data['counsellorUser']))->toBeFalse()
            ->and(mtorPresenceName('therapies.{therapyId}', $data['counsellorUser'], $data['therapy']->id))->toBe($data['counsellorUser']->name);
    });

    test('an anonymous client keeps their camera (only the counsellor sees them)', function () {
        $data = mtorTherapy(anonymous: true);

        expect($data['therapy']->allowsCameraFor($data['client']))->toBeTrue()
            ->and($data['therapy']->allowsCameraFor($data['counsellorUser']))->toBeTrue();
    });
});

describe('group therapy', function () {
    test('the creator is masked by the group flag, everywhere, and keeps no camera', function () {
        $data = mtorGroup(anonymous: true);
        $group = $data['group'];

        expect($group->isMaskedToOthers($data['creator']))->toBeTrue()
            ->and($group->displayNameOf($data['creator']))->toBe(ConstantsEnum::anonymousUserLabel->value)
            ->and(mtorPresenceName('groupTherapies.{groupTherapyId}', $data['creator'], $group->id))->toBe(ConstantsEnum::anonymousUserLabel->value)
            ->and($group->addedByUserIsMaskedFor($data['counsellorUser']))->toBeTrue()
            ->and($group->addedByUserIsMaskedFor($data['creator']))->toBeFalse()
            ->and($group->allowsCameraFor($data['creator']))->toBeFalse();
    });

    test('the creator of a non-anonymous group is named and has a camera (no membership row needed)', function () {
        $data = mtorGroup(anonymous: false);

        expect($data['group']->isMaskedToOthers($data['creator']))->toBeFalse()
            ->and($data['group']->displayNameOf($data['creator']))->toBe('Group Creator')
            ->and($data['group']->addedByUserIsMaskedFor($data['counsellorUser']))->toBeFalse()
            ->and($data['group']->allowsCameraFor($data['creator']))->toBeTrue();
    });

    test('a member is masked by the group flag even when their own row is not anonymous', function () {
        $data = mtorGroup(anonymous: true);
        $member = mtorMember($data['group'], anonymous: false);

        expect($data['group']->isMaskedToOthers($member))->toBeTrue()
            ->and($data['group']->allowsCameraFor($member))->toBeFalse();
    });

    test('a member is masked by their own row\'s flag in a non-anonymous group', function () {
        $data = mtorGroup(anonymous: false);
        $member = mtorMember($data['group'], anonymous: true);

        expect($data['group']->isMaskedToOthers($member))->toBeTrue()
            ->and($data['group']->displayNameOf($member))->toBe(ConstantsEnum::anonymousUserLabel->value)
            ->and($data['group']->allowsCameraFor($member))->toBeFalse();
    });

    test('a member with neither flag is named', function () {
        $data = mtorGroup(anonymous: false);
        $member = mtorMember($data['group'], anonymous: false);

        expect($data['group']->isMaskedToOthers($member))->toBeFalse()
            ->and($data['group']->displayNameOf($member))->toBe('Some Member');
    });

    test('a counsellor of an anonymous group is never masked and keeps their camera', function () {
        $data = mtorGroup(anonymous: true);

        expect($data['group']->isMaskedToOthers($data['counsellorUser']))->toBeFalse()
            ->and(mtorPresenceName('groupTherapies.{groupTherapyId}', $data['counsellorUser'], $data['group']->id))->toBe($data['counsellorUser']->name)
            ->and($data['group']->allowsCameraFor($data['counsellorUser']))->toBeTrue();
    });

    test('the counsellor who created an anonymous group is never masked, and the group has no masked creator', function () {
        $counsellorUser = User::factory()->create();
        $counsellor = Counsellor::factory()->create(['user_id' => $counsellorUser->id]);
        $group = GroupTherapy::factory()->create([
            'addedby_type' => Counsellor::class,
            'addedby_id' => $counsellor->id,
            'anonymous' => true,
        ]);

        expect($group->isMaskedToOthers($counsellorUser))->toBeFalse()
            ->and($group->addedByUserIsMaskedFor(User::factory()->create()))->toBeFalse();
    });

    test('SCRUM-334 security review: someone no longer a member is masked, even in a non-anonymous group', function () {
        $data = mtorGroup(anonymous: false);
        $member = mtorMember($data['group'], anonymous: false);
        DB::table('group_therapy_user')->where('user_id', $member->id)->delete();

        expect($data['group']->fresh()->isMaskedToOthers($member))->toBeTrue()
            ->and($data['group']->fresh()->displayNameOf($member))->toBe(ConstantsEnum::anonymousUserLabel->value);
    });

    // The one precedence change: the old video/channel copies checked "counsellor" first, while
    // addedByUserIsMaskedFor() (the group page, requests, notifications) masked the creator by the
    // group flag regardless -- so the same person could be masked on the page but named in the call.
    // The creator rule now comes first everywhere (the masked side).
    test('a creator who is also one of the group\'s counsellors is masked by the group flag, as on the group page', function () {
        $data = mtorGroup(anonymous: true);
        $creatorCounsellor = Counsellor::factory()->create(['user_id' => $data['creator']->id]);
        $data['group']->counsellors()->attach($creatorCounsellor->id, ['state' => 'ACTIVE', 'role' => 'NORMAL']);

        expect($data['group']->isMaskedToOthers($data['creator']))->toBeTrue()
            ->and($data['group']->addedByUserIsMaskedFor($data['counsellorUser']))->toBeTrue()
            // Reviewer: and so, in a call, they join without a camera (while still able to moderate).
            ->and($data['group']->allowsCameraFor($data['creator']))->toBeFalse()
            ->and($data['group']->isCounsellorUser($data['creator']))->toBeTrue();
    });

    test('a lookup reads only the subject\'s own membership row, never the whole member list', function () {
        $data = mtorGroup(anonymous: false);
        $member = mtorMember($data['group'], anonymous: true);
        foreach (range(1, 5) as $i) {
            mtorMember($data['group'], anonymous: false);
        }
        $group = GroupTherapy::find($data['group']->id);

        expect($group->isMaskedToOthers($member))->toBeTrue()
            ->and($group->relationLoaded('users'))->toBeFalse();
    });

    test('an eager-loaded member list is reused rather than queried again', function () {
        $data = mtorGroup(anonymous: false);
        $member = mtorMember($data['group'], anonymous: true);
        $group = GroupTherapy::with('users')->find($data['group']->id);

        DB::enableQueryLog();
        $membership = $group->membershipOf($member);
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        expect((bool) $membership->anonymous)->toBeTrue()
            ->and($queries)->toBeEmpty();
    });
});
