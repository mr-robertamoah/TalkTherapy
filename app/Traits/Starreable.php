<?php

namespace App\Traits;

use App\Models\Star;

trait Starreable
{
    public function starreable()
    {
        return $this->morphMany(Star::class, 'starreable');
    }
}