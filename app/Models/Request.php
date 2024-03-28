<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Request extends Model
{
    use HasFactory;

    protected $fillable = ['data', 'type', 'status'];
    protected $casts = [
        'data' => 'array'
    ];

    public function from()
    {
        return $this->morphTo();
    }

    public function to()
    {
        return $this->morphTo();
    }

    public function for()
    {
        return $this->morphTo();
    }

    public function scopeWhereTo($query, $to)
    {
        return $query->where(function ($q) use ($to) {

            $q->where('to_id', $to->id);
            $q->where('to_type', $to::class);
        });
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
