<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'firstName',
        'lastName',
        'otherNames',
        'username',
        'gender',
        'settings',
        'country',
        'email',
        'password',
        'dob',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'email_verified_at' => 'datetime',
        'dob' => 'datetime',
        'password' => 'hashed',
        'settings' => 'array',
    ];

    public function administrator() {
        return $this->hasOne(Administrator::class);
    }

    public function isAdmin() {
        return $this->administrator()->exists();
    }

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
