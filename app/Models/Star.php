<?php

namespace App\Models;

use App\Enums\StarSourceEnum;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Star extends Model
{
    use HasFactory;

    protected $fillable = ['type'];

    // TT-7.11a/SCRUM-470: source and award_key are set only by AwardCounsellorStarAction (never mass-assigned).
    protected function casts(): array
    {
        return ['source' => StarSourceEnum::class];
    }

    public function starredby()
    {
        return $this->morphTo();
    }

    public function starred()
    {
        return $this->morphTo();
    }

    public function starreable()
    {
        return $this->morphTo();
    }
}
