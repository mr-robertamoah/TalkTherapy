<?php

namespace App\Models;

use App\Traits\Starreable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphToMany;

class Religion extends Model
{
    use HasFactory,
    Starreable;

    protected $fillable = [
        'name', 'about',
    ];

    public function addedBy() {
        return $this->morphTo('addedby');
    }

    public function counsellors(): MorphToMany
    {
        return $this
            ->morphedByMany(Counsellor::class, 'religionable', 'religionables')
            ->withTimestamps();
    }

    public function therapy(): MorphToMany
    {
        return $this
            ->morphedByMany(Therapy::class, 'religionable', 'religionables')
            ->withTimestamps();
    }
}
