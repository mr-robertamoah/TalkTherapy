<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TherapyTopicSession extends Model
{
    use HasFactory;

    protected $table = 'therapy_topic_session';

    protected $fillable = ['therapy_topic_id', 'session_id', 'current'];

    public function therapyTopic()
    {
        return $this->belongsTo(TherapyTopic::class);
    }

    public function session()
    {
        return $this->belongsTo(Session::class);
    }

    public function scopeWhereSession($query, Session $session)
    {
        return $query->where('session_id', $session->id);
    }

    public function scopeWhereTopic($query, TherapyTopic $topic)
    {
        return $query->where('therapy_topic_id', $topic->id);
    }

    public function scopeWhereCurrent($query)
    {
        return $query->where('current', true);
    }
}
