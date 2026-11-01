<?php

namespace App\Models;

use App\Enums\SuspensionScopeEnum;
use App\Notifications\VerifyEmailNotification;
use Carbon\Carbon;
use Illuminate\Auth\MustVerifyEmail;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Auth\MustVerifyEmail as MustVerifyEmailContract;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Collection;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable implements MustVerifyEmailContract
{
    use HasApiTokens, HasFactory, MustVerifyEmail, Notifiable, SoftDeletes;

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
        'dob_verified_at',
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
        'dob_verified_at' => 'datetime',
        'password' => 'hashed',
        'settings' => 'array',
    ];

    public function getAgeAttribute()
    {
        return (bool) $this->dob
            ? (int) now()->diffInYears(new Carbon($this->dob), true)
            : 0;
    }

    public function routeNotificationForMail()
    {
        return [
            $this->email => $this->name,
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

    // SCRUM-182/TT-10.6: tagged fileables pivot, same pattern as Counsellor::avatarFile() (TT-10.2)
    // and Organization::logoFile() (TT-10.4) -- withPivotValue (not the similarly-named,
    // nonexistent wherePivotValue) constrains reads AND auto-populates the tag column on
    // attach()/sync(). UserResource is only ever built singly (the shared Inertia auth-user
    // prop), never via ::collection(), so this carries no N+1 exposure the way Counsellor's did.
    public function avatarFile(): MorphToMany
    {
        return $this->morphToMany(File::class, 'fileable', 'fileables')
            ->withPivotValue('tag', 'avatar')
            ->withTimestamps();
    }

    public function getAvatarAttribute(): ?File
    {
        return $this->avatarFile->first();
    }

    // SCRUM-357: the identity a presence channel shows for this user -- never the real id, which
    // anyone signed in can resolve through /api/users. Laravel's Pusher/Reverb broadcaster asks for
    // this inside the /broadcasting/auth request, so it is scoped to the channel being joined
    // (`channel_name`): the same person gets a different key in every channel, so being in two
    // channels never links an anonymous member of one to a named member of another (code review).
    // A keyed hash: stable per channel (a browser recognises itself), not reversible.
    public function getAuthIdentifierForBroadcasting(): string
    {
        return $this->broadcastKeyFor((string) request('channel_name'));
    }

    public function broadcastKeyFor(string $channelName): string
    {
        return substr(hash_hmac('sha256', "presence:{$channelName}:user:{$this->id}", (string) config('app.key')), 0, 24);
    }

    public function guardians()
    {
        return $this->hasMany(Guardianship::class, 'ward_id');
    }

    public function isAdult()
    {
        return $this->age && $this->age >= 18;
    }

    // TT-4.10c/SCRUM-292: mirrors getAgeAttribute()/isAdult()'s own math exactly, but for a
    // CANDIDATE dob that isn't (yet) saved on any User instance -- EnsureDobChangeIsAllowedAction
    // needs to compare "would this dob edit flip the person's adult status" without instantiating
    // a throwaway model or duplicating the age calculation a third time.
    public static function isAdultForDob(?string $dob): bool
    {
        if (! $dob) {
            return false;
        }

        return (int) now()->diffInYears(new Carbon($dob), true) >= 18;
    }

    public function isGuardianOf(User $user)
    {
        return $this->wards()->where('ward_id', $user->id)->exists();
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

    private ?Collection $wardIdsCache = null;

    // SCRUM-362: memoised -- Report::involves() asks it for every row of an admin's report list.
    public function wardIds(): Collection
    {
        return $this->wardIdsCache ??= $this->wards()->pluck('ward_id');
    }

    public function comments()
    {
        return $this->hasMany(Comment::class);
    }

    public function administrator()
    {
        return $this->hasOne(Administrator::class);
    }

    public function administeredOrganizations()
    {
        return $this->belongsToMany(Organization::class, 'organization_admins')
            ->withPivot(['role'])
            ->withTimestamps();
    }

    public function organizationMemberships()
    {
        return $this->hasMany(OrganizationMember::class);
    }

    public function isAdminOf(Organization $organization): bool
    {
        return $this->administeredOrganizations()->whereKey($organization->id)->exists();
    }

    private ?bool $isSuperAdminCache = null;

    public function isSuperAdmin()
    {
        return $this->isSuperAdminCache ??= $this
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

    private ?bool $isAdminCache = null;

    // Memoised: list resources ask it once per row (SCRUM-362 review).
    public function isAdmin()
    {
        return $this->isAdminCache ??= $this->administrator()->exists();
    }

    public function isGuardianOfAUserFor(GroupTherapy $groupTherapy)
    {
        return $groupTherapy->addedby_type === User::class &&
            $groupTherapy->addedby &&
            $this->isGuardianOf($groupTherapy->addedby);
    }

    public function isNotAdmin()
    {
        return ! $this->isAdmin();
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
        return ! $this->hasTestimonial();
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

    // SCRUM-359 ---------------------------------------------------------------------------------

    public function suspensions()
    {
        return $this->hasMany(Suspension::class)->latest('id');
    }

    private array $activeSuspensionCache = [];

    // The suspension in force that covers $scope (an account suspension covers both), memoised for
    // the request: the middleware and the guards ask on every request.
    public function activeSuspension(SuspensionScopeEnum $scope = SuspensionScopeEnum::account): ?Suspension
    {
        // Re-read if the cached one has since run out (a long-lived instance, e.g. in tests).
        $cached = $this->activeSuspensionCache[$scope->value] ?? null;
        if (! array_key_exists($scope->value, $this->activeSuspensionCache) || ($cached && ! $cached->isActive())) {
            $this->activeSuspensionCache[$scope->value] = Suspension::query()
                ->where('user_id', $this->id)
                ->active()
                ->covering($scope)
                ->orderByRaw("scope = 'account' desc")
                ->orderByRaw('ends_at is null desc')
                ->orderByDesc('ends_at')
                ->first();
        }

        return $this->activeSuspensionCache[$scope->value];
    }

    public function forgetActiveSuspension(): static
    {
        $this->activeSuspensionCache = [];

        return $this;
    }

    // Either scope -- an account suspension first.
    public function anyActiveSuspension(): ?Suspension
    {
        return $this->activeSuspension(SuspensionScopeEnum::counsellor);
    }

    public function isAccountSuspended(): bool
    {
        return (bool) $this->activeSuspension(SuspensionScopeEnum::account);
    }

    public function isSuspendedAsCounsellor(): bool
    {
        return (bool) $this->activeSuspension(SuspensionScopeEnum::counsellor);
    }

    public function stars()
    {
        return $this->morphMany(Star::class, 'starred');
    }

    public function isNotVerifiedCounsellor()
    {
        return ! $this->isVerifiedCounsellor();
    }

    public function isNotCounsellor()
    {
        return ! $this->isCounsellor();
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

    // Used to pick admins to alert. SCRUM-359: never one whose account is suspended -- they
    // can't act on it.
    public function scopeWhereAdmin($query)
    {
        return $query
            ->whereHas('administrator')
            ->whereDoesntHave('suspensions', fn ($q) => $q->active()->where('scope', SuspensionScopeEnum::account->value));
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

    // Mirrors Counsellor::hasPendingRequestFor()/doesNotHavePendingRequestFor(). The to/from
    // alternation must be grouped -- `->whereTo($this)->orWhereFrom($this)` un-grouped makes
    // orWhereFrom() a top-level OR, unscoped from `wherePending()`/`whereFor($model)`, so it
    // would match ANY request the user has ever sent (any status, any target), not just a
    // pending one for this specific model.
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
