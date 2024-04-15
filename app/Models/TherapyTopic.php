<?php

namespace App\Models;

use App\Traits\Starreable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class TherapyTopic extends Model
{
    use HasFactory,
    Starreable,
    SoftDeletes;

    protected $fillable = [
        'name', 'description', 'counsellor_id', 'therapy_id'
    ];

    public function sessions()
    {
        return $this->belongsToMany(Session::class, 'therapy_topic_session')
            ->withTimestamps();
    }

    public function therapy()
    {
        return $this->belongsTo(Therapy::class);
    }

    public function counsellor()
    {
        return $this->belongsTo(Counsellor::class);
    }

    public function messages()
    {
        return $this->hasMany(Message::class);
    }

    public function scopeWhereTherapyId($query, $therapyId)
    {
        return $query->where('therapy_id', $therapyId);
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
