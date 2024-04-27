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
        return $this->morphTo();
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

    public function scopeWhereReportable($query, Therapy|Counsellor|User $reportable)
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
