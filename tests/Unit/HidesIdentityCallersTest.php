<?php

use App\Actions\Comment\CreateCommentAction;
use App\DTOs\CreateCommentDTO;
use App\Enums\ConstantsEnum;
use App\Enums\RequestTypeEnum;
use App\Http\Resources\CommentResource;
use App\Http\Resources\MessageResource;
use App\Http\Resources\SessionResource;
use App\Models\Comment;
use App\Models\Counsellor;
use App\Models\GroupTherapy;
use App\Models\Message;
use App\Models\Request as AppRequest;
use App\Models\Session;
use App\Models\Therapy;
use App\Models\User;
use App\Notifications\TherapyAssistanceRequestAcceptedNotification;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

// SCRUM-380: the callers that used isAnonymousFor() / isAnonymousParticipant() now use
// TherapyTrait::hidesIdentityOf() (isMaskedToOthers() plus the old rule's masking). Every case each
// caller can meet, before and after. Two changed, both towards masking:
// - someone with no membership row (left the group, or an administrator) in a non-anonymous group;
// - a session whose therapy can't be resolved.
// Everything else gives the old answer -- including counsellors acting through their own User
// account, whom isMaskedToOthers() alone would have shown.

function hicrOneToOne(bool $anonymous): array
{
    $client = User::factory()->create();
    $counsellorUser = User::factory()->create();
    $counsellor = Counsellor::factory()->create(['user_id' => $counsellorUser->id]);
    $therapy = Therapy::factory()->create([
        'addedby_type' => User::class,
        'addedby_id' => $client->id,
        'counsellor_id' => $counsellor->id,
        'anonymous' => $anonymous,
        'public' => true,
    ]);
    $session = Session::factory()->create(['for_id' => $therapy->id, 'for_type' => Therapy::class]);

    return compact('client', 'counsellorUser', 'counsellor', 'therapy', 'session');
}

function hicrGroup(bool $anonymous): array
{
    $creator = User::factory()->create();
    $counsellorUser = User::factory()->create();
    $counsellor = Counsellor::factory()->create(['user_id' => $counsellorUser->id]);
    $group = GroupTherapy::factory()->create([
        'addedby_type' => User::class,
        'addedby_id' => $creator->id,
        'anonymous' => $anonymous,
        'public' => true,
    ]);
    $group->counsellors()->attach($counsellor->id, ['state' => 'ACTIVE', 'role' => 'NORMAL']);
    $session = Session::factory()->create(['for_id' => $group->id, 'for_type' => GroupTherapy::class]);

    return compact('creator', 'counsellorUser', 'counsellor', 'group', 'session');
}

function hicrMember(GroupTherapy $group, bool $anonymous): User
{
    $member = User::factory()->create();
    $group->users()->attach($member->id, ['anonymous' => $anonymous]);

    return $member;
}

function hicrMessage(Session $session, Model $from, ?Model $to = null): Message
{
    return Message::factory()->create([
        'from_type' => $from::class,
        'from_id' => $from->id,
        'to_type' => $to ? $to::class : null,
        'to_id' => $to?->id,
        'for_type' => Session::class,
        'for_id' => $session->id,
        'confidential' => false,
    ])->fresh();
}

function hicrPresent($resource, ?User $viewer): array
{
    return json_decode(json_encode(
        $resource->toArray(Request::create('/')->setUserResolver(fn () => $viewer))
    ), true);
}

function hicrSessionUpdatedBy(Session $session, User $user): Session
{
    $session->forceFill(['updatedby_type' => User::class, 'updatedby_id' => $user->id])->save();

    return $session->fresh();
}

function hicrAcceptedName(Model $for, ?User $to): string
{
    $counsellor = Counsellor::factory()->create(['user_id' => User::factory()->create()->id]);
    $request = AppRequest::query()->forceCreate([
        'from_type' => Counsellor::class, 'from_id' => $counsellor->id,
        'to_type' => User::class, 'to_id' => $to?->id ?? 999999,
        'for_type' => $for::class, 'for_id' => $for->id,
        'type' => RequestTypeEnum::therapy->value, 'status' => 'ACCEPTED', 'data' => [],
    ])->fresh();

    return (new TherapyAssistanceRequestAcceptedNotification($request))->toBroadcast($counsellor)->data['to'];
}

