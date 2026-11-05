<?php

namespace App\Models;

use App\Enums\FileTagEnum;
use App\Enums\RequestStatusEnum;
use App\Enums\RequestTypeEnum;
use App\Traits\HasTaggedFiles;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphToMany;

class Request extends Model
{
    use HasFactory, HasTaggedFiles;

    protected $fillable = ['data', 'type', 'status', 'expires_at', 'round', 'reminder_sent_at'];

    protected $casts = [
        'data' => 'array',
        'expires_at' => 'datetime',
        'reminder_sent_at' => 'datetime',
        'responded_at' => 'datetime',
    ];

    // SCRUM-362: stamp the moment a request first leaves PENDING (accepted, rejected, superseded or
    // made moot) -- once, so a later change to the row never moves it. Query-builder updates skip
    // this hook, so those few call sites set responded_at themselves.
    protected static function booted(): void
    {
        static::saving(function (Request $request) {
            // A new row without a status is PENDING (the column's default).
            if ($request->status && $request->status !== RequestStatusEnum::pending->value && is_null($request->responded_at)) {
                $request->responded_at = now();
            }
        });
    }

    public function from()
    {
        return $this->morphTo();
    }

    // TT-4.11a/SCRUM-302: mirrors License::files() exactly -- introduced for identity-verification
    // document attachments (age/identity verification, SCRUM-289), always uploaded to the private
    // 'identity_documents' disk (never the public default), retrieved only through the dedicated
    // authorized route/action, never File::url/getUrlFor(). Generic on the Request model itself
    // (not type-specific) since nothing about the relation depends on `type`.
    public function files(): MorphToMany
    {
        return $this
            ->morphToMany(File::class, 'fileable', 'fileables')
            ->withTimestamps();
    }

    // TT-4.11b/SCRUM-303: tag-scoped, mirrors User::avatarFile()'s own withPivotValue() shape --
    // lets a re-submission simply ->sync() a new document in place of an old one (at most one
    // per request, per the fileables table's own unique(fileable_type, fileable_id, tag) index)
    // rather than managing the tag manually against the generic files() relation above.
    public function identityDocument(): MorphToMany
    {
        return $this->taggedFile(FileTagEnum::identityDocument);
    }

    public function to()
    {
        return $this->morphTo();
    }

    public function for()
    {
        return $this->morphTo();
    }

    public function scopeWhereFor($query, $for)
    {
        return $query->where(function ($q) use ($for) {

            $q->where('for_id', $for->id);
            $q->where('for_type', $for::class);
        });
    }

    public function scopeWhereTo($query, $to)
    {
        return $query->where(function ($q) use ($to) {

            $q->where('to_id', $to->id);
            $q->where('to_type', $to::class);
        });
    }

    public function scopeWherePending($query)
    {
        return $query->where('status', RequestStatusEnum::pending->value);
    }

    // SCRUM-381: counsellors' requests for a safeguarding exception -- for one minor ($ward, stored
    // as data.wardId) and/or in one Therapy or GroupTherapy ($for), when given.
    public function scopeSafeguardingException($query, User|int|null $ward = null, $for = null)
    {
        return $query
            ->where('type', RequestTypeEnum::safeguardingException->value)
            ->when(! is_null($ward), fn ($query) => $query->where('data->wardId', $ward instanceof User ? $ward->id : $ward))
            ->when($for, fn ($query) => $query->whereFor($for));
    }

    // The ones still waiting for the super administrator.
    public function scopePendingSafeguardingException($query, User|int|null $ward = null, $for = null)
    {
        return $query->safeguardingException($ward, $for)->wherePending();
    }

    public function scopeWhereType($query, $type)
    {
        return $query->where('type', $type);
    }

    public function scopeWhereFrom($query, $from)
    {
        return $query->where(function ($q) use ($from) {

            $q->where('from_id', $from->id);
            $q->where('from_type', $from::class);
        });
    }

    public function scopeOrWhereTo($query, $to)
    {
        return $query->orWhere(function ($q) use ($to) {

            $q->where('to_id', $to->id);
            $q->where('to_type', $to::class);
        });
    }

    public function scopeOrWhereFrom($query, $from)
    {
        return $query->orWhere(function ($q) use ($from) {

            $q->where('from_id', $from->id);
            $q->where('from_type', $from::class);
        });
    }
}
