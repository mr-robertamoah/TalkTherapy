<?php

use App\Actions\Comment\EnsureCommentableExistsAction;
use App\Actions\Like\EnsureLikeableExistsAction;
use App\Enums\ConstantsEnum;
use App\Http\Resources\TherapyResource;
use App\Models\Comment;
use App\Models\Counsellor;
use App\Models\GroupTherapy;
use App\Models\Post;
use App\Models\Therapy;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

// SCRUM-351/354: only public therapies and groups can be liked or commented on (and their comments
// read); anonymous participants' comments are masked; therapy likes are a count, never a list.

function aTherapyFor354(bool $public = true, bool $anonymous = false): array
{
    $client = User::factory()->create();
    $therapy = Therapy::factory()->create([
        'addedby_type' => User::class, 'addedby_id' => $client->id,
        'counsellor_id' => Counsellor::factory()->create(['user_id' => User::factory()->create()->id])->id,
        'public' => $public, 'anonymous' => $anonymous,
    ]);

    return [$therapy->fresh(), $client];
}

test('anyone signed in can like and comment on a public therapy, and guests can read the comments', function () {
    [$therapy] = aTherapyFor354();
    $fan = User::factory()->create();

    $this->actingAs($fan)->postJson(route('api.likes.create'), ['likeableType' => 'Therapy', 'likeableId' => $therapy->id])->assertSuccessful();
    $this->actingAs($fan)->postJson(route('api.comments.create'), ['commentableType' => 'Therapy', 'commentableId' => $therapy->id, 'content' => 'Wishing you well'])->assertSuccessful();

    $this->app['auth']->forgetGuards(); // a guest from here on
    $this->getJson(route('api.comments', ['commentableType' => 'Therapy', 'commentableId' => $therapy->id]))
        ->assertOk()->assertJsonPath('data.0.content', 'Wishing you well');
});

test('a private therapy can\'t be liked or commented on, and its comments can\'t be read -- like a missing one', function () {
    [$therapy] = aTherapyFor354(public: false);
    Comment::query()->forceCreate(['user_id' => User::factory()->create()->id, 'commentable_type' => Therapy::class, 'commentable_id' => $therapy->id, 'content' => 'Old private comment']);
    $user = User::factory()->create();

    foreach ([$therapy->id, 999999] as $id) {
        $this->actingAs($user)->postJson(route('api.likes.create'), ['likeableType' => 'Therapy', 'likeableId' => $id])
            ->assertStatus(422)->assertJsonPath('message', EnsureLikeableExistsAction::NOT_AVAILABLE);
        $this->actingAs($user)->postJson(route('api.comments.create'), ['commentableType' => 'Therapy', 'commentableId' => $id, 'content' => 'x'])
            ->assertStatus(422)->assertJsonPath('message', EnsureCommentableExistsAction::NOT_AVAILABLE);
        $read = $this->getJson(route('api.comments', ['commentableType' => 'Therapy', 'commentableId' => $id]))->assertStatus(422);
        expect($read->getContent())->not->toContain('Old private comment');
    }
});

test('public groups can be liked and commented on too', function () {
    $group = GroupTherapy::factory()->create(['public' => true]);
    $user = User::factory()->create();

    $this->actingAs($user)->postJson(route('api.likes.create'), ['likeableType' => 'GroupTherapy', 'likeableId' => $group->id])->assertSuccessful();
    $this->actingAs($user)->postJson(route('api.comments.create'), ['commentableType' => 'GroupTherapy', 'commentableId' => $group->id, 'content' => 'Great group'])->assertSuccessful();

    $private = GroupTherapy::factory()->create(['public' => false]);
    $this->actingAs($user)->postJson(route('api.likes.create'), ['likeableType' => 'GroupTherapy', 'likeableId' => $private->id])->assertStatus(422);
});

test('an anonymous client\'s comment on their own therapy never names them -- except to themselves', function () {
    [$therapy, $client] = aTherapyFor354(anonymous: true);
    $outsider = User::factory()->create();

    $this->actingAs($client)->postJson(route('api.comments.create'), ['commentableType' => 'Therapy', 'commentableId' => $therapy->id, 'content' => 'Thank you all'])->assertSuccessful();
    $this->actingAs($outsider)->postJson(route('api.comments.create'), ['commentableType' => 'Therapy', 'commentableId' => $therapy->id, 'content' => 'Hang in there'])->assertSuccessful();

    $asOutsider = collect($this->actingAs($outsider)->getJson(route('api.comments', ['commentableType' => 'Therapy', 'commentableId' => $therapy->id]))->json('data'))->keyBy('content');
    expect($asOutsider['Thank you all']['username'])->toBe(ConstantsEnum::anonymousUserLabel->value)
        ->and($asOutsider['Thank you all']['userId'])->toBeNull()
        // An outsider's own comment is never masked, even on an anonymous therapy.
        ->and($asOutsider['Hang in there']['username'])->toBe($outsider->username);

    $asClient = collect($this->actingAs($client)->getJson(route('api.comments', ['commentableType' => 'Therapy', 'commentableId' => $therapy->id]))->json('data'))->keyBy('content');
    expect($asClient['Thank you all']['userId'])->toBe($client->id);
});

