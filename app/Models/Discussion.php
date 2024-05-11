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

    public function messages()
    {
        return $this->morphMany(Message::class, 'for');
    }

    public function isNotParticipant(User $user)
    {
        return !$this->isParticipant($user);
    }

    public function isParticipant(User $user)
    {
        return false; // TODO
    }

    public function getOtherUsers(User $user)
    {
        return false; // TODO
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
}
