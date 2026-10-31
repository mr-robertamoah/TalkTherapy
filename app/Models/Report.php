<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphToMany;

class Report extends Model
{
    use HasFactory;

    protected $fillable = ['description', 'data'];

    protected $casts = ['data' => 'array'];

    public function addedby()
    {
        // withTrashed: see Therapy::counsellor() -- the reporter (User or Counsellor) may have
        // since deleted their account.
        return $this->morphTo()->withTrashed();
    }

    // SCRUM-337: the reporter -- a User, or the User behind a reporting Counsellor.
    public function isReportedBy(?User $user): bool
    {
        if (! $user || ! $this->addedby) {
            return false;
        }

        return $this->addedby instanceof Counsellor
            ? (bool) $this->addedby->user?->is($user)
            : $this->addedby->is($user);
    }

    // SCRUM-337: reports (and their evidence) are for admins and the reporter only.
    public function isViewableBy(?User $user): bool
    {
        return (bool) $user && ($user->isAdmin() || $this->isReportedBy($user));
    }

    // Reports a non-admin may list: their own, as a User or as their Counsellor.
    public function scopeWhereReportedBy($query, User $user)
    {
        return $query->where(function ($query) use ($user) {
            $query->where(fn ($q) => $q->where('addedby_type', User::class)->where('addedby_id', $user->id));

            if ($user->counsellor) {
                $query->orWhere(fn ($q) => $q->where('addedby_type', Counsellor::class)->where('addedby_id', $user->counsellor->id));
            }
        });
    }

    public function reportable()
    {
        return $this->morphTo();
    }

    public function scopeWhereAddedby($query, Counsellor|User $addedby)
    {
        return $query
            ->where('addedby_type', $addedby::class)
            ->where('addedby_id', $addedby->id);
    }

    public function scopeWhereReportable($query, Model $reportable)
    {
        return $query
            ->where('reportable_type', $reportable::class)
            ->where('reportable_id', $reportable->id);
    }

    public function files(): MorphToMany
    {
        return $this
            ->morphToMany(File::class, 'fileable', 'fileables')
            ->withTimestamps();
    }
}
