<?php

namespace App\Models;

use App\Traits\Starreable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TherapyCase extends Model
{
    use HasFactory,
    Starreable;

    protected $fillable = [
        'name', 'description',
    ];

    public function addedBy() {
        return $this->morphTo();
    }
}
