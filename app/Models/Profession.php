<?php

namespace App\Models;

use App\Traits\Starreable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Profession extends Model
{
    use HasFactory,
    Starreable;

    protected $fillable = [
        'name',
        'description',
    ];

    public function counsellors()
    {
        return $this->hasMany(Counsellor::class);
    }

    public function addedBy() {
        return $this->morphTo('addedby');
    }
}
