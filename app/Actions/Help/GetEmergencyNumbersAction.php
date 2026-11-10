<?php

namespace App\Actions\Help;

use App\Actions\Action;
use App\Models\EmergencyNumber;
use App\Models\User;
use App\Support\Countries;

// SCRUM-33: what the "I need help now" dialog shows -- the verified emergency numbers for a country:
// the one asked for (a known ISO code), else the person's own (users.country, a name), else the
// default (config help.default_country). Never a guessed number: a country with none returns an
// empty list, and the dialog says to contact local emergency services.
class GetEmergencyNumbersAction extends Action
{
    public function execute(?User $viewer, ?string $requestedCode = null): array
    {
        $codes = Countries::codes();
        $code = in_array($requestedCode, $codes, true) ? $requestedCode
            : (Countries::codeFor($viewer?->country) ?? config('help.default_country'));

        return [
            'country' => ['code' => $code, 'name' => Countries::nameOf($code)],
            'numbers' => EmergencyNumber::query()->forCountry($code)->get()
                ->map(fn (EmergencyNumber $number) => [
                    'id' => $number->id,
                    'label' => $number->label,
                    'number' => $number->number,
                    'notes' => $number->notes,
                    'verifiedAt' => $number->verified_at->toDateString(),
                ])->values()->all(),
        ];
    }
}
