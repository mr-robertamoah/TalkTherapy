<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class LicensingAuthority extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'licensing_type',
        'licensing_format',
        'about',
        'is_public',
        'country',
        'other',
        'type',
        'email',
        'phone',
    ];
}