describe('MessageResource sender and recipient ids', function () {
    // 1:1: a User sender/recipient is the client (ResolveTherapyMessageRecipientAction); the
    // counsellor-as-User case is older data, from before SCRUM-348.
    test('1:1 cases', function (bool $anonymous, string $who, bool $masked) {
        $t = hicrOneToOne($anonymous);
        $subject = $who === 'client' ? $t['client'] : $t['counsellorUser'];
        $viewer = User::factory()->create();

        $sent = MessageResource::present(hicrMessage($t['session'], $subject), $viewer);
        $received = MessageResource::present(hicrMessage($t['session'], $t['counsellor'], $subject), $viewer);

        expect($sent['fromUserId'])->toBe($masked ? null : $subject->id)
            ->and($received['toUserId'])->toBe($masked ? null : $subject->id);
    })->with([
        'anonymous, client (unchanged: masked)' => [true, 'client', true],
        'not anonymous, client (unchanged: shown)' => [false, 'client', false],
        'anonymous, counsellor as their User (unchanged: masked)' => [true, 'counsellorUser', true],
        'not anonymous, counsellor as their User (unchanged: shown)' => [false, 'counsellorUser', false],
    ]);

    test('group cases', function (bool $anonymous, string $who, bool $masked) {
        $g = hicrGroup($anonymous);
        $subject = match ($who) {
            'creator' => $g['creator'],
            'anonymous member' => hicrMember($g['group'], true),
            'member' => hicrMember($g['group'], false),
            'counsellor' => $g['counsellorUser'],
            'no row' => User::factory()->create(),
        };
        $viewer = hicrMember($g['group'], false);

        $sent = MessageResource::present(hicrMessage($g['session'], $subject), $viewer);
        $received = MessageResource::present(hicrMessage($g['session'], $g['counsellor'], $subject), $viewer);

        expect($sent['fromUserId'])->toBe($masked ? null : $subject->id)
            ->and($received['toUserId'])->toBe($masked ? null : $subject->id);
    })->with([
        'anonymous group, creator (unchanged: masked)' => [true, 'creator', true],
        'anonymous group, member (unchanged: masked)' => [true, 'member', true],
        'anonymous group, counsellor as their User (unchanged: masked)' => [true, 'counsellor', true],
        'anonymous group, no row (unchanged: masked)' => [true, 'no row', true],
        'group, creator with no row (unchanged: shown)' => [false, 'creator', false],
        'group, anonymous member (unchanged: masked)' => [false, 'anonymous member', true],
        'group, member (unchanged: shown)' => [false, 'member', false],
        'group, counsellor as their User (unchanged: shown)' => [false, 'counsellor', false],
        'group, someone who left / no row (CHANGED: was shown, now masked)' => [false, 'no row', true],
    ]);

    test('a creator with an anonymous membership row of their own stays masked', function () {
        $g = hicrGroup(false);
        $g['group']->users()->attach($g['creator']->id, ['anonymous' => true]);

        expect(MessageResource::present(hicrMessage($g['session'], $g['creator']), User::factory()->create())['fromUserId'])->toBeNull();
    });

    // Not reachable today (a counsellor can't join as a member, nor a member become a counsellor),
    // but the old rule masked it, so it stays masked.
    test('a counsellor with an anonymous membership row of their own stays masked', function () {
        $g = hicrGroup(false);
        $g['group']->users()->attach($g['counsellorUser']->id, ['anonymous' => true]);

        expect(MessageResource::present(hicrMessage($g['session'], $g['counsellorUser']), User::factory()->create())['fromUserId'])->toBeNull();
    });

    test('the sender always sees their own id', function () {
        $g = hicrGroup(true);
        $member = hicrMember($g['group'], true);

        expect(MessageResource::present(hicrMessage($g['session'], $member), $member)['fromUserId'])->toBe($member->id);
    });

    test('a message in a session whose therapy is gone masks its User ids (CHANGED: was shown)', function () {
        $t = hicrOneToOne(false);
        $message = hicrMessage($t['session'], $t['client']);
        Session::query()->whereKey($t['session']->id)->update(['for_id' => 999999]);

        expect(MessageResource::present($message->fresh(), User::factory()->create())['fromUserId'])->toBeNull();
    });

    test('a message sent as a Counsellor profile is never masked', function () {
        $g = hicrGroup(true);

        expect(MessageResource::present(hicrMessage($g['session'], $g['counsellor']), User::factory()->create())['fromUserId'])
            ->toBe($g['counsellorUser']->id);
    });
});

