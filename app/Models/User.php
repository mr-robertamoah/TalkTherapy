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

    public function getNameAttribute()
    {
        return constructName(
            $this->firstName,
            $this->lastName,
            $this->otherNames,
        );
    }

    public function administrator()
    {
        return $this->hasOne(Administrator::class);
    }

    public function isAdmin()
    {
        return $this->administrator()->exists();
    }

    public function isNotAdmin()
    {
        return !$this->isAdmin();
    }

    public function addedLanguages()
    {
        return $this->morphMany(Language::class, 'addedby');
    }

    public function addedFiles()
    {
        return $this->morphMany(File::class, 'addedby');
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

    public function sentMessages()
    {
        return $this->morphMany(Message::class, 'from');
    }

    public function receivedMessages()
    {
        return $this->morphMany(Message::class, 'to');
    }

    public function addedTherapies()
    {
        return $this->morphMany(Therapy::class, 'addedby');
    }

    public function addedGroupTherapies()
    {
        return $this->morphMany(GroupTherapy::class, 'addedby');
    }

    public function groupTherapies()
    {
        return $this->belongsToMany(GroupTherapy::class, 'group_therapy_user', 'user_id', 'group_therapy_id')
            ->withPivot(['background_story'])
            ->withTimestamps();
    }

    public function sentRequests()
    {
        return $this->morphMany(Request::class, 'from');
    }

    public function receivedRequests()
    {
        return $this->morphMany(Request::class, 'to');
    }

    public function requests()
    {
        return $this->morphMany(Request::class, 'for');
    }

    public function starred()
    {
        return $this->morphMany(Star::class, 'starredby');
    }

    public function counsellor()
    {
        return $this->hasOne(Counsellor::class);
    }

    public function stars()
    {
        return $this->morphMany(Star::class, 'starred');
    }

    public function isNotVerifiedCounsellor()
    {
        return !$this->isVerifiedCounsellor();
    }

    public function isNotCounsellor()
    {
        return !$this->isCounsellor();
    }

    public function isVerifiedCounsellor()
    {
        return $this->counsellor()->whereNotNull('verified_at')->exists();
    }

    public function isCounsellor()
    {
        return $this->counsellor()->exists();
    }
}
