<?php

namespace App\Models;

use App\Enums\ConstantsEnum;
use App\Enums\RequestStatusEnum;
use App\Enums\RequestTypeEnum;
use App\Enums\TherapyPaymentTypeEnum;
use App\Traits\Likeable;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Notifications\Notifiable;

class Counsellor extends Model
{
    use HasFactory, Likeable,
        Notifiable, SoftDeletes;

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

    protected $casts = [
        'email_verified_at' => 'datetime',
    ];

    public function routeNotificationForMail()
    {
        return [
            $this->email => $this->getName(),
        ];
    }

    public function routeNotificationForBroadcast()
    {
        return new PrivateChannel("counsellors.{$this->id}");
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

    public function getOnlineSessionsHeldCountAttribute()
    {
        return $this->addedSessions()->whereOnline()->whereHeld()->count();
    }

    public function addedLinks()
    {
        return $this->morphMany(Link::class, 'addedby');
    }

    public function discussions()
    {
        return $this->belongsToMany(Discussion::class, 'counsellor_discussion', 'counsellor_id', 'discussion_id')
            ->withTimestamps();
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

    public function organizationCounsellors()
    {
        return $this->hasMany(OrganizationCounsellor::class);
    }

    // SCRUM-154 (TT-7.2b): informational/display-only preferred pricing -- never read by
    // app/Actions/Transaction/ (see GetPayableAmountAction's own guardrail comment).
    public function pricings()
    {
        return $this->hasMany(CounsellorPricing::class);
    }

    public function hasPendingCounsellorVerificationRequest()
    {
        return $this->sentRequests()
            ->where('type', RequestTypeEnum::counsellor->value)
            ->where('status', RequestStatusEnum::pending->value)
            ->exists();
    }

    public function addedDiscussions()
    {
        return $this->morphMany(Discussion::class, 'addedby');
    }

    public function verify()
    {
        $this->verified_at = now()->utc();
        $this->save();
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

    public function hasPendingSessions()
    {
        // SCRUM-134: the three OR'd conditions must be grouped in one outer where() -- otherwise
        // only wherePending() stays scoped to this counsellor's addedSessions(), and the trailing
        // orWhere()s break out to match ANY session in the whole table (the same
        // where()->orWhere() footgun already fixed elsewhere as SCRUM-129/139). Found here because
        // it silently blocked EnsureCanDeleteCounsellorAction's deletion flow for every counsellor
        // as soon as any session anywhere was upcoming.
        return $this->addedSessions()
            ->where(function ($query) {
                $query->wherePending()
                    ->orWhere(function ($query) {
                        $query->whereStartsInTheFuture();
                    })
                    ->orWhere(function ($query) {
                        $query->whereAboutToStart();
                    });
            })
            ->exists();
    }

    public function isVerified()
    {
        return (bool) $this->verified_at;
    }

    // TT-7.6a/SCRUM-225: null until the counsellor onboards a payout destination
    // (CreateCounsellorPayoutDestinationAction) -- deliberately not eager-loaded everywhere,
    // since this is only ever relevant to the counsellor's own payout flow, not general display.
    public function payoutAccount()
    {
        return $this->hasOne(CounsellorPayoutAccount::class);
    }

    // TT-7.6d/SCRUM-228: read side for the counsellor's own payout screen.
    public function earnings()
    {
        return $this->hasMany(CounsellorEarning::class);
    }

    public function payouts()
    {
        return $this->hasMany(CounsellorPayout::class);
    }

    public function addedPosts()
    {
        return $this->morphMany(Post::class, 'addedby');
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
        return ! $this->hasTestimonial();
    }

    public function addedReports()
    {
        return $this->morphMany(Report::class, 'addedby');
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
        // withTrashed: a counsellor's user may have deleted their account -- see
        // Therapy::counsellor() for why callers need this to resolve rather than crash.
        return $this->belongsTo(User::class)->withTrashed();
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

    public function addedGroupTherapies()
    {
        return $this->morphMany(GroupTherapy::class, 'addedby');
    }

    public function profession()
    {
        return $this->belongsTo(Profession::class);
    }

    // SCRUM-182/TT-10.2: migrated off the avatar_id/cover_id FK columns onto the shared, tagged
    // fileables pivot (see TT-10.1). withPivotValue (rather than a plain wherePivot) also makes
    // attach()/sync() auto-populate the tag column on write, not just filter reads -- note this is
    // NOT the same as the similarly-named wherePivotValue(), which doesn't exist on this relation
    // and is silently absorbed by Eloquent's dynamic-where-clause magic instead of erroring.
    public function avatarFile(): MorphToMany
    {
        return $this->morphToMany(File::class, 'fileable', 'fileables')
            ->withPivotValue('tag', 'avatar')
            ->withTimestamps();
    }

    public function coverFile(): MorphToMany
    {
        return $this->morphToMany(File::class, 'fileable', 'fileables')
            ->withPivotValue('tag', 'cover')
            ->withTimestamps();
    }

    // Accessors, not the relation methods themselves, are what CounsellorResource and the rest of
    // the app read ($counsellor->avatar / ->cover) -- a MorphToMany is always collection-returning,
    // so this preserves the pre-existing File|null contract without touching any consumer.
    public function getAvatarAttribute(): ?File
    {
        return $this->avatarFile->first();
    }

    public function getCoverAttribute(): ?File
    {
        return $this->coverFile->first();
    }

    public function engagesAUserInTherapy()
    {
        return $this->therapies()->exists();
    }

    public function hasNotEngagedAUserInTherapy()
    {
        return ! $this->engagesAUserInTherapy();
    }

    public function hasHeldATherapySession()
    {
        return $this->addedSessions()
            ->whereHeld()
            ->exists();
    }

    public function hasNotHeldATherapySession()
    {
        return ! $this->hasHeldATherapySession();
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

    public function scopeWhereDiscussion($query, Discussion $discussion)
    {
        return $query
            ->whereHas('discussions', function ($query) use ($discussion) {
                $query
                    ->where('discussion_id', $discussion->id);
            });
    }

    public function scopeWhereNotUser($query, $user)
    {
        return $query->where(function ($query) use ($user) {
            $query
                ->whereNot('user_id', $user->id);
        });
    }

    // The to/from alternation must be grouped -- `->whereTo($this)->orWhereFrom($this)`
    // un-grouped makes orWhereFrom() a top-level OR, unscoped from `wherePending()`/
    // `whereFor($model)`, so it would match ANY request this counsellor has ever sent
    // (any status, any target), not just a pending one for this specific model.
    public function hasPendingRequestFor(Model $model)
    {
        return (bool) Request::query()
            ->wherePending()
            ->whereFor($model)
            ->where(function ($query) {
                $query->whereTo($this)->orWhereFrom($this);
            })
            ->count();
    }

    public function doesNotHavePendingRequestFor(Model $model)
    {
        return ! $this->hasPendingRequestFor($model);
    }
}
