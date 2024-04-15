<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Message extends Model
{
    use HasFactory,
    SoftDeletes;

    protected $fillable = ['content', 'type', 'confidential', 'status', 'message_id', 'therapy_topic_id', 'deleted_for'];

    public function from()
    {
        return $this->morphTo();
    }

    public function to()
    {
        return $this->morphTo();
    }

    public function for()
    {
        return $this->morphTo();
    }

    public function therapyTopic()
    {
        return $this->belongsTo(TherapyTopic::class);
    }

    public function replying()
    {
        return $this->belongsTo(Message::class, 'message_id');
    }

    public function replies()
    {
        return $this->hasMany(Message::class, 'message_id');
    }

    public function files(): MorphToMany
    {
        return $this
            ->morphToMany(File::class, 'fileable', 'fileables')
            ->withTimestamps();
    }

    public function isParty(?User $user)
    {
        if (!$user) return false;
        
        return $this->query()
            ->whereTo($user)
            ->orWhere(function ($query) use ($user) {
                $query->whereFrom($user);
            })
            ->exists();
    }

    public function isNotParty(?User $user)
    {
        if (!$user) return false;

        return !$this->isParty($user);
    }

    public function scopeWhereTo($query, User $user)
    {
        $counsellor = $user->counsellor;
        return $query->where(function ($query) use ($user, $counsellor) {
            $query->where(function ($query) use ($user) {
                $query
                    ->where('to_type', $user::class)
                    ->where('to_id', $user->id);
            });

            $query->when($counsellor, function ($query) use ($counsellor) {
                $query->orWhere(function ($query) use ($counsellor) {
                    $query
                        ->where('to_type', $counsellor::class)
                        ->where('to_id', $counsellor->id);
                });
            });
        });
    }

    public function scopeWhereFrom($query, User $user)
    {
        $counsellor = $user->counsellor;
        return $query->where(function ($query) use ($user, $counsellor) {
            $query->where(function ($query) use ($user) {
                $query
                    ->where('from_type', $user::class)
                    ->where('from_id', $user->id);
            });

            $query->when($counsellor, function ($query) use ($counsellor) {
                $query->orWhere(function ($query) use ($counsellor) {
                    $query
                        ->where('from_type', $counsellor::class)
                        ->where('from_id', $counsellor->id);
                });
            });
        });
    }

    public function scopeWhereLike($query, String $like)
    {
        return $query->where('content', 'LIKE', "%{$like}%");
    }

    public function scopeWhereTherapyTopicId($query, String|int|null $topicId)
    {
        if (!$topicId) return $query;
        
        return $query->where('therapy_topic_id', $topicId);
    }

    public function scopeWhereReplyId($query, String|int|null $replyId)
    {
        if (!$replyId) return $query;
        
        return $query->where('message_id', $replyId);
    }
}
