<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class License extends Model
{
    use HasFactory;

    protected $fillable = ['number'];

    public function licensingAuthority()
    {
        return $this->belongsTo(LicensingAuthority::class);
    }

    public function addedBy()
    {
        return $this->morphTo();
    }

    public function for()
    {
        return $this->morphTo();
    }
}
