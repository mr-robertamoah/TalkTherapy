<?php

namespace App\Http\Controllers;

use App\Actions\Help\GetEmergencyNumbersAction;
use Illuminate\Http\Request;

// SCRUM-33: "I need help now". The emergency numbers are open to everyone, signed in or not,
// suspended or not (EnsureAccountIsNotSuspended lets help routes through).
class HelpController extends Controller
{
    public function emergencyNumbers(Request $request)
    {
        $code = $request->query('country');

        return response()->json(GetEmergencyNumbersAction::new()->execute(
            $request->user(),
            is_string($code) ? strtoupper($code) : null,
        ));
    }
}
