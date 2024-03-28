<?php

namespace App\Models;

use App\Traits\Starreable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphToMany;

class GroupTherapy extends Model
{
    use HasFactory,
    Starreable;

    protected $fillable = [
        'session_type', 'payment_type', 'max_users', 'allow_anyone', 'about', 'name',
        'public', 'anonymous', 'payment_data', 'status', 'max_sessions'
    ];

    protected $casts = [
        'payment_data' => 'array'
    ];

    public function addedBy()
    {
        return $this->morphTo('addedby');
    }

    public function counsellors()
    {
        return $this->belongsToMany(Counsellor::class, 'counsellor_group_therapy', 'group_therapy_id', 'counsellor_id')
            ->withPivot(['state'])
            ->withTimestamps();
    }

    public function users()
    {
        return $this->belongsToMany(User::class, 'group_therapy_user', 'group_therapy_id', 'user_id')
            ->withPivot(['background_story'])
            ->withTimestamps();
    }

    public function therapyTopics()
    {
        return $this->morphMany(TherapyTopic::class, 'for');
    }

    public function messages()
    {
        return $this->morphMany(Message::class, 'for');
    }

    public function cases(): MorphToMany
    {
        return $this
            ->morphToMany(TherapyCase::class, 'caseable', 'caseables', relatedPivotKey: 'case_id')
            ->withTimestamps();
    }
}
