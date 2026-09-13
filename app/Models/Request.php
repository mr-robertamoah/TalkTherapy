<?php

namespace App\Models;

use App\Enums\RequestStatusEnum;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphToMany;

class Request extends Model
{
    use HasFactory;

    protected $fillable = ['data', 'type', 'status', 'expires_at', 'round', 'reminder_sent_at'];

    protected $casts = [
        'data' => 'array',
        'expires_at' => 'datetime',
        'reminder_sent_at' => 'datetime',
    ];

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
