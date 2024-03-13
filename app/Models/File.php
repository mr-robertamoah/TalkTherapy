<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphToMany;

class File extends Model
{
    use HasFactory;

    protected $fillable = [
        'name', 'mime', 'size', 'path', 'storage'
    ];

    public function getUrlAttribute()
    {
        return getUrlFor($this);
    }

    public function licenses(): MorphToMany
    {
        return $this
            ->morphedByMany(Counsellor::class, 'fileable', 'fileables')
            ->withTimestamps();
    }
}
