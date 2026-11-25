<?php

namespace Database\Seeders;

use App\DTOs\CreateCommentDTO;
use App\DTOs\CreateLikeDTO;
use App\Models\Post;
use App\Models\User;
use App\Services\CommentService;
use App\Services\LikeService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

// TT-7.11c/SCRUM-472: a post lisa_anderson published as a counsellor, with likes and a comment from demo clients made
// through the real LikeService/CommentService, so SyncPostInteractionStarAction gives her stars: post_fan_ama (like and
// comment: one star) and post_fan_kofi (like: one star). post_fan_new's account is 2 days old, so their like gives
// nothing. post_fan_esi qualifies and hasn't touched the post -- for trying it out. Each part is skipped once it
// exists. See documentation/seeded-data.md, "Stars from post interactions".
class PostInteractionStarSeeder extends Seeder
{
    use RefusesOutsideLocal;

    public const COUNSELLOR = 'lisa_anderson';

    public const MARKER = '[Recognition demo: post]';

    // Username => [first name, account age in days, interactions].
    public const FANS = [
        'post_fan_ama' => ['Ama', 30, ['like', 'comment']],
        'post_fan_kofi' => ['Kofi', 30, ['like']],
        'post_fan_new' => ['Nana', 2, ['like']],
        'post_fan_esi' => ['Esi', 30, []],
    ];

    public function run(): void
    {
        if ($this->refusedHere()) {
            return;
        }

        $counsellor = User::query()->where('username', self::COUNSELLOR)->first()?->counsellor;

        if (! $counsellor) {
            return;
        }

        $post = Post::query()
            ->where('addedby_type', $counsellor->getMorphClass())
            ->where('addedby_id', $counsellor->id)
            ->where('content', 'like', self::MARKER.'%')
            ->first();

        if (! $post) {
            $post = new Post(['content' => self::MARKER." Small routines help on hard days\n\nA short walk, a glass of water, one message to a friend. Small steps still count."]);
            $post->addedby()->associate($counsellor);
            $post->save();
        }

        foreach (self::FANS as $username => [$firstName, $ageInDays, $interactions]) {
            if (User::query()->where('username', $username)->exists()) {
                continue;
            }

            $fan = User::factory()->adult()->create([
                'firstName' => $firstName, 'lastName' => 'Fan', 'username' => $username,
                'email' => str_replace('_', '.', $username).'@example.com', 'password' => Hash::make('password'),
                'email_verified_at' => now(), 'country' => 'Ghana', 'created_at' => now()->subDays($ageInDays),
            ]);

            if (in_array('like', $interactions, true)) {
                LikeService::new()->like(CreateLikeDTO::new()->fromArray(['user' => $fan, 'likeable' => $post]));
            }

            if (in_array('comment', $interactions, true)) {
                CommentService::new()->createComment(CreateCommentDTO::new()->fromArray([
                    'user' => $fan, 'commentable' => $post, 'content' => 'This helped me today, thank you.',
                ]));
            }
        }
    }
}
