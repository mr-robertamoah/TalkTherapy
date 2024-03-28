<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TherapyTopic extends Model
{
    use HasFactory;

    public function for()
    {
        return $this->morphTo();
    }

    public function counsellor()
    {
        return $this->belongsTo(Counsellor::class);
    }

    public function messages()
    {
        return $this->hasMany(Message::class);
    }
}
