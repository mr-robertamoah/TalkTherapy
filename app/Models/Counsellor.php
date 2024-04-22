<?php

namespace App\Models;

use App\Enums\ConstantsEnum;
use App\Enums\RequestStatusEnum;
use App\Enums\RequestTypeEnum;
use App\Enums\TherapyPaymentTypeEnum;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Notifications\Notifiable;

class Counsellor extends Model
{
    use HasFactory, Notifiable, SoftDeletes;

    protected $fillable = [
        'name',
        'about',
        'verified_at',
        'email_verified_at',
        'avatar_id',
        'cover_id',
        'profession_id',
        'contact_visible',
        'email',
        'phone',
    ];

    public function routeNotificationForMail()
    {
        return [
            $this->email => $this->getName()
        ];
    }

    public function getFreeTherapiesCountAttribute()
    {
        return $this->therapies()
            ->where('payment_type', TherapyPaymentTypeEnum::free->value)
            ->count();
    }

    public function getPaidTherapiesCountAttribute()
    {
        return $this->therapies()
            ->where('payment_type', TherapyPaymentTypeEnum::paid->value)
            ->count();
    }

    public function getFreeGroupTherapiesCountAttribute()
    {
        return 0; // TODO
    }

    public function getPaidGroupTherapiesCountAttribute()
    {
        return 0; // TODO
    }

    public function getOnlineSessionsCountAttribute()
    {
        return $this->addedSessions()->whereOnline()->count();
    }

    public function getInPersonSessionsCountAttribute()
    {
        return $this->addedSessions()->whereInPerson()->count();
    }

    public function getGroupTherapiesCountAttribute()
    {
        return $this->groupTherapies()->count();
    }

    public function getHeldSessionsCountAttribute()
    {
        return $this->addedSessions()->whereHeld()->count();
    }

    public function getSessionsCountAttribute()
    {
        return $this->addedSessions()->count();
    }

    public function getTherapiesCountAttribute()
    {
        return $this->therapies()->count();
    }

    public function getAllTherapiesCountAttribute()
    {
        return $this->therapiesCount + $this->groupTherapiesCount;
    }

    public function hasNationalIdentification()
    {
        return $this->licensesFor()
            ->where(function ($query) {
                $query
                    ->where('for_id', $this->id)
                    ->where('for_type', $this::class);
            })
            ->whereHas('licensingAuthority', function ($query) {
                $query->where('name', ConstantsEnum::nationalId->value);
            })
            ->where('validated', true)
            ->exists();
    }

    public function licensesFor()
    {
        return $this->morphMany(License::class, 'for');
    }

    public function getName()
    {
        return $this->name
            ? $this->name 
            : $this->user->name;
    }

    public function groupTherapies()
    {
        return $this->belongsToMany(GroupTherapy::class, 'counsellor_group_therapy', 'counsellor_id', 'group_therapy_id')
            ->withPivot(['state'])
            ->withTimestamps();
    }

    public function therapies()
    {
        return $this->hasMany(Therapy::class);
    }

    public function sentMessages()
    {
        return $this->morphMany(Message::class, 'from');
    }

    public function receivedMessages()
    {
        return $this->morphMany(Message::class, 'to');
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

    public function hasPendingCounsellorVerificationRequest()
    {
        return $this->sentRequests()
            ->where('type', RequestTypeEnum::counsellor->value)
            ->where('status', RequestStatusEnum::pending->value)
            ->exists();
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

    public function addedSessions()
    {
        return $this->morphMany(Session::class, 'addedby');
    }

    public function hasNoPendingSessions()
    {
        return !$this->hasPendingSessions();
    }

    public function hasPendingSessions()
    {
        return $this->addedSessions()
            ->where(function ($query) {
                $query->wherePending();
            })
            ->orWhere(function ($query) {
                $query->whereStartsInTheFuture();
            })
            ->orWhere(function ($query) {
                $query->whereAboutToStart();
            })
            ->exists();
    }

    public function starred()
    {
        return $this->morphMany(Star::class, 'starredby');
    }

    public function stars()
    {
        return $this->morphMany(Star::class, 'starred');
    }

    public function getOverallStarsCountAttribute()
    {
        return Star::query()->whereStarredCounsellor($this)->count();
    }

    public function getCurrentMonthStarsCountAttribute()
    {
        return Star::query()
            ->whereStarredCounsellor($this)
            ->whereWithinCurrentMonth()
            ->count();
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
        return $this->therapies()->exists();
    }

    public function hasNotEngagedAUserInTherapy()
    {
        return !$this->engagesAUserInTherapy();
    }

    public function hasHeldATherapySession()
    {
        return $this->addedSessions()
            ->whereHeld()
            ->exists();
    }

    public function hasNotHeldATherapySession()
    {
        return !$this->hasHeldATherapySession();
    }

    public function scopeWhereName($query, $name)
    {
        return $query->where('name', 'LIKE', "%{$name}%")
            ->orWhereHas('user', function ($query) use ($name) {
                $query
                    ->where('username', 'LIKE', "%{$name}%")
                    ->orWhere('firstName', 'LIKE', "%{$name}%")
                    ->orWhere('lastName', 'LIKE', "%{$name}%")
                    ->orWhere('otherNames', 'LIKE', "%{$name}%");
            });
    }

    public function scopeWhereNotUser($query, $user)
    {
        return $query->where(function ($query) use ($user) {
                $query
                    ->whereNot('user_id', $user->id);
            });
    }

    public function hasPendingRequestFor(Model $model)
    {
        return (bool) Request::query()
            ->wherePending()
            ->whereFor($model)
            ->whereTo($this)
            ->orWhereFrom($this)
            ->count();
    }

    public function doesNotHavePendingRequestFor(Model $model)
    {
        return !$this->hasPendingRequestFor($model);
    }
}
