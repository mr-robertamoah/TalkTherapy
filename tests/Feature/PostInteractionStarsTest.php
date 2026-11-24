<?php

use App\Actions\Post\SyncPostInteractionStarAction;
use App\Enums\AdministratorTypeEnum;
use App\Enums\SettingsEnum;
use App\Enums\StarSourceEnum;
use App\Enums\SuspensionScopeEnum;
use App\Models\Administrator;
use App\Models\Counsellor;
use App\Models\GroupTherapy;
use App\Models\Like;
use App\Models\PlatformSetting;
use App\Models\Post;
use App\Models\Report;
use App\Models\Star;
use App\Models\Suspension;
use App\Models\User;
use App\Services\SettingsService;
use Database\Seeders\PostInteractionStarSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia;

// TT-7.11c/SCRUM-472: a counsellor's post earns them one star per qualifying user who likes or comments on it
// (SyncPostInteractionStarAction), under a per-post and a per-day cap, and who gave it is never sent anywhere.
// Helpers are file-local and pis-prefixed (--parallel).

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-12-10 12:00:00', 'UTC'));
    Notification::fake();
});

afterEach(function () {
    Carbon::setTestNow();
});

function pisCounsellor(): Counsellor
{
    return Counsellor::factory()->create(['user_id' => User::factory()->adult()->state(['created_at' => now()->subYear()])]);
}

// A client who qualifies: verified email, an account 30 days old, no counsellor profile.
function pisClient(array $state = []): User
{
    return User::factory()->adult()->create(array_merge(['created_at' => now()->subDays(30)], $state));
}

function pisPost(Counsellor|User $author): Post
{
    $post = new Post(['content' => 'Some thoughts on sleep.']);
    $post->addedby()->associate($author);
    $post->save();

    return $post;
}

function pisLike($test, User $user, Post $post): void
{
    $test->actingAs($user)->postJson(route('api.likes.create'), ['likeableType' => 'Post', 'likeableId' => $post->id])->assertSuccessful();
}

function pisUnlike($test, User $user, Post $post): void
{
    $test->actingAs($user)->postJson(route('api.likes.delete'), ['likeableType' => 'Post', 'likeableId' => $post->id])->assertSuccessful();
}

function pisComment($test, User $user, Post $post): int
{
    return $test->actingAs($user)
        ->postJson(route('api.comments.create'), ['commentableType' => 'Post', 'commentableId' => $post->id, 'content' => 'Thank you'])
        ->assertSuccessful()
        ->json('comment.id');
}

function pisUncomment($test, User $user, int $commentId): void
{
    $test->actingAs($user)->deleteJson(route('api.comments.delete', ['commentId' => $commentId]))->assertSuccessful();
}

function pisStars(?Post $post = null)
{
    return Star::query()
        ->where('source', StarSourceEnum::postInteraction->value)
        ->when($post, fn ($q) => $q->where('starreable_type', Post::class)->where('starreable_id', $post->id));
}

function pisSuspend(User $user, SuspensionScopeEnum $scope): void
{
    $report = new Report(['description' => 'Something happened']);
    $report->addedby()->associate(User::factory()->adult()->create());
    $report->reportable()->associate(GroupTherapy::factory()->create());
    $report->save();
    Suspension::query()->forceCreate(['user_id' => $user->id, 'report_id' => $report->id, 'scope' => $scope->value, 'public_reason' => 'Conduct', 'starts_at' => now()->subMinute()]);
}

function pisSetting(SettingsEnum $key, int $value): void
{
    PlatformSetting::query()->forceCreate(['key' => $key->value, 'value' => (string) $value]);
}

function pisSuperAdmin(): User
{
    return User::factory()->adult()
        ->has(Administrator::factory()->state(['type' => AdministratorTypeEnum::super->value]))
        ->create();
}

// Every key, at any depth, of a decoded JSON payload.
function pisKeys(mixed $value): array
{
    if (! is_array($value)) {
        return [];
    }

    $keys = [];
    foreach ($value as $key => $child) {
        if (is_string($key)) {
            $keys[] = $key;
        }
        $keys = array_merge($keys, pisKeys($child));
    }

    return array_values(array_unique($keys));
}

function pisStarredbyKeys(mixed $payload): array
{
    return array_values(array_filter(pisKeys($payload), fn (string $key) => (bool) preg_match('/starred_?by/i', $key)));
}

