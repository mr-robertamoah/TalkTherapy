<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Comment extends Model
{
    use HasFactory;

    protected $fillable = ['content'];

    public function user()
    {
        // withTrashed: see Therapy::counsellor() for why callers need this to resolve rather
        // than crash -- a comment's author may have since deleted their account.
        return $this->belongsTo(User::class)->withTrashed();
    }

    public function commentable()
    {
        return $this->morphTo('commentable');
    }

    public function scopeWhereCommentable($query, $commentable)
    {
        return $query
            ->where('commentable_id', $commentable->id)
            ->where('commentable_type', $commentable::class);
    }
}
