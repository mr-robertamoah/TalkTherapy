<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphToMany;

class File extends Model
{
    use HasFactory;

    protected $fillable = [
        'name', 'mime', 'size', 'path', 'storage',
    ];

    public function getUrlAttribute()
    {
        return getUrlFor($this);
    }

    public function licenses(): MorphToMany
    {
        return $this
            ->morphedByMany(License::class, 'fileable', 'fileables')
            ->withTimestamps();
    }

    // TT-4.11a/SCRUM-302: mirrors licenses() above, for identity-verification document
    // attachments on a Request row.
    public function requests(): MorphToMany
    {
        return $this
            ->morphedByMany(Request::class, 'fileable', 'fileables')
            ->withTimestamps();
    }
}
