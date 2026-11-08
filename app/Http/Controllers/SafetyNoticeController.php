<?php

namespace App\Http\Controllers;

use App\Actions\Safety\GetSafetyNoticeAction;
use Illuminate\Http\Request;

// SCRUM-32 (TT-4.3c): the signed-in person's persistent safety notice, and dismissing it. Open to a
// suspended account too (EnsureAccountIsNotSuspended): someone may need it most then.
class SafetyNoticeController extends Controller
{
    public function current(Request $request)
    {
        return response()->json(['notice' => GetSafetyNoticeAction::new()->current($request->user())]);
    }

    public function dismiss(Request $request, string $notice)
    {
        GetSafetyNoticeAction::new()->dismiss($request->user(), $notice);

        return response()->json(['status' => true]);
    }
}
