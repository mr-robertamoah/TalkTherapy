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
        return $this->belongsTo(User::class);
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
