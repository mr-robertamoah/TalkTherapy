<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PlatformSetting extends Model
{
    use HasFactory;

    protected $fillable = ['key', 'value', 'updated_by_id'];

    public function updatedBy()
    {
        return $this->belongsTo(User::class, 'updated_by_id');
    }
}
