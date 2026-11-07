<?php

namespace App\Actions\Counsellor;

use App\Actions\Action;
use App\Exceptions\TherapyException;
use App\Models\Counsellor;
use App\Models\Request;

// SCRUM-392: a counsellor's offers to help -- therapies and groups together -- are capped per rolling
// 24 hours (config counsellor.daily_offer_cap). An offer is Request::isAssistanceOffer() (from their
// counsellor profile to a person; a group creator's invites go to counsellors, so they don't count).
// Every status counts -- withdrawn, refused, or closed because the place went -- so withdrawing and
// re-sending can't get around it.
class EnsureCounsellorOfferCapAction extends Action
{
    public const REACHED = 'You have offered to help the most times allowed in a day. Please try again tomorrow.';

    public function execute(Counsellor $counsellor): void
    {
        $sent = Request::query()
            ->whereAssistanceOfferFrom($counsellor)
            ->where('created_at', '>=', now()->subDay())
            ->count();

        if ($sent >= config('counsellor.daily_offer_cap')) {
            throw new TherapyException(self::REACHED, 422);
        }
    }
}
