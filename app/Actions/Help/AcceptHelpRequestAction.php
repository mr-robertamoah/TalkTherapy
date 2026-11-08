<?php

namespace App\Actions\Help;

use App\Actions\Action;
use App\Enums\HelpRequestStatusEnum;
use App\Events\HelpRequestUpdatedEvent;
use App\Exceptions\HelpException;
use App\Models\Counsellor;
use App\Models\HelpRequest;
use App\Models\User;
use App\Support\HelpBroadcast;
use Illuminate\Support\Facades\DB;

// SCRUM-33: an on-call counsellor answers a help request. The first to accept wins: the status moves
// from waiting to connected in one conditional update, so a second accept (or a cancel or expiry at
// the same moment) changes nothing and is told so. Winning creates the help chat (CreateHelpChatAction)
// in the same transaction; every other ring for it closes.
class AcceptHelpRequestAction extends Action
{
    public const TAKEN = 'Someone else has already answered, or it is no longer waiting.';

    public const NOT_AVAILABLE = 'You can answer urgent help requests while you are available for urgent help, verified, and not in another help chat.';

    public function execute(User $user, ?HelpRequest $request): HelpRequest
    {
        $counsellor = $user->counsellor;

        // Their own state first, so a refusal never tells whether a request exists (security review).
        if (! $counsellor || ! $counsellor->isAvailableForUrgentHelp() || ! $counsellor->isVerified() || $counsellor->isSuspended()) {
            throw new HelpException(self::NOT_AVAILABLE, 422);
        }

        // Only a counsellor this request would ring (never the person, a guardian, nor one already in a
        // help chat) -- the same answer whether it's missing, taken or not theirs to answer.
        if (! $request || ! in_array((int) $counsellor->id, RingAvailableCounsellorsAction::ringableFor($request), true)) {
            throw new HelpException(self::TAKEN, 409);
        }

        // Before the update, so the counsellors whose ring this closes are still "available".
        $others = array_values(array_diff(RingAvailableCounsellorsAction::ringableFor($request), [$counsellor->id]));

        DB::transaction(function () use ($request, $counsellor) {
            // Their own row locked, so two accepts of different requests can't both make them busy.
            $locked = Counsellor::query()->lockForUpdate()->find($counsellor->id);
            if ($locked->helpRequests()->where('status', HelpRequestStatusEnum::connected->value)->exists()) {
                throw new HelpException(self::NOT_AVAILABLE, 422);
            }

            $won = HelpRequest::query()->whereKey($request->id)
                ->where('status', HelpRequestStatusEnum::waiting->value)
                ->update(['status' => HelpRequestStatusEnum::connected->value, 'counsellor_id' => $counsellor->id, 'accepted_at' => now(), 'updated_at' => now()]);

            if ($won === 0) {
                throw new HelpException(self::TAKEN, 409);
            }

            CreateHelpChatAction::new()->execute($request->refresh(), $counsellor);
        });

        HelpBroadcast::send(new HelpRequestUpdatedEvent($request->id, HelpRequestStatusEnum::connected->value, $others));

        return $request->refresh();
    }
}
