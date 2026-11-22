<?php

namespace App\Actions\Star;

use App\Actions\Action;
use App\Enums\StarSourceEnum;
use App\Models\Counsellor;
use App\Models\Star;
use Illuminate\Database\Eloquent\Model;

// TT-7.11a/SCRUM-470: takes back new-rule stars (e.g. a refunded session's). The row is deleted, so the same award key
// can be awarded again later -- an event can earn its star again, but never hold two at once. Legacy stars (NULL
// source) are never touched. Each returns how many stars were removed.
class RevokeCounsellorStarsAction extends Action
{
    public function forCounsellorAndKey(Counsellor $counsellor, string $awardKey): int
    {
        return Star::query()
            ->where('starred_type', $counsellor->getMorphClass())
            ->where('starred_id', $counsellor->getKey())
            ->where('award_key', $awardKey)
            ->whereNotNull('source')
            ->delete();
    }

    // Every star the rule gave for this thing, to any counsellor, whatever its award key (a bulk revoke).
    public function forStarreable(StarSourceEnum $source, Model $starreable): int
    {
        return $this->forStarreables($source, $starreable->getMorphClass(), [$starreable->getKey()]);
    }

    // SCRUM-471: the same for many things of one type, in one DELETE (e.g. every session a refunded payment covered).
    public function forStarreables(StarSourceEnum $source, string $starreableType, array $starreableIds): int
    {
        if ($starreableIds === []) {
            return 0;
        }

        return Star::query()
            ->where('starred_type', (new Counsellor)->getMorphClass())
            ->where('source', $source->value)
            ->where('starreable_type', $starreableType)
            ->whereIn('starreable_id', $starreableIds)
            ->delete();
    }
}
