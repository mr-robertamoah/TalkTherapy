<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Star extends Model
{
    use HasFactory;

    protected $fillable = ['type'];

    public function starredby()
    {
        return $this->morphTo();
    }

    public function starred()
    {
        return $this->morphTo();
    }

    public function starreable()
    {
        return $this->morphTo();
    }

    public function scopeWhereStarredUser($query, ?User $user)
    {
        return $query->where(function ($query) use ($user) {
            $query
                ->where('starred_type', User::class)
                ->where('starred_id', $user?->id);
        });
    }

    public function scopeWhereWithinCurrentMonth($query)
    {
        return $query->where(function ($query) {
            $query
                ->whereBetween('created_at', [now()->startOfMonth(), now()->endOfMonth()]);
        });
    }

    public function scopeWhereWithinPreviousMonth($query)
    {
        return $query->where(function ($query) {
            $query
                ->whereBetween('created_at', [now()->subMonth()->startOfMonth(), now()->subMonth()->endOfMonth()]);
        });
    }

    public function scopeWhereStarreable($query, ?Model $model)
    {
        return $query->where(function ($query) use ($model) {
            $query
                ->where('starreable_type', model::class)
                ->where('starreable_id', $model?->id);
        });
    }

    public function scopeWhereStarredCounsellor($query, ?Counsellor $counsellor)
    {
        return $query->where(function ($query) use ($counsellor) {
            $query
                ->where('starred_type', Counsellor::class)
                ->where('starred_id', $counsellor?->id);
        })->orWhere(function ($query) use ($counsellor) {
            $query
                ->whereStarredUser($counsellor?->user);
        });
    }
}
