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
    public function byAwardKey(string $awardKey): int
    {
        return Star::query()
            ->where('award_key', $awardKey)
            ->whereNotNull('source')
            ->delete();
    }

    // Every star the rule gave for this thing, whatever its award key (a bulk revoke).
    public function forStarreable(StarSourceEnum $source, Model $starreable): int
    {
        return Star::query()
            ->where('starred_type', Counsellor::class)
            ->where('source', $source->value)
            ->where('starreable_type', $starreable->getMorphClass())
            ->where('starreable_id', $starreable->getKey())
            ->delete();
    }
}
