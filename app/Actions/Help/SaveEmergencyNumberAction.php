<?php

namespace App\Actions\Help;

use App\Actions\Action;
use App\Models\EmergencyNumber;
use App\Models\EmergencyNumberEvent;
use App\Models\User;
use Illuminate\Support\Facades\DB;

// SCRUM-33: a super administrator adds or corrects an emergency number. $values are already
// validated (AdminEmergencyNumberController). Each save is recorded, with who and the values, in
// the append-only history.
class SaveEmergencyNumberAction extends Action
{
    public function execute(?User $actor, array $values, ?EmergencyNumber $number = null): EmergencyNumber
    {
        EnsureCanManageEmergencyNumbersAction::new()->execute($actor);

        return DB::transaction(function () use ($actor, $values, $number) {
            $number ??= new EmergencyNumber;
            $creating = ! $number->exists;
            $number->fill($values);
            $number->updated_by_id = $actor->id;
            $number->save();

            EmergencyNumberEvent::query()->create([
                'emergency_number_id' => $number->id,
                'action' => $creating ? 'created' : 'updated',
                'actor_user_id' => $actor->id,
                'values' => $number->only(['country_code', 'label', 'number', 'notes', 'source_url', 'verified_by', 'verified_at', 'position']),
            ]);

            return $number;
        });
    }
}
