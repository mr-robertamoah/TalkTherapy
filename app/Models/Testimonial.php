<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Testimonial extends Model
{
    use HasFactory;

    protected $fillable = ['content', 'use'];

    public function addedby()
    {
        // withTrashed: see Therapy::counsellor() -- addedby (User or Counsellor) may have
        // since deleted their account.
        return $this->morphTo()->withTrashed();
    }

    public function scopeWhereUse($query)
    {
        return $query->where('use', true);
    }

    public function scopeWhereAddedby($query, Counsellor|User $addedby)
    {
        return $query
            ->where('addedby_type', $addedby::class)
            ->where('addedby_id', $addedby->id);
    }

    public function scopeWhereLike($query, ?string $like)
    {
        return $query
            ->when($like, function ($query) use ($like) {
                $query
                    ->where('content', 'LIKE', "%{$like}%");
            });
    }
}
