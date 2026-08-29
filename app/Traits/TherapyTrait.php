<?php

namespace App\Traits;

use App\Enums\SessionStatusEnum;
use App\Enums\TherapyStatusEnum;
use App\Models\Counsellor;
use App\Models\Discussion;
use App\Models\Language;
use App\Models\Message;
use App\Models\Religion;
use App\Models\Request;
use App\Models\Session;
use App\Models\TherapyCase;
use App\Models\TherapyTopic;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphToMany;

trait TherapyTrait
{
    public function getActiveSessionAttribute()
    {
        return Session::query()
            ->whereTherapyId($this->id)
            ->whereInSession()
            ->orWhere(function ($query) {
                $query->whereTherapyId($this->id);
                $query->whereFiveOrLessMinutesToStart();
            })
            ->orWhere(function ($query) {
                $query->whereTherapyId($this->id);
                $query->whereIsOngoing();
            })
            ->first();
    }

    public function getSessionsHeldAttribute()
    {
        return $this->sessions()->whereHeld()->count();
    }

    public function getSessionsCreatedAttribute()
    {
        return $this->sessions()->count();
    }

    public function getPaidSessionsAttribute()
    {
        return $this->sessions()->wherePaid()->count();
    }

    public function getFreeSessionsAttribute()
    {
        return $this->sessions()->whereFree()->count();
    }

    public function getStatus()
    {
        if ($this->status == TherapyStatusEnum::in_session->value) {
            return str_replace('_', ' ', TherapyStatusEnum::in_session->value);
        }

        return $this->status;
    }

    public function getActiveDiscussion(Counsellor $counsellor)
    {
        return $this->discussions()
            ->where(function ($query) use ($counsellor) {
                $query
                    ->whereIsParticipant($counsellor)
                    ->whereIsOngoing();
            })
            ->first();
    }

    public function getActiveSession(User $user)
    {
        return $this->sessions()
            ->where(function ($query) use ($user) {
                $query
                    ->whereIsParticipant($user)
                    ->whereIsNotUserWhoConfirmedHeld($user)
                    ->whereIsOngoing();
            })
            ->first();
    }

    public function addedby()
    {
        // withTrashed: see Therapy::counsellor() -- addedby (User or Counsellor) may have
        // since deleted their account.
        return $this->morphTo('addedby')->withTrashed();
    }

    public function topics()
    {
        return $this->morphMany(TherapyTopic::class, 'topicable');
    }

    public function messages()
    {
        return $this->morphMany(Message::class, 'for');
    }

    public function sessions()
    {
        return $this->morphMany(Session::class, 'for');
    }

    public function transactions()
    {
        return $this->morphMany(Transaction::class, 'for');
    }

    // Latest across ALL eligible payers for this model, not scoped to the current viewer -- a
    // GroupTherapy with multiple members each attempting to pay can surface one member's
    // pending/failed attempt to a different member. Explicit 'created_at' column: latestOfMany()
    // defaults to ordering by 'id', which coincides with insertion order today (every Transaction
    // row is created once via InitiatePaystackChargeAction and only ever updated afterwards) but
    // is not the same guarantee as this codebase's usual `->latest()` (created_at-based)
    // convention -- keep it explicit so a future insert path can't silently break "latest".
    public function latestTransaction()
    {
        return $this->morphOne(Transaction::class, 'for')->latestOfMany('created_at');
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

    public function pendingRequestFor(?Counsellor $counsellor)
    {
        if (is_null($counsellor)) {
            return null;
        }

        return Request::query()
            ->wherePending()
            ->whereFor($this)
            ->whereTo($counsellor)
            ->latest()
            ->first();
    }

    public function scopeWhereAddedby($query, Model $model)
    {
        return $query->where(function ($query) use ($model) {
            $query
                ->where('addedby_type', $model::class)
                ->where('addedby_id', $model->id);
        });
    }

    public function scopeWherePublic($query)
    {
        return $query->where('public', true);
    }

    public function requests()
    {
        return $this->morphMany(Request::class, 'for');
    }

    public function discussions()
    {
        return $this->morphMany(Discussion::class, 'for');
    }

    public function endSessions()
    {
        $this->sessions()
            ->wherePending()
            ->update(['status' => SessionStatusEnum::failed->value]);

        $this->sessions()
            ->wherePastEndTime()
            ->update(['status' => SessionStatusEnum::held->value]);

        $this->sessions()
            ->whereStatusIn([
                SessionStatusEnum::held_confirmation->value,
                SessionStatusEnum::in_session->value,
                SessionStatusEnum::in_session_confirmation->value,
            ])
            ->update(['status' => SessionStatusEnum::abandoned->value]);
    }

    public function getTopicsCountAttribute()
    {
        return $this->topics()->count();
    }
}
