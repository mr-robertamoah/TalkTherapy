<?php

namespace App\Models;

use App\Traits\Starreable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphToMany;

class Post extends Model
{
    use HasFactory,
    Starreable;

    protected $fillable = ['content'];

    public function addedby()
    {
        return $this->morphTo();
    }

    public function postable()
    {
        return $this->morphTo();
    }

    public function scopeWhereLike($query, string $like)
    {
        return $query->where('content', 'LIKE', "%{$like}%");
    }

    public function scopeWhereAddedby($query, Counsellor|User $addedby)
    {
        return $query
            ->where('addedby_type', $addedby::class)
            ->where('addedby_id', $addedby->id);
    }

    public function files(): MorphToMany
    {
        return $this
            ->morphToMany(File::class, 'fileable', 'fileables')
            ->withTimestamps();
    }
}