function pisInertiaProps($test, string $url, ?User $viewer = null): array
{
    $test = $viewer ? $test->actingAs($viewer) : $test;
    $props = null;

    $test->get($url)->assertOk()->assertInertia(function (AssertableInertia $page) use (&$props) {
        $props = $page->toArray()['props'];
    });

    return json_decode(json_encode($props), true);
}

// ---- Earning ----

test('a qualifying client\'s like gives the post\'s counsellor one star, from that client', function () {
    $counsellor = pisCounsellor();
    $post = pisPost($counsellor);
    $client = pisClient();

    pisLike($this, $client, $post);

    $star = pisStars($post)->sole();

    expect($star->award_key)->toBe("post_interaction:{$post->id}:{$client->id}")
        ->and($star->starred_type)->toBe(Counsellor::class)
        ->and($star->starred_id)->toBe($counsellor->id)
        ->and($star->starredby_type)->toBe(User::class)
        ->and($star->starredby_id)->toBe($client->id)
        ->and($star->type)->toBe(StarSourceEnum::postInteraction->starType()->value);
});

test('a like and a comment from the same client are one star', function () {
    $post = pisPost(pisCounsellor());
    $client = pisClient();

    pisLike($this, $client, $post);
    pisComment($this, $client, $post);
    pisComment($this, $client, $post);

    expect(pisStars($post)->count())->toBe(1);
});

test('duplicate likes count once, and the star stays until the last one goes', function () {
    $post = pisPost(pisCounsellor());
    $client = pisClient();

    pisLike($this, $client, $post);
    pisLike($this, $client, $post);

    expect(Like::query()->where('user_id', $client->id)->count())->toBe(2)
        ->and(pisStars($post)->count())->toBe(1);

    pisUnlike($this, $client, $post);
    expect(pisStars($post)->count())->toBe(1);

    pisUnlike($this, $client, $post);
    expect(pisStars($post)->count())->toBe(0);
});

test('unliking while a comment remains keeps the star; removing both takes it back; interacting again earns it again', function () {
    $post = pisPost(pisCounsellor());
    $client = pisClient();

    pisLike($this, $client, $post);
    $commentId = pisComment($this, $client, $post);
    pisUnlike($this, $client, $post);

    expect(pisStars($post)->count())->toBe(1);

    pisUncomment($this, $client, $commentId);
    expect(pisStars($post)->count())->toBe(0);

    pisComment($this, $client, $post);
    expect(pisStars($post)->count())->toBe(1);
});

test('a comment alone earns the star, and deleting it takes the star back', function () {
    $post = pisPost(pisCounsellor());
    $client = pisClient();

    $commentId = pisComment($this, $client, $post);
    expect(pisStars($post)->count())->toBe(1);

    pisUncomment($this, $client, $commentId);
    expect(pisStars($post)->count())->toBe(0);
});

test('an anonymous comment counts, and stays anonymous', function () {
    $post = pisPost(pisCounsellor());
    $client = pisClient();

    $comment = $client->comments()->create(['content' => 'From someone']);
    $comment->commentable()->associate($post);
    $comment->anonymous = true;
    $comment->save();

    SyncPostInteractionStarAction::new()->execute($post, $client);

    expect(pisStars($post)->count())->toBe(1)
        ->and($comment->fresh()->anonymous)->toBeTrue();
});

test('different clients each give the counsellor a star', function () {
    $post = pisPost(pisCounsellor());

    pisLike($this, pisClient(), $post);
    pisLike($this, pisClient(), $post);
    pisComment($this, pisClient(), $post);

    expect(pisStars($post)->count())->toBe(3);
});

// ---- Who qualifies ----

test('a user who doesn\'t qualify gives nothing', function (Closure $makeUser) {
    $counsellor = pisCounsellor();
    $post = pisPost($counsellor);
    $user = $makeUser($counsellor);

    // Written directly: an account-suspended user can't reach the like route at all.
    $like = $user->likes()->create();
    $like->likeable()->associate($post)->save();

    expect(SyncPostInteractionStarAction::new()->execute($post, $user))->toBeNull()
        ->and(pisStars()->count())->toBe(0);
})->with([
    'the author' => [fn (Counsellor $counsellor) => $counsellor->user],
    'another counsellor' => [fn () => pisCounsellor()->user],
    'someone whose counsellor profile was deleted' => [function () {
        $other = pisCounsellor();
        $other->delete();

        return $other->user;
    }],
    'an unverified email' => [fn () => pisClient(['email_verified_at' => null])],
    'an account 6 days old' => [fn () => pisClient(['created_at' => now()->subDays(6)])],
    'an account-suspended user' => [function () {
        pisSuspend($user = pisClient(), SuspensionScopeEnum::account);

        return $user;
    }],
    'a counsellor-scope-suspended user' => [function () {
        pisSuspend($user = pisClient(), SuspensionScopeEnum::counsellor);

        return $user;
    }],
]);

