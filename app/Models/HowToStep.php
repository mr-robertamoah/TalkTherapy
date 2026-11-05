<?php

namespace App\Models;

use App\Enums\FileTagEnum;
use App\Traits\HasTaggedFiles;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphToMany;

class HowToStep extends Model
{
    use HasFactory, HasTaggedFiles;

    protected $fillable = ['name', 'description', 'user_id', 'how_to_id', 'position', 'element_id'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    // SCRUM-194: off the old file_id column, onto the shared tagged fileables pivot -- the same shape
    // as Counsellor::avatarFile() (TT-10.2) and User::avatarFile() (TT-10.6). withPivotValue also
    // writes the tag on attach()/sync().
    public function stepFile(): MorphToMany
    {
        return $this->taggedFile(FileTagEnum::step);
    }

    // What the rest of the app reads ($step->file), keeping the old File|null contract.
    public function getFileAttribute(): ?File
    {
        return $this->stepFile->first();
    }

    public function howTo()
    {
        return $this->belongsTo(HowTo::class);
    }

    public function scopeWhereNotIds($query, array $ids)
    {
        return $query->whereNotIn('id', $ids);
    }
}
