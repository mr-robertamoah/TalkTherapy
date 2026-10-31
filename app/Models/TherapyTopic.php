<?php

namespace App\Models;

use App\Traits\Starreable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class TherapyTopic extends Model
{
    use HasFactory,
        SoftDeletes,
        Starreable;

    protected $fillable = [
        'name', 'description', 'counsellor_id',
    ];

    public function sessions()
    {
        return $this->belongsToMany(Session::class, 'therapy_topic_session')
            ->withTimestamps();
    }

    public function therapyTopicSessions()
    {
        return $this->hasMany(TherapyTopicSession::class);
    }

    // SCRUM-344: replaces a therapy() belongsTo on the `therapy_id` column that
    // 2025_02_10_084703 moved to topicable_id/topicable_type -- it always resolved to null, which
    // crashed every topic update. A topic belongs to a Therapy or a GroupTherapy.
    public function topicable()
    {
        return $this->morphTo();
    }

    public function counsellor()
    {
        // withTrashed: see Therapy::counsellor() -- same reasoning.
        return $this->belongsTo(Counsellor::class)->withTrashed();
    }

    public function messages()
    {
        return $this->hasMany(Message::class);
    }

    public function scopeWhereName($query, $name)
    {
        return $query->where('name', $name);
    }

    public function scopeWhereNameLike($query, $name)
    {
        return $query->where('name', 'LIKE', "%{$name}%");
    }
}