test('an account exactly the minimum age qualifies, and the minimum comes from config', function () {
    $post = pisPost(pisCounsellor());

    pisLike($this, pisClient(['created_at' => now()->subDays(7)]), $post);
    expect(pisStars($post)->count())->toBe(1);

    config(['recognition.interaction_min_account_age_days' => 60]);
    pisLike($this, pisClient(), $post);
    expect(pisStars($post)->count())->toBe(1);
});

test('a deleted account gives nothing, and its star is taken back on the next sync', function () {
    $post = pisPost(pisCounsellor());
    $client = pisClient();

    pisLike($this, $client, $post);
    expect(pisStars($post)->count())->toBe(1);

    $client->delete();
    SyncPostInteractionStarAction::new()->execute($post, $client);

    expect(pisStars($post)->count())->toBe(0);
});

test('a client who stops qualifying loses the star at their next like or comment', function () {
    $post = pisPost(pisCounsellor());
    $client = pisClient();

    pisLike($this, $client, $post);
    pisSuspend($client, SuspensionScopeEnum::counsellor);
    $this->actingAs($client)->postJson(route('api.comments.create'), ['commentableType' => 'Post', 'commentableId' => $post->id, 'content' => 'Hi']);
    SyncPostInteractionStarAction::new()->execute($post, $client);

    expect(pisStars($post)->count())->toBe(0);
});

test('a post made as a user gives nothing, nor does liking a counsellor\'s profile', function () {
    $counsellor = pisCounsellor();
    $userPost = pisPost($counsellor->user);
    $client = pisClient();

    pisLike($this, $client, $userPost);
    pisComment($this, $client, $userPost);
    $this->actingAs($client)->postJson(route('api.likes.create'), ['likeableType' => 'Counsellor', 'likeableId' => $counsellor->id])->assertSuccessful();

    expect(Star::query()->count())->toBe(0)
        ->and(SyncPostInteractionStarAction::new()->execute($userPost, $client))->toBeNull();
});

test('a suspended counsellor earns nothing from their post', function () {
    $counsellor = pisCounsellor();
    $post = pisPost($counsellor);
    pisSuspend($counsellor->user, SuspensionScopeEnum::counsellor);

    pisLike($this, pisClient(), $post);

    expect(pisStars()->count())->toBe(0);
});

test('deleting the post deletes its interaction stars', function () {
    $counsellor = pisCounsellor();
    $post = pisPost($counsellor);
    pisLike($this, pisClient(), $post);

    $this->actingAs($counsellor->user)->deleteJson(route('api.posts.delete', ['postId' => $post->id]))->assertSuccessful();

    expect(pisStars()->count())->toBe(0);
});

// ---- Caps ----

test('at most interaction_per_post stars per post; a freed place is not refilled', function () {
    pisSetting(SettingsEnum::recognitionCapInteractionPerPost, 2);
    $counsellor = pisCounsellor();
    $post = pisPost($counsellor);
    [$first, $second, $third] = [pisClient(), pisClient(), pisClient()];

    pisLike($this, $first, $post);
    pisLike($this, $second, $post);
    pisLike($this, $third, $post);

    expect(pisStars($post)->pluck('starredby_id')->sort()->values()->all())->toBe([$first->id, $second->id]);

    // The first unlikes: their place is free, but the third client's earlier like isn't looked at again.
    pisUnlike($this, $first, $post);
    expect(pisStars($post)->pluck('starredby_id')->all())->toBe([$second->id]);

    // A new interaction takes the free place -- the third client's own next interaction, or anyone's.
    pisComment($this, $third, $post);
    expect(pisStars($post)->pluck('starredby_id')->sort()->values()->all())->toBe([$second->id, $third->id]);

    // The per-post cap isn't per day.
    Carbon::setTestNow(now()->addDay());
    pisLike($this, pisClient(), $post);
    expect(pisStars($post)->count())->toBe(2);

    // Another post has its own places.
    pisLike($this, pisClient(), pisPost($counsellor));
    expect(pisStars()->count())->toBe(3);
});