test('therapy likes are a count and your own like, never a list of who liked it', function () {
    [$therapy] = aTherapyFor354();
    $fan = User::factory()->create();
    $this->actingAs($fan)->postJson(route('api.likes.create'), ['likeableType' => 'Therapy', 'likeableId' => $therapy->id])->assertSuccessful();

    $likes = $this->actingAs($fan)->getJson(route('api.likes', ['likeableType' => 'Therapy', 'likeableId' => $therapy->id]))->assertOk();
    expect($likes->json())->toBe(['count' => 1, 'liked' => true])
        ->and($likes->getContent())->not->toContain('user_id');

    $engagement = (new TherapyResource($therapy->fresh()))->resolve(Request::create('/')->setUserResolver(fn () => $fan))['engagement'];
    expect($engagement)->toBe(['likesCount' => 1, 'isLiked' => true, 'commentsCount' => 0]);

    [$private] = aTherapyFor354(public: false);
    expect((new TherapyResource($private))->resolve(Request::create('/')->setUserResolver(fn () => $fan)))->not->toHaveKey('engagement');
});

function commentsAsFor354($test, User $viewer, string $type, int $id): Collection
{
    return collect($test->actingAs($viewer)->getJson(route('api.comments', ['commentableType' => $type, 'commentableId' => $id]))->json('data'))->keyBy('content');
}

test('in a group, anonymous members and an anonymous group\'s creator are masked; counsellors and outsiders never', function () {
    $creator = User::factory()->create();
    $anonMember = User::factory()->create();
    $namedMember = User::factory()->create();
    $counsellor = Counsellor::factory()->create(['user_id' => User::factory()->create()->id]);
    $group = GroupTherapy::factory()->create(['addedby_type' => User::class, 'addedby_id' => $creator->id, 'public' => true, 'anonymous' => false]);
    $group->users()->attach([$anonMember->id => ['anonymous' => true], $namedMember->id => ['anonymous' => false]]);
    $group->counsellors()->attach($counsellor->id, ['state' => 'ACTIVE', 'role' => 'NORMAL']);
    $outsider = User::factory()->create();

    foreach ([[$anonMember, 'anon member'], [$namedMember, 'named member'], [$counsellor->user, 'counsellor']] as [$author, $text]) {
        $this->actingAs($author)->postJson(route('api.comments.create'), ['commentableType' => 'GroupTherapy', 'commentableId' => $group->id, 'content' => $text])->assertSuccessful();
    }

    $seen = commentsAsFor354($this, $outsider, 'GroupTherapy', $group->id);
    expect($seen['anon member']['username'])->toBe(ConstantsEnum::anonymousUserLabel->value)
        ->and($seen['anon member']['userId'])->toBeNull()
        ->and($seen['named member']['username'])->toBe($namedMember->username)
        ->and($seen['counsellor']['username'])->toBe($counsellor->user->username);

    // Leaving the group doesn't unmask what they wrote while anonymous.
    $group->users()->detach($anonMember->id);
    expect(commentsAsFor354($this, $outsider, 'GroupTherapy', $group->fresh()->id)['anon member']['userId'])->toBeNull();

    // The creator of an anonymous group is masked too.
    $anonGroup = GroupTherapy::factory()->create(['addedby_type' => User::class, 'addedby_id' => $creator->id, 'public' => true, 'anonymous' => true]);
    $this->actingAs($creator)->postJson(route('api.comments.create'), ['commentableType' => 'GroupTherapy', 'commentableId' => $anonGroup->id, 'content' => 'creator'])->assertSuccessful();
    expect(commentsAsFor354($this, $outsider, 'GroupTherapy', $anonGroup->id)['creator']['userId'])->toBeNull();
});

test('a comment stays masked after the therapy stops being anonymous', function () {
    [$therapy, $client] = aTherapyFor354(anonymous: true);
    $this->actingAs($client)->postJson(route('api.comments.create'), ['commentableType' => 'Therapy', 'commentableId' => $therapy->id, 'content' => 'while anonymous'])->assertSuccessful();
    $therapy->update(['anonymous' => false]);

    expect(commentsAsFor354($this, User::factory()->create(), 'Therapy', $therapy->id)['while anonymous']['userId'])->toBeNull();
});

test('you can still remove your like after a therapy turns private', function () {
    [$therapy] = aTherapyFor354();
    $fan = User::factory()->create();
    $this->actingAs($fan)->postJson(route('api.likes.create'), ['likeableType' => 'Therapy', 'likeableId' => $therapy->id])->assertSuccessful();
    $therapy->update(['public' => false]);

    $this->actingAs($fan)->postJson(route('api.likes.delete'), ['likeableType' => 'Therapy', 'likeableId' => $therapy->id])->assertSuccessful();
    expect($therapy->likes()->count())->toBe(0);
});

test('post likes keep their list shape; a guest\'s therapy like state is false', function () {
    $post = Post::query()->forceCreate(['content' => 'Hi', 'addedby_type' => User::class, 'addedby_id' => User::factory()->create()->id]);
    $fan = User::factory()->create();
    $this->actingAs($fan)->postJson(route('api.likes.create'), ['likeableType' => 'Post', 'likeableId' => $post->id])->assertSuccessful();
    expect($this->getJson(route('api.likes', ['likeableType' => 'Post', 'likeableId' => $post->id]))->json())->toBe([['user_id' => $fan->id]]);

    [$therapy] = aTherapyFor354();
    $this->app['auth']->forgetGuards();
    expect($this->getJson(route('api.likes', ['likeableType' => 'Therapy', 'likeableId' => $therapy->id]))->json())->toBe(['count' => 0, 'liked' => false]);
});
