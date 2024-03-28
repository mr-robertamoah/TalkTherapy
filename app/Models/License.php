<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphToMany;

class License extends Model
{
    use HasFactory;

    protected $fillable = ['number', 'validated'];

    public function licensingAuthority()
    {
        return $this->belongsTo(LicensingAuthority::class);
    }

    public function addedBy()
    {
        return $this->morphTo('addedby');
    }

    public function for()
    {
        return $this->morphTo();
    }

    public function files(): MorphToMany
    {
        return $this
            ->morphToMany(File::class, 'fileable', 'fileables')
            ->withTimestamps();
    }

    public function validate()
    {
        return $this->update([
            'validated' => true
        ]);
    }
}