test('the per-post default is 10', function () {
    $post = pisPost(pisCounsellor());

    foreach (range(1, 11) as $i) {
        pisLike($this, pisClient(), $post);
    }

    expect(pisStars($post)->count())->toBe(10);
});

test('at most interaction_per_day stars per counsellor per UTC day, the default 20', function () {
    Carbon::setTestNow('2026-12-10 23:00:00');
    $counsellor = pisCounsellor();
    $posts = collect(range(1, 3))->map(fn () => pisPost($counsellor));

    foreach (range(1, 21) as $i) {
        pisLike($this, pisClient(), $posts[$i % 3]);
    }
    expect(pisStars()->count())->toBe(20);

    // A new UTC day, a new allowance.
    Carbon::setTestNow('2026-12-11 00:00:01');
    pisLike($this, pisClient(), $posts[0]);
    expect(pisStars()->count())->toBe(21);
});

test('a super admin\'s setting overrides the config default for both caps', function () {
    config(['recognition.cap.interaction_per_day' => 1, 'recognition.cap.interaction_per_post' => 1]);
    pisSetting(SettingsEnum::recognitionCapInteractionPerDay, 3);
    pisSetting(SettingsEnum::recognitionCapInteractionPerPost, 2);
    $counsellor = pisCounsellor();
    [$one, $two] = [pisPost($counsellor), pisPost($counsellor)];

    foreach (range(1, 3) as $i) {
        pisLike($this, pisClient(), $one);
    }
    foreach (range(1, 2) as $i) {
        pisLike($this, pisClient(), $two);
    }

    expect(pisStars($one)->count())->toBe(2)
        ->and(pisStars($two)->count())->toBe(1)
        ->and(SettingsService::new()->getRecognitionCaps())->toMatchArray(['interaction_per_day' => 3, 'interaction_per_post' => 2]);
});

test('a cap of 0 stops interaction stars', function () {
    pisSetting(SettingsEnum::recognitionCapInteractionPerDay, 0);
    pisLike($this, pisClient(), pisPost(pisCounsellor()));

    expect(pisStars()->count())->toBe(0);
});

// ---- Never in the way ----

test('a failing star sync never blocks the like, the unlike, the comment or its delete', function () {
    Exceptions::fake();
    Event::listen('eloquent.creating: '.Star::class, fn () => throw new RuntimeException('star store down'));
    $post = pisPost(pisCounsellor());
    $client = pisClient();

    pisLike($this, $client, $post);
    $commentId = pisComment($this, $client, $post);

    expect($post->likes()->count())->toBe(1)
        ->and($post->comments()->count())->toBe(1)
        ->and(pisStars()->count())->toBe(0);
    Exceptions::assertReported(fn (RuntimeException $e) => $e->getMessage() === 'star store down');

    Event::listen('eloquent.deleting: '.Star::class, fn () => throw new RuntimeException('never reached'));
    pisUnlike($this, $client, $post);
    pisUncomment($this, $client, $commentId);

    expect($post->likes()->count())->toBe(0)->and($post->comments()->count())->toBe(0);
});

// ---- The admin caps card ----

