<?php

namespace App\Traits;

use App\Models\Star;

trait Alertable
{
    public function alertable()
    {
        return $this->morphMany(Star::class, 'alertable');
    }
}