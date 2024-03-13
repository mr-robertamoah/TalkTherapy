<?php

namespace App\Models;

use App\Traits\Starreable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphToMany;

class TherapyCase extends Model
{
    use HasFactory,
    Starreable;

    protected $fillable = [
        'name', 'description',
    ];

    public function addedBy() {
        return $this->morphTo();
    }

    public function counsellors(): MorphToMany
    {
        return $this
            ->morphedByMany(Counsellor::class, 'caseable', 'caseables', 'case_id')
            ->withTimestamps();
    }

    public function therapies(): MorphToMany
    {
        return $this
            ->morphedByMany(Therapy::class, 'caseable', 'caseables', 'case_id')
            ->withTimestamps();
    }
}
