<?php

namespace App\Models;

use App\Traits\Starreable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphToMany;

class Language extends Model
{
    use HasFactory,
    Starreable;

    protected $fillable = [
        'name', 'about',
    ];

    public function addedby() {
        return $this->morphTo('addedby');
    }

    public function counsellors(): MorphToMany
    {
        return $this
            ->morphedByMany(Counsellor::class, 'languageable', 'languageables')
            ->withTimestamps();
    }

    public function therapy(): MorphToMany
    {
        return $this
            ->morphedByMany(Therapy::class, 'languageable', 'languageables')
            ->withTimestamps();
    }
}
