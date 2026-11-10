<?php

namespace App\Actions\Help;

use App\Actions\Action;
use App\Exceptions\HelpException;
use App\Models\EmergencyNumber;
use App\Models\EmergencyNumberEvent;
use App\Models\User;
use Illuminate\Support\Facades\DB;

// SCRUM-33: a super administrator removes an emergency number (e.g. it stopped working). Its last
// values are kept in the history.
class DeleteEmergencyNumberAction extends Action
{
    public function execute(?User $actor, ?EmergencyNumber $number): void
    {
        EnsureCanManageEmergencyNumbersAction::new()->execute($actor);

        if (! $number) {
            throw new HelpException('That emergency number no longer exists.', 404);
        }

        DB::transaction(function () use ($actor, $number) {
            EmergencyNumberEvent::query()->create([
                'emergency_number_id' => $number->id,
                'action' => 'deleted',
                'actor_user_id' => $actor->id,
                'values' => $number->only(['country_code', 'label', 'number', 'notes', 'source_url', 'verified_by', 'verified_at', 'position']),
            ]);
            $number->delete();
        });
    }
}
