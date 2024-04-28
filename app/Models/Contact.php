<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Contact extends Model
{
    use HasFactory;

    protected $fillable = ['content', 'name', 'email', 'organisation', 'type'];

    public function addedby()
    {
        return $this->morphTo();
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

    public function scopeWhereName($query, ?string $name)
    {
        return $query
            ->when($name, function ($query) use ($name) {
                $query
                    ->where('name', 'LIKE', "%{$name}%");
            });
    }

    public function scopeWhereOrganisation($query, ?string $organisation)
    {
        return $query
            ->when($organisation, function ($query) use ($organisation) {
                $query
                    ->where('organisation', 'LIKE', "%{$organisation}%");
            });
    }
}
