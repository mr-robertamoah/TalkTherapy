<?php

namespace App\Traits;

use App\Enums\FileTagEnum;
use App\Models\File;
use Illuminate\Database\Eloquent\Relations\MorphToMany;

// SCRUM-382: a single-file slot on the shared, tagged `fileables` pivot (SCRUM-182/TT-10) -- the
// files attached under one FileTagEnum tag. Defined once for every model that has one (a user's
// avatar, a counsellor's avatar and cover, an organization's logo, a request's identity documents,
// a how-to step's file).
trait HasTaggedFiles
{
    public function taggedFile(FileTagEnum $tag): MorphToMany
    {
        return $this->morphToMany(File::class, 'fileable', 'fileables')
            ->withPivotValue('tag', $tag->value)
            ->withTimestamps();
    }
}
