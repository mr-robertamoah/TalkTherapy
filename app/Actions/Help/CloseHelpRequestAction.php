<?php

namespace App\Actions\Help;

use App\Actions\Action;
use App\Enums\HelpRequestStatusEnum;
use App\Enums\SessionStatusEnum;
use App\Enums\TherapyStatusEnum;
use App\Models\HelpRequest;
use App\Support\HelpBroadcast;
use Illuminate\Support\Facades\DB;

// SCRUM-33: moves a help request from $from to a final $to in one conditional update (so nothing
// that happened at the same moment is overwritten) and frees the person to ask again. A connected
// chat's session and therapy end with it, and any call in it is ended. Returns whether it moved.
class CloseHelpRequestAction extends Action
{
    // $extra: more columns to set in the same update (an administrator's close: who, and the note).
    public function execute(HelpRequest $request, HelpRequestStatusEnum $from, HelpRequestStatusEnum $to, string $endedBy, array $extra = []): bool
    {
        $moved = DB::transaction(function () use ($request, $from, $to, $endedBy, $extra) {
            $moved = HelpRequest::query()->whereKey($request->id)->where('status', $from->value)->update([
                ...$extra, 'status' => $to->value, 'active_user_id' => null, 'ended_at' => now(), 'ended_by' => $endedBy, 'updated_at' => now(),
            ]);

            if ($moved && $from === HelpRequestStatusEnum::connected) {
                $request->refresh();
                $request->session?->forceFill(['status' => SessionStatusEnum::held->value, 'ended_at' => now()])->save();
                $request->therapy?->forceFill(['status' => TherapyStatusEnum::ended->value])->save();
            }

            return $moved > 0;
        });

        if ($moved) {
            HelpBroadcast::queueChanged($request->id);
        }

        if ($moved && $from === HelpRequestStatusEnum::connected && $request->session) {
            EndHelpCallAction::new()->execute($request->session);
        }

        return $moved;
    }
}