describe('SessionResource addedby and updatedby ids', function () {
    test('1:1 cases', function (bool $anonymous, string $who, bool $masked) {
        $t = hicrOneToOne($anonymous);
        $subject = match ($who) {
            'client' => $t['client'],
            'counsellor' => $t['counsellorUser'],
            'admin' => User::factory()->create(),
        };
        $session = hicrSessionUpdatedBy($t['session'], $subject);

        expect(hicrPresent(new SessionResource($session), User::factory()->create())['updatedById'])->toBe($masked ? null : $subject->id);
    })->with([
        'anonymous, client (unchanged: masked)' => [true, 'client', true],
        'not anonymous, client (unchanged: shown)' => [false, 'client', false],
        // ChangeSessionStatusAction records the counsellor as their User when an admin added the session.
        'anonymous, counsellor as their User (unchanged: masked)' => [true, 'counsellor', true],
        'not anonymous, counsellor as their User (unchanged: shown)' => [false, 'counsellor', false],
        'anonymous, an administrator (unchanged: masked)' => [true, 'admin', true],
        'not anonymous, an administrator (unchanged: shown)' => [false, 'admin', false],
    ]);

    test('group cases', function (bool $anonymous, string $who, bool $masked) {
        $g = hicrGroup($anonymous);
        $subject = match ($who) {
            'creator' => $g['creator'],
            'anonymous member' => hicrMember($g['group'], true),
            'member' => hicrMember($g['group'], false),
            'counsellor' => $g['counsellorUser'],
            'no row' => User::factory()->create(),
        };
        $session = hicrSessionUpdatedBy($g['session'], $subject);

        expect(hicrPresent(new SessionResource($session), hicrMember($g['group'], false))['updatedById'])->toBe($masked ? null : $subject->id);
    })->with([
        'anonymous group, creator (unchanged: masked)' => [true, 'creator', true],
        'anonymous group, counsellor as their User (unchanged: masked)' => [true, 'counsellor', true],
        'anonymous group, no row (unchanged: masked)' => [true, 'no row', true],
        'group, creator with no row (unchanged: shown)' => [false, 'creator', false],
        'group, anonymous member (unchanged: masked)' => [false, 'anonymous member', true],
        'group, member (unchanged: shown)' => [false, 'member', false],
        'group, counsellor as their User (unchanged: shown)' => [false, 'counsellor', false],
        'group, someone who left or an administrator (CHANGED: was shown, now masked)' => [false, 'no row', true],
    ]);

    test('the person themselves still sees their own id', function () {
        $g = hicrGroup(false);
        $leaver = User::factory()->create();
        $session = hicrSessionUpdatedBy($g['session'], $leaver);

        expect(hicrPresent(new SessionResource($session), $leaver)['updatedById'])->toBe($leaver->id);
    });

    test('a Counsellor-recorded addedby is never masked', function () {
        $g = hicrGroup(true);
        $g['session']->forceFill(['addedby_type' => Counsellor::class, 'addedby_id' => $g['counsellor']->id])->save();

        expect(hicrPresent(new SessionResource($g['session']->fresh()), User::factory()->create())['userId'])->toBe($g['counsellorUser']->id);
    });
});

describe('TherapyAssistanceRequestAcceptedNotification', function () {
    // The accepting User is the 1:1 client in every real flow (TherapyService::sendAssistanceRequest);
    // group invites are accepted by a Counsellor. The other rows show the rule holds anyway.
    test('the accepting user\'s name', function (string $kind, bool $anonymous, string $who, bool $masked) {
        if ($kind === '1:1') {
            $t = hicrOneToOne($anonymous);
            [$for, $user] = [$t['therapy'], $who === 'client' ? $t['client'] : User::factory()->create()];
        } else {
            $g = hicrGroup($anonymous);
            $user = match ($who) {
                'anonymous member' => hicrMember($g['group'], true),
                'member' => hicrMember($g['group'], false),
                'no row' => User::factory()->create(),
            };
            $for = $g['group'];
        }

        expect(hicrAcceptedName($for, $user))->toBe($masked ? ConstantsEnum::anonymousPersonPhrase->value : $user->name);
    })->with([
        '1:1 anonymous, client (unchanged: masked)' => ['1:1', true, 'client', true],
        '1:1 not anonymous, client (unchanged: shown)' => ['1:1', false, 'client', false],
        '1:1 anonymous, someone else (unchanged: masked)' => ['1:1', true, 'other', true],
        '1:1 not anonymous, someone else (unchanged: shown)' => ['1:1', false, 'other', false],
        'group anonymous, member (unchanged: masked)' => ['group', true, 'member', true],
        'group, anonymous member (unchanged: masked)' => ['group', false, 'anonymous member', true],
        'group, member (unchanged: shown)' => ['group', false, 'member', false],
        'group, no row (CHANGED: was shown, now masked)' => ['group', false, 'no row', true],
    ]);

    test('a deleted accepting user is never named', function () {
        $t = hicrOneToOne(false);

        expect(hicrAcceptedName($t['therapy'], null))->toBe(ConstantsEnum::anonymousPersonPhrase->value);
    });
});

