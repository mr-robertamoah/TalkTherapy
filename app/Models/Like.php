<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Like extends Model
{
    use HasFactory;

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function likeable()
    {
        return $this->morphTo('likeable');
    }

    public function scopeWhereLikeable($query, $likeable)
    {
        return $query
            ->where('likeable_id', $likeable->id)
            ->where('likeable_type', $likeable::class);
    }
}
