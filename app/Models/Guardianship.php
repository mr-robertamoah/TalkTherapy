<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Guardianship extends Model
{
    use HasFactory;

    protected $table = 'guardianship';

    protected $fillable = ['guardian_id', 'ward_id', 'ward_was_minor_at_creation'];

    protected $casts = [
        'ward_was_minor_at_creation' => 'boolean',
    ];

    public function guardian()
    {
        return $this->belongsTo(User::class, 'guardian_id');
    }

    public function ward()
    {
        return $this->belongsTo(User::class, 'ward_id');
    }
}
