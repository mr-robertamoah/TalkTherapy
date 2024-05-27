<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Guardianship extends Model
{
    use HasFactory;

    protected $table = 'guardianship';

    protected $fillable = ['guardian_id', 'ward_id'];

    public function guardian()
    {
        return $this->belongsTo(User::class, 'guardian_id');
    }

    public function ward()
    {
        return $this->belongsTo(User::class, 'ward_id');
    }
}
