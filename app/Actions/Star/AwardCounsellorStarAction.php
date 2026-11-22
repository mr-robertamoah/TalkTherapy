<?php

namespace App\Actions\Star;

use App\Actions\Action;
use App\Actions\Administrator\GetSuperAdministratorAction;
use App\Enums\StarSourceEnum;
use App\Models\Counsellor;
use App\Models\Star;
use App\Models\User;
use App\Support\StarCapPolicy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

// TT-7.11a/SCRUM-470: the one writer of new-rule stars. A star always goes on the Counsellor (never their User), with
// the rule that earned it and an award key naming the earning event. Each counsellor holds at most one star per key
// (unique on starred_type, starred_id, award_key), so every counsellor of a group session can be starred for it, and
// awarding the same counsellor the same key again is a no-op -- the index decides, so concurrent awards cannot both
// insert. Returns the new star, or null when none was given.
//
// SCRUM-471: under the counsellor's row lock, nothing is given to a counsellor who is gone (soft-deleted), suspended
// or not currently verified, or past a daily cap (StarCapPolicy). $by is who the star came from -- for a held session
// or help chat, its client, which the per-client cap counts on; otherwise the platform (the super administrator).
class AwardCounsellorStarAction extends Action
{
    public function execute(
        Counsellor $counsellor,
        StarSourceEnum $source,
        Model $starreable,
        string $awardKey,
        ?User $by = null,
    ): ?Star {
        return DB::transaction(function () use ($counsellor, $source, $starreable, $awardKey, $by) {
            $locked = Counsellor::query()->whereKey($counsellor->getKey())->lockForUpdate()->first();

            if (! $locked || ! $locked->isVerified() || $locked->isSuspended()) {
                return null;
            }

            if (! StarCapPolicy::allows($locked, $source, $by)) {
                return null;
            }

            $star = (new Star)->forceFill([
                'type' => $source->starType()->value,
                'source' => $source,
                'award_key' => $awardKey,
            ]);

            $star->starred()->associate($locked);
            $star->starreable()->associate($starreable);
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
        });
    }
}
