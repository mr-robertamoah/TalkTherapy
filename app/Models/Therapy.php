<?php

namespace App\Models;

use App\Enums\TherapyStatusEnum;
use App\Traits\Starreable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphToMany;

class Therapy extends Model
{
    use HasFactory,
    Starreable;

    protected $fillable = [
        'session_type', 'payment_type', 'background_story', 'allow_in_person', 'name',
        'public', 'anonymous', 'payment_data', 'status', 'max_sessions', 'counsellor_id'
    ];

    protected $casts = [
        'payment_data' => 'array'
    ];

    public function getSessionsHeldAttribute()
    {
        return 0; // TODO
    }

    public function getSessionsCreatedAttribute()
    {
        return 0; // TODO
    }

    public function getPaidSessionsAttribute()
    {
        return 0; // TODO
    }

    public function getFreeSessionsAttribute()
    {
        return 0; // TODO
    }

    public function getStatus()
    {
        if ($this->status == TherapyStatusEnum::in_session->value)
            return str_replace('_', ' ', TherapyStatusEnum::in_session->value);
        
        return $this->status;
    }

    public function addedBy()
    {
        return $this->morphTo('addedby');
    }

    public function therapyTopics()
    {
        return $this->morphMany(TherapyTopic::class, 'for');
    }

    public function messages()
    {
        return $this->morphMany(Message::class, 'for');
    }

    public function counsellor()
    {
        return $this->belongsTo(Counsellor::class);
    }

    public function cases(): MorphToMany
    {
        return $this
            ->morphToMany(TherapyCase::class, 'caseable', 'caseables', relatedPivotKey: 'case_id')
            ->withTimestamps();
    }

    public function languages(): MorphToMany
    {
        return $this
            ->morphToMany(Language::class, 'languageable', 'languageables')
            ->withTimestamps();
    }

    public function religions(): MorphToMany
    {
        return $this
            ->morphToMany(Religion::class, 'religionable', 'religionables')
            ->withTimestamps();
    }

    public function isParticipant(User $user)
    {
        return $this->addedBy->is($user) || $this->counsellor?->user->is($user);
    }

    public function scopeWhereParticipant($query, User $user)
    {
        return $query->where(function ($query) use ($user) {
            $query
                ->where('addedby_type', $user::class)
                ->where('addedby_id', $user->id);
        })->when($user->counsellor, function ($query) use ($user) {
            $query->orWhere(function ($query) use ($user) {
                $query->where('counsellor_id', $user->counsellor->id);
            });
        });
    }
}
