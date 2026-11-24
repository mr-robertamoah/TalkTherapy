<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

// TT-7.11c/SCRUM-472: the permanent record that a user's likes or comments on a post earned its counsellor a star
// (see the post_interaction_awards migration). Written only by SyncPostInteractionStarAction.
class PostInteractionAward extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['awarded_at' => 'datetime'];
    }
}