test('the caps card shows the interaction caps and a super admin saves them', function () {
    $super = pisSuperAdmin();

    $this->actingAs($super)->get(route('administrator.recognition'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('caps.interaction_per_post', 10)
            ->where('caps.interaction_per_day', 20)
            ->where('capsMax.interaction_per_post', 100)
            ->where('capsMax.interaction_per_day', 100));

    $caps = array_merge(SettingsService::new()->getRecognitionCaps(), ['interaction_per_post' => 4, 'interaction_per_day' => 7]);
    $this->actingAs($super)->postJson(route('admin.settings.recognition-caps.update'), ['caps' => $caps])
        ->assertOk()
        ->assertJsonPath('caps.interaction_per_post', 4)
        ->assertJsonPath('caps.interaction_per_day', 7);

    expect(PlatformSetting::query()->where('key', SettingsEnum::recognitionCapInteractionPerPost->value)->value('value'))->toBe('4')
        ->and(PlatformSetting::query()->where('key', SettingsEnum::recognitionCapInteractionPerDay->value)->value('value'))->toBe('7');

    $this->actingAs($super)->postJson(route('admin.settings.recognition-caps.update'), ['caps' => array_merge($caps, ['interaction_per_post' => 101])])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('caps.interaction_per_post');
});

// ---- Privacy ----

test('who liked or commented is never sent: no starredby in the post, counsellor or Home payloads', function () {
    $counsellor = pisCounsellor();
    $post = pisPost($counsellor);
    $client = pisClient();
    pisLike($this, $client, $post);
    pisComment($this, $client, $post);
    expect(pisStars()->count())->toBe(1);

    $posts = $this->actingAs($client)->getJson(route('api.posts', ['addedbyType' => 'Counsellor', 'addedbyId' => $counsellor->id]))->assertOk()->json('data');
    expect(array_keys($posts[0]))->toBe(['id', 'fromAdmin', 'counsellor', 'content', 'likesCount', 'isLiked', 'comments', 'files', 'createdAt'])
        ->and(pisStarredbyKeys($posts))->toBe([]);

    foreach ([null, $client, $counsellor->user] as $viewer) {
        $profile = pisInertiaProps($this, route('counsellor.show', ['counsellorId' => $counsellor->id]), $viewer);
        $home = pisInertiaProps($this, route('home'), $viewer);

        expect(pisStarredbyKeys($profile))->toBe([])
            ->and(pisStarredbyKeys($home))->toBe([])
            ->and(json_encode([$profile, $home]))->not->toContain('starredby');
    }

    // Snapshots: a new key here must be checked for who it reveals before the list is updated.
    $profile = pisInertiaProps($this, route('counsellor.show', ['counsellorId' => $counsellor->id]), $client);
    $counsellorData = $profile['counsellor']['data'] ?? $profile['counsellor'];
    expect(array_keys($counsellorData))->toBe([
        'id', 'userId', 'name', 'about', 'phone', 'email', 'avatar', 'cover', 'contactVisible', 'emailVerified', 'verification',
        'cases', 'religions', 'languages', 'pricings', 'createdAt', 'freeTherapiesCount', 'paidTherapiesCount',
        'groupTherapiesCount', 'onlineSessionsHeldCount', 'inPersonSessionsCount',
    ]);

    // Home with a shared post open (posts.get puts it in the session): its post prop is the PostResource.
    $this->actingAs($client)->get(route('posts.get', ['postId' => $post->id]))->assertRedirect(route('home'));
    $home = pisInertiaProps($this, route('home'), $client);
    $homePost = $home['post']['data'] ?? $home['post'];
    expect(array_keys($home))->toBe([
        'errors', 'auth', 'supportedCurrencies', 'videoProvider', 'features', 'passwordPolicy', 'failureAlert',
        'verificationStatus', 'twoFactorRequirement', 'adminNavigation', 'counsellorCreationStep', 'recentTherapies',
        'recentGroupTherapies', 'recognisedCounsellors', 'leadingCounsellors', 'post', 'alert', 'paymentRequired',
        'paymentRequiredTherapyId', 'paymentRequiredGroupTherapyId', 'paymentRequiredMessage',
    ])
        ->and(array_keys($homePost))->toBe(['id', 'fromAdmin', 'counsellor', 'content', 'likesCount', 'isLiked', 'comments', 'files', 'createdAt'])
        ->and(pisStarredbyKeys($home))->toBe([]);
});

// ---- Seed data ----

test('the seeder gives lisa_anderson two stars from her demo post, once', function () {
    $counsellor = Counsellor::factory()->create(['user_id' => User::factory()->adult()->create(['username' => 'lisa_anderson'])]);

    $this->seed(PostInteractionStarSeeder::class);
    $this->seed(PostInteractionStarSeeder::class);

    $post = Post::query()->where('content', 'like', PostInteractionStarSeeder::MARKER.'%')->sole();
    $fan = fn (string $username) => User::query()->where('username', $username)->sole()->id;

    expect($post->addedby->is($counsellor))->toBeTrue()
        ->and(pisStars($post)->pluck('starredby_id')->sort()->values()->all())->toBe([$fan('post_fan_ama'), $fan('post_fan_kofi')])
        ->and($post->likes()->count())->toBe(3)
        ->and($post->comments()->count())->toBe(1)
        ->and(SyncPostInteractionStarAction::qualifies($counsellor, User::query()->where('username', 'post_fan_esi')->sole()))->toBeTrue();
});
