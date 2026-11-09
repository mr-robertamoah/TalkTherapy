<?php

namespace App\Models;

use App\Enums\ConstantsEnum;
use App\Enums\FileTagEnum;
use App\Enums\SuspensionScopeEnum;
use App\Notifications\VerifyEmailNotification;
use App\Traits\HasGuardedDatabaseNotifications;
use App\Traits\HasTaggedFiles;
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
    use HasApiTokens, HasFactory, HasTaggedFiles, MustVerifyEmail, Notifiable, SoftDeletes;
    use HasGuardedDatabaseNotifications {
        HasGuardedDatabaseNotifications::notifications insteadof Notifiable;
    }

    // SCRUM-367/368: the columns cleared from a closed account once its retention period has passed
    // (AnonymiseClosedAccountAction) -- its personal details plus what would still tie the row to
    // them. /privacy's "How long we keep it" describes the same list in words; keep the two in step.
    public const ANONYMISED_ATTRIBUTES = [
        'firstName', 'lastName', 'otherNames', 'username', 'email', 'email_verified_at',
        'dob', 'dob_verified_at', 'gender', 'country', 'settings', 'remember_token',
    ];

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
        'notification_retention_days' => 'integer',
        'anonymised_at' => 'datetime',
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
        // SCRUM-367: an erased closed account (AnonymiseClosedAccountAction) has no name left.
        if ($this->anonymised_at) {
            return ConstantsEnum::deletedUserLabel->value;
        }

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
        return $this->taggedFile(FileTagEnum::avatar);
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

    // SCRUM-288: the ward's guardianships that count (Guardianship::scopeActive()). $exceptGuardianIds
    // leaves those guardians out; $lock makes it a locking read (see hasActiveGuardian()).
    public function activeGuardianships(array $exceptGuardianIds = [], bool $lock = false)
    {
        return $this->guardians()->active($exceptGuardianIds, $lock);
    }

    private ?bool $hasActiveGuardianCache = null;

    // Memoised on this model instance: every write in a therapy asks it, and a request's user is one
    // instance. $fresh (or leaving guardians out) re-reads, as a locking read (shared), so inside a
    // transaction it sees what other transactions have committed -- under REPEATABLE READ a plain
    // read could still answer from the transaction's first snapshot -- and holds it until commit.
    public function hasActiveGuardian(bool $fresh = false, array $exceptGuardianIds = []): bool
    {
        if ($fresh || $exceptGuardianIds) {
            $has = $this->activeGuardianships($exceptGuardianIds, lock: true)->first(['guardianship.id']) !== null;

            if (! $exceptGuardianIds) {
                $this->hasActiveGuardianCache = $has;
            }

            return $has;
        }

        return $this->hasActiveGuardianCache ??= $this->activeGuardianships()->exists();
    }

    // SCRUM-288: the one pause rule -- a minor (no date of birth counts as one) with no active
    // guardian can read their therapies but not act in them. Adults are answered without a query;
    // administrators are exempt. $exceptGuardianIds: would they be paused without these guardians
    // (asked before one stops counting, and to tell whether one coming back resumed them).
    // SCRUM-381: nor is a minor covered by a super administrator's safeguarding exception, checked
    // only once the guardian check has failed (most minors have a guardian: no extra query).
    public function isPausedForMissingGuardian(bool $fresh = false, array $exceptGuardianIds = []): bool
    {
        if (! $this->lacksGuardianCover($fresh, $exceptGuardianIds)) {
            return false;
        }

        return ! $this->hasActiveSafeguardingException($fresh || (bool) $exceptGuardianIds);
    }

    // SCRUM-382 review: the one "no guardian cover" rule, whether or not a safeguarding exception
    // stands in -- a minor (no date of birth counts as one), not an administrator, with no active
    // guardian. isPausedForMissingGuardian() is this minus any exception in force; granting one and
    // its reminder ask this directly.
    public function lacksGuardianCover(bool $fresh = false, array $exceptGuardianIds = []): bool
    {
        if ($this->isAdult() || $this->isAdmin()) {
            return false;
        }

        return ! $this->hasActiveGuardian($fresh, $exceptGuardianIds);
    }

    public function safeguardingExceptions()
    {
        return $this->hasMany(SafeguardingException::class, 'ward_id');
    }

    // false: none was in force when last read. Otherwise the end of the one that was -- so a reused
    // instance stops counting it the moment it runs out, without another query.
    private \DateTimeInterface|false|null $activeSafeguardingExceptionEndsAt = null;

    // SCRUM-381: memoised like hasActiveGuardian(); $fresh re-reads as a locking read (shared).
    public function hasActiveSafeguardingException(bool $fresh = false): bool
    {
        if ($fresh || is_null($this->activeSafeguardingExceptionEndsAt)) {
            $query = $this->safeguardingExceptions()->active();
            $row = ($fresh ? $query->sharedLock() : $query)->orderByDesc('ends_at')->first(['id', 'ends_at']);
            $this->activeSafeguardingExceptionEndsAt = $row?->ends_at ?? false;
        }

        return $this->activeSafeguardingExceptionEndsAt !== false && now()->lt($this->activeSafeguardingExceptionEndsAt);
    }

    // SCRUM-382 review: the memo above only re-checks the clock, so an exception ended early (or a
    // new one granted) on another instance -- or on this one, by an action holding it -- isn't seen
    // until it is re-read. The actions that grant or end one call this on any instance they were
    // handed, like forgetActiveSuspension().
    public function forgetSafeguardingException(): static
    {
        $this->activeSafeguardingExceptionEndsAt = null;

        return $this;
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

    // The groups this user is a member of now (SCRUM-73: not the ones they left).
    public function groupTherapies()
    {
        return $this->groupTherapyMembershipRelation('groupTherapies')->wherePivotNull('left_at');
    }

    // SCRUM-73: every group this user has ever been a member of, the ones they left included.
    public function allGroupTherapyMemberships()
    {
        return $this->groupTherapyMembershipRelation('allGroupTherapyMemberships');
    }

    // Named explicitly: belongsToMany() otherwise guesses the relation name from its caller.
    private function groupTherapyMembershipRelation(string $name)
    {
        return $this->belongsToMany(GroupTherapy::class, 'group_therapy_user', 'user_id', 'group_therapy_id', relation: $name)
            ->withPivot(['background_story', 'anonymous', 'left_at'])
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

    // The suspension in force that covers $scope (an account suspension covers both), memoised on
    // this model instance (a request's user is one instance): the middleware and the guards ask on
    // every request.
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

    // SCRUM-31: currently verified (Counsellor::isVerified()).
    public function isVerifiedCounsellor()
    {
        return (bool) $this->counsellor?->isVerified();
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
            ->whereNotAccountSuspended();
    }

    // SCRUM-382 review: isPausedForMissingGuardian() as a query, for lists -- no date of birth or one
    // under 18, not an administrator, no active guardian and no safeguarding exception in force. The
    // date filter only narrows it: callers still drop anyone isAdult() says is an adult.
    public function scopeWherePausedForMissingGuardian($query)
    {
        $adultFrom = now()->subYears(18)->toDateString();

        return $query
            ->where(fn ($q) => $q->whereNull('dob')->orWhere('dob', '>', $adultFrom))
            ->whereDoesntHave('administrator')
            ->whereDoesntHave('guardians', fn ($q) => $q->active())
            ->whereDoesntHave('safeguardingExceptions', fn ($q) => $q->active());
    }

    public function scopeWhereNotAccountSuspended($query)
    {
        return $query->whereDoesntHave('suspensions', fn ($q) => $q->active()->where('scope', SuspensionScopeEnum::account->value));
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
        // SCRUM-400 (found there): an account made with a username only has no address to verify -- the
        // job used to fail on an empty address (and, run synchronously, failed the sign-up).
        if (blank($this->email)) {
            return;
        }

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
