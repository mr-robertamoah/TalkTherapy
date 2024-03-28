<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class LicensingAuthority extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'license_type',
        'license_format',
        'about',
        'is_public',
        'country',
        'other',
        'type',
        'email',
        'phone',
        'validated',
    ];

    public function licenses()
    {
        return $this->hasMany(License::class);
    }

    public function validate()
    {
        return $this->update([
            'validated' => true
        ]);
    }

    public function isNotValidated()
    {
        return !$this->validated;
    }
}
