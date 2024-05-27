<?php

namespace App\Models;

use App\Enums\DiscussionStatusEnum;
use App\Traits\Starreable;
use App\Traits\Timeable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Discussion extends Model
{
    use HasFactory,
    Timeable,
    SoftDeletes,
    Starreable;

    protected $fillable = ['name', 'description', 'start_time', 'end_time', 'status', 'session_id'];

    public function addedby()
    {
        return $this->morphTo( 'addedby');
    }

    public function for()
    {
        return $this->morphTo( 'for');
    }

    public function session()
    {
        return $this->belongsTo(Session::class);
    }

    public function messages()
    {
        return $this->morphMany(Message::class, 'for');
    }

    public function isNotParticipant(User $user)
    {
        return !$this->isParticipant($user);
    }

    public function counsellors()
    {
        return $this->belongsToMany(Counsellor::class, 'counsellor_discussion', 'discussion_id', 'counsellor_id')
            ->withTimestamps();
    }

    public function isParticipant(User $user)
    {
        return false; // TODO
    }

    public function getOtherUsers(User $user)
    {
        $users = User::query()
            ->whereNot('id', $user->id)
            ->whereHas('counsellor', function ($query) {
                $query
                ->whereHas('discussions', function ($query) {
                    $query->where('discussion_id', $this->id);
                });
            })
            ->get();

        if (!$this->addedby->user?->is($user))
            $users = $users->merge($this->addedby->user);

        return $users;
    }

    public function scopeWherePending($query)
    {
        return $query->where('status', DiscussionStatusEnum::pending->value);
    }

    public function scopeWhereAddedby($query, Model $model)
    {
        return $query->where(function ($query) use ($model) {
            $query
                ->where('addedby_type', $model::class)
                ->where('addedby_id', $model->id);
        });
    }

    public function scopeWhereFor($query, Model $model)
    {
        return $query->where(function ($query) use ($model) {
            $query
                ->where('for_type', $model::class)
                ->where('for_id', $model->id);
        });
    }

    public function scopeWhereCounsellor($query, Model $model)
    {
        return $query->where(function ($query) use ($model) {
            $query
                ->whereHas('counsellors', function ($query) use ($model) {
                    $query->where('counsellor_id', $model->id);
                });
        });
    }

    public function getNotificationActionData()
    {
        if ($this->for_type == Therapy::class) {
            $type = 'Therapy';
            $url = url("therapies/{$this->for->id}");
        }
        else {
            $type = 'Group Therapy';
            $url = url("group_therapies/{$this->for->id}");
        }
        
        return [$type, $url];
    }

    public function getForChannelName()
    {
        if ($this->for_type == Therapy::class)
            return "therapies.{$this->for_id}";
        
        return "grouptherapies.{$this->for_id}";
    }
}
