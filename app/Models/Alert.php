<?php

namespace App\Models;

use App\Enums\AlertStatusEnum;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Alert extends Model
{
    use HasFactory;

    protected $fillable = ['status', 'alertable_type', 'alertable_id'];

    public function alertable()
    {
        return $this->morphTo();
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function scopeWhereStatusIn($query, $statuses)
    {
        return $query->whereIn('status', $statuses);
    }

    public function scopeWhereWaiting($query)
    {
        return $query->where('status', AlertStatusEnum::waiting->value);
    }
}
