<?php

namespace App\Models;

use App\Notifications\VerifyEmailNotification;
use Carbon\Carbon;
use Illuminate\Contracts\Auth\MustVerifyEmail as MustVerifyEmailContract;

use Illuminate\Auth\MustVerifyEmail;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable implements MustVerifyEmailContract
{
    use HasApiTokens, HasFactory, Notifiable, MustVerifyEmail, SoftDeletes;

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
        'email_verified_at',
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

    public function getAgeAttribute()
    {
        return !!$this->dob 
            ? (int) now()->diffInYears(new Carbon($this->dob), true) 
            : 0;
    }

    public function routeNotificationForMail()
    {
        return [
            $this->email => $this->name
        ];
    }

    public function routeNotificationForBroadcast()
    {
        return new PrivateChannel("users.{$this->id}");
    }

    public function getNameAttribute()
    {
        return constructName(
            $this->firstName,
            $this->lastName,
            $this->otherNames,
        );
    }

    public function likes()
    {
        return $this->hasMany(Like::class);
    }

    public function guardians()
    {
        return $this->hasMany(Guardianship::class, 'ward_id');
    }

    public function isAdult()
    {
        return $this->age && $this->age >= 18;
    }

    public function isGuardianOf(User $user)
    {
        return $this->guardians()->where('ward_id', $user->id)->exists();
    }

    public function hasGuardian(): bool
    {
        return $this->guardians()
            ->where('ward_id', $this->id)
            ->exists();
    }

    public function wards()
    {
        return $this->hasMany(Guardianship::class, 'guardian_id');
    }

    public function comments()
    {
        return $this->hasMany(Comment::class);
    }

    public function administrator()
    {
        return $this->hasOne(Administrator::class);
    }

    public function isSuperAdmin()
    {
        return $this
            ->administrator()
            ->whereSuperAdmin()
            ->exists();
    }

    public function addedHowTos()
    {
        return $this->hasMany(HowTo::class);
    }

    public function addedHowToSteps()
    {
        return $this->hasMany(HowToStep::class);
    }

    public function isAdmin()
    {
        return $this->administrator()->exists();
    }

    public function isGuardianOfAUserFor(GroupTherapy $groupTherapy)
    {
        // TODO implement this
        return true;
    }

    public function isNotAdmin()
    {
        return !$this->isAdmin();
    }

    public function addedLinks()
    {
        return $this->morphMany(Link::class, 'addedby');
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

    public function addedSessions()
    {
        return $this->morphMany(Session::class, 'addedby');
    }

    public function addedContacts()
    {
        return $this->morphMany(Contact::class, 'addedby');
    }

    public function addedTestimonials()
    {
        return $this->morphMany(Testimonial::class, 'addedby');
    }

    public function hasTestimonial()
    {
        return $this->addedTestimonials()->exists();
    }

    public function doesNotHaveTestimonial()
    {
        return !$this->hasTestimonial();
    }

    public function sentMessages()
    {
        return $this->morphMany(Message::class, 'from');
    }

    public function receivedMessages()
    {
        return $this->morphMany(Message::class, 'to');
    }

    public function alerts()
    {
        return $this->hasMany(Alert::class);
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

    public function addedPosts()
    {
        return $this->morphMany(Post::class, 'addedby');
    }

    public function addedReports()
    {
        return $this->morphMany(Report::class, 'addedby');
    }

    public function isCounsellor()
    {
        return $this->counsellor()->exists();
    }

    public function scopeWhereWaitingForAlert($query)
    {
        return $query
            ->whereHas('alerts', function ($query) {
                $query->whereWaiting();
            });
    }

    public function scopeWhereSuperAdmin($query)
    {
        return $query
            ->whereHas('administrator', function ($query) {
                $query->whereSuperAdmin();
            });
    }

    public function scopeWhereAdmin($query)
    {
        return $query
            ->whereHas('administrator');
    }

    public function scopeWhereWard($query, User $user)
    {
        return $query
            ->whereHas('wards', function ($query) use ($user) {
                $query->where('ward_id', $user->id);
            });
    }

    public function sendEmailVerificationNotification()
    {
        $this->notify(new VerifyEmailNotification);
    }
}
