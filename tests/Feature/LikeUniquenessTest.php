<?php

use App\Actions\Like\CreateLikeAction;
use App\Models\Counsellor;
use App\Models\Like;
use App\Models\Post;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;

// SCRUM-478: one like per person per thing, enforced by the database -- the migration deduplicates (earliest kept) and
// adds likes_user_likeable_unique; a like that loses a race to that index gets the same 422 as the up-front check.
// Helpers are file-local and lku-prefixed (--parallel).

beforeEach(function () {
    Notification::fake();
});

function lkuMigration(): object
{
    return require database_path('migrations/2026_12_18_100000_add_unique_index_to_likes_table.php');
}

function lkuPost(): Post
{
    $counsellor = Counsellor::factory()->create(['user_id' => User::factory()->adult()]);
    $post = new Post(['content' => 'Some thoughts on rest.']);
    $post->addedby()->associate($counsellor);
    $post->save();

    return $post;
}

function lkuInsert(User $user, Post $post): int
{
    return DB::table('likes')->insertGetId([
        'user_id' => $user->id,
        'likeable_type' => Post::class,
        'likeable_id' => $post->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

test('the migration keeps the earliest of each duplicate and removes the rest', function () {
    // This runs DDL (drop and re-add the index) inside RefreshDatabase's transaction. Fine on SQLite, the suite's
    // database, where DDL is transactional; on MySQL it would implicitly commit and leak into later tests.
    $migration = lkuMigration();
    $migration->down();

    $post = lkuPost();
    $other = lkuPost();
    $client = User::factory()->adult()->create();
    $someoneElse = User::factory()->adult()->create();

    $earliest = lkuInsert($client, $post);
    lkuInsert($client, $post);
    lkuInsert($client, $post);
    $otherPost = lkuInsert($client, $other);
    $otherUser = lkuInsert($someoneElse, $post);
    lkuInsert($someoneElse, $post);

    $migration->up();

    expect(Like::query()->orderBy('id')->pluck('id')->all())->toBe([$earliest, $otherPost, $otherUser])
        ->and(Schema::hasIndex('likes', 'likes_user_likeable_unique'))->toBeTrue()
        ->and(Schema::hasIndex('likes', ['likeable_type', 'likeable_id']))->toBeTrue();
});

test('the database refuses a second like by the same person on the same thing', function () {
    $post = lkuPost();
    $client = User::factory()->adult()->create();
    lkuInsert($client, $post);

    expect(fn () => lkuInsert($client, $post))->toThrow(UniqueConstraintViolationException::class);

    // Someone else, or the same person on another thing, is fine.
    lkuInsert(User::factory()->adult()->create(), $post);
    lkuInsert($client, lkuPost());

    expect(Like::query()->count())->toBe(3);
});

test('a like that loses the race to the unique index is a 422, not a 500, and nothing changes', function () {
    $post = lkuPost();
    $client = User::factory()->adult()->create();

    // The up-front check has passed; a concurrent request inserts the same like just before ours does.
    $raced = false;
    Like::creating(function () use ($client, $post, &$raced) {
        if (! $raced) {
            $raced = true;
            lkuInsert($client, $post);
        }
    });

    $this->actingAs($client)
        ->postJson(route('api.likes.create'), ['likeableType' => 'Post', 'likeableId' => $post->id])
        ->assertStatus(422)
        ->assertJsonFragment(['message' => CreateLikeAction::ALREADY_LIKED]);

    expect($post->likes()->count())->toBe(1)
        ->and($post->likes()->where('user_id', $client->id)->count())->toBe(1);

    $this->getJson(route('api.likes', ['likeableType' => 'Post', 'likeableId' => $post->id]))
        ->assertSuccessful()
        ->assertJsonFragment(['count' => 1]);
});
