<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphToMany;

class Counsellor extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'about',
        'verified_at',
        'email_verified_at',
        'avatar_id',
        'cover_id',
        'professional_id',
        'contact_visible',
        'email',
        'phone',
    ];

    public function getName()
    {
        return $this->name
            ? $this->name 
            : $this->user->name;
    }

    public function addedLanguages()
    {
        return $this->morphMany(Language::class, 'addedby');
    }

    public function addedReligions()
    {
        return $this->morphMany(Religion::class, 'addedby');
    }

    public function addedLicensingAuthorities()
    {
        return $this->morphMany(LicensingAuthority::class, 'addedby');
    }

    public function addedTherapyCases()
    {
        return $this->morphMany(TherapyCase::class, 'addedby');
    }

    public function addedProfessions()
    {
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

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function cases(): MorphToMany
    {
        return $this
            ->morphToMany(TherapyCase::class, 'caseable', 'caseables', relatedPivotKey: 'case_id')
            ->withTimestamps();
    }

    public function languages(): MorphToMany
    {
        return $this
            ->morphToMany(Language::class, 'languageable', 'languageables')
            ->withTimestamps();
    }

    public function religions(): MorphToMany
    {
        return $this
            ->morphToMany(Religion::class, 'religionable', 'religionables')
            ->withTimestamps();
    }

    public function addedFiles()
    {
        return $this->morphMany(File::class, 'addedby');
    }

    public function profession()
    {
        return $this->belongsTo(Profession::class);
    }

    public function avatar()
    {
        return $this->belongsTo(File::class, 'avatar_id');
    }

    public function cover()
    {
        return $this->belongsTo(File::class, 'cover_id');
    }

    public function engagesAUserInTherapy()
    {
        // TODO
        return false;
    }

    public function hasNotEngagedAUserInTherapy()
    {
        return !$this->engagesAUserInTherapy();
    }

    public function hasHeldATherapySession()
    {
        // TODO
        return false;
    }

    public function hasNotHeldATherapySession()
    {
        return !$this->hasHeldATherapySession();
    }
}
