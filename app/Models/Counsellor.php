<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Counsellor extends Model
{
    use HasFactory;

    public function addedLanguages() {
        return $this->morphMany(Language::class, 'addedby');
    }

    public function addedReligions() {
        return $this->morphMany(Religion::class, 'addedby');
    }

    public function addedLicensingAuthorities() {
        return $this->morphMany(LicensingAuthority::class, 'addedby');
    }

    public function addedTherapyCases() {
        return $this->morphMany(TherapyCase::class, 'addedby');
    }

    public function addedProfessions() {
        return $this->morphMany(Profession::class, 'addedby');
    }

    public function starredby()
    {
        return $this->morphMany(Star::class, 'starredby');
    }

    public function starred()
    {
        return $this->morphMany(Star::class, 'starred');
    }
}
