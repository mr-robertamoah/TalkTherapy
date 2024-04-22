<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Visitor extends Model
{
    use HasFactory;

    protected $fillable = ['user_id', 'ip_address', 'data'];

    protected $casts = ['data' => 'array'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function scopeWhereUser($query)
    {
        return $query->whereNotNull('user_id');
    }

    public function scopeWhereNonUser($query)
    {
        return $query
            ->whereNull('user_id')
            ->whereNotNull('ip_address');
    }
}
