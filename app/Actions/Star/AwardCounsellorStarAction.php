<?php

namespace App\Actions\Star;

use App\Actions\Action;
use App\Actions\Administrator\GetSuperAdministratorAction;
use App\Enums\StarSourceEnum;
use App\Models\Counsellor;
use App\Models\Star;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;

// TT-7.11a/SCRUM-470: the one writer of new-rule stars. A star always goes on the Counsellor (never their User), with
// the rule that earned it and an award key naming the earning event. Each counsellor holds at most one star per key
// (unique on starred_type, starred_id, award_key), so every counsellor of a group session can be starred for it, and
// awarding the same counsellor the same key again is a no-op -- the index decides, so concurrent awards cannot both
// insert. Returns the new star, or null when this counsellor already has it.
class AwardCounsellorStarAction extends Action
{
    public function execute(
        Counsellor $counsellor,
        StarSourceEnum $source,
        Model $starreable,
        string $awardKey,
        ?User $by = null,
    ): ?Star {
        $star = (new Star)->forceFill([
            'type' => $source->starType()->value,
            'source' => $source,
            'award_key' => $awardKey,
        ]);

        $star->starred()->associate($counsellor);
        $star->starreable()->associate($starreable);
        // As CreateStarAction: a star nobody in particular gave is the platform's (the super administrator).
        $by = $by ?? GetSuperAdministratorAction::new()->execute();
        if ($by) {
            $star->starredby()->associate($by);
        }

        try {
            $star->save();
        } catch (UniqueConstraintViolationException) {
            return null;
        }

        return $star;
    }
}