describe('isAnonymousParticipant (comments)', function () {
    // SCRUM-354: only the therapy's own people can be masked; any other commenter on a public therapy
    // is public, however anonymous the therapy is -- including someone who left the group.
    test('1:1 cases (all unchanged)', function (bool $anonymous, string $who, bool $masked) {
        $t = hicrOneToOne($anonymous);
        $subject = match ($who) {
            'client' => $t['client'],
            'counsellor' => $t['counsellorUser'],
            'outsider' => User::factory()->create(),
        };

        expect($t['therapy']->fresh()->isAnonymousParticipant($subject))->toBe($masked);
    })->with([
        'anonymous, client' => [true, 'client', true],
        'not anonymous, client' => [false, 'client', false],
        'anonymous, counsellor' => [true, 'counsellor', false],
        'anonymous, outside commenter' => [true, 'outsider', false],
    ]);

    test('group cases (all unchanged)', function (bool $anonymous, string $who, bool $masked) {
        $g = hicrGroup($anonymous);
        $subject = match ($who) {
            'creator' => $g['creator'],
            'anonymous member' => hicrMember($g['group'], true),
            'member' => hicrMember($g['group'], false),
            'counsellor' => $g['counsellorUser'],
            'outsider' => User::factory()->create(),
        };

        expect($g['group']->fresh()->isAnonymousParticipant($subject))->toBe($masked);
    })->with([
        'anonymous group, creator' => [true, 'creator', true],
        'anonymous group, member' => [true, 'member', true],
        'anonymous group, counsellor' => [true, 'counsellor', false],
        'anonymous group, outside commenter or someone who left' => [true, 'outsider', false],
        'group, creator with no row' => [false, 'creator', false],
        'group, anonymous member' => [false, 'anonymous member', true],
        'group, member' => [false, 'member', false],
        'group, counsellor' => [false, 'counsellor', false],
        'group, outside commenter or someone who left' => [false, 'outsider', false],
    ]);

    test('a creator with an anonymous membership row of their own is masked', function () {
        $g = hicrGroup(false);
        $g['group']->users()->attach($g['creator']->id, ['anonymous' => true]);

        expect($g['group']->fresh()->isAnonymousParticipant($g['creator']))->toBeTrue();
    });

    test('a comment written while an anonymous member stays masked after they leave; an outsider\'s never is', function () {
        $g = hicrGroup(false);
        $member = hicrMember($g['group'], true);
        $outsider = User::factory()->create();
        $write = fn (User $user) => CreateCommentAction::new()->execute(CreateCommentDTO::new()->fromArray([
            'user' => $user, 'commentable' => $g['group']->fresh(), 'content' => 'Hello',
        ]));

        $memberComment = $write($member);
        $outsiderComment = $write($outsider);
        $g['group']->users()->detach($member->id);
        $viewer = User::factory()->create();

        expect($memberComment->anonymous)->toBeTrue()
            ->and($outsiderComment->anonymous)->toBeFalse()
            ->and(hicrPresent(new CommentResource(Comment::find($memberComment->id)), $viewer)['userId'])->toBeNull()
            ->and(hicrPresent(new CommentResource(Comment::find($outsiderComment->id)), $viewer)['userId'])->toBe($outsider->id);
    });
});

// SCRUM-288 architect review asked whether hidesIdentityOf() could be just
// `anonymous || isMaskedToOthers()`. It can't without a change: a group's counsellor who is also an
// anonymous member of it is not masked by isMaskedToOthers() (counsellors are named), only by the
// membership-row term. This pins that term.
test('a group counsellor who is also an anonymous member stays hidden, though isMaskedToOthers alone would show them', function () {
    $g = hicrGroup(false);
    $g['group']->users()->attach($g['counsellorUser']->id, ['anonymous' => true]);
    $group = $g['group']->fresh();

    expect($group->isMaskedToOthers($g['counsellorUser']))->toBeFalse()
        ->and($group->hidesIdentityOf($g['counsellorUser']))->toBeTrue();
});
