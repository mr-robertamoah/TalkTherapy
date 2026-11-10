<?php

namespace App\Actions\Counsellor;

use App\Actions\Action;
use App\Exceptions\TherapyException;
use App\Models\Counsellor;
use App\Models\Request;
use Illuminate\Support\Facades\Log;

// SCRUM-392: a counsellor's offers to help -- therapies and groups together -- are capped per rolling
// 24 hours (config counsellor.daily_offer_cap). An offer is Request::isAssistanceOffer() (from their
// counsellor profile to a person; a group creator's invites go to counsellors, so they don't count).
// Every status counts -- withdrawn, refused, or closed because the place went -- so withdrawing and
// re-sending can't get around it.
// SCRUM-394: a hard cap -- the counsellor's row is locked before counting, and both callers run the check
// and the new request in one transaction, so offers sent at the same moment can't go over it. A refusal
// is logged, so support can see how often counsellors hit it.
// It must run inside a transaction, and the counsellor's lock (lockCounsellor) must come before any plain
// read in it: under REPEATABLE READ the first read fixes the snapshot, and the count would then miss an
// offer committed while it waited (review).
class EnsureCounsellorOfferCapAction extends Action
{
    public const REACHED = 'You have offered to help the most times allowed in a day. Please try again tomorrow.';

    public static function lockCounsellor(Counsellor $counsellor): void
    {
        Counsellor::withTrashed()->whereKey($counsellor->id)->lockForUpdate()->first(['id']);
    }

    public function execute(Counsellor $counsellor): void
    {
        self::lockCounsellor($counsellor);

        $sent = Request::query()
            ->whereAssistanceOfferFrom($counsellor)
            ->where('created_at', '>=', now()->subDay())
            ->count();

        if ($sent >= config('counsellor.daily_offer_cap')) {
            Log::info('A counsellor reached the daily cap on offers to help.', ['counsellorId' => $counsellor->id, 'offersInLastDay' => $sent]);

            throw new TherapyException(self::REACHED, 422);
        }
    }
}
