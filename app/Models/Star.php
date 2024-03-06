<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Star extends Model
{
    use HasFactory;

    protected $fillable = ['type'];

    public function starredby()
    {
        return $this->morphTo();
    }

    public function starred()
    {
        return $this->morphTo();
    }

    public function starreable()
    {
        return $this->morphTo();
    }
}
