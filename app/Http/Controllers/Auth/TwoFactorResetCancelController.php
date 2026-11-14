<?php

namespace App\Http\Controllers\Auth;

use App\Actions\TwoFactor\CancelTwoFactorResetAction;
use App\Http\Controllers\Controller;
use App\Models\TwoFactorReset;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

// SCRUM-424: stopping a reset of two-factor sign-in the team scheduled -- from the signed link emailed to the verified
// address (no sign-in: show the button, the button cancels), or from the profile (signed in).
class TwoFactorResetCancelController extends Controller
{
    public function show(Request $request): SymfonyResponse
    {
        $reset = TwoFactorReset::query()->findOrFail($request->route('reset'));

        return Inertia::render('Auth/CancelTwoFactorReset', [
            'state' => $reset->applied_at ? 'applied' : ($reset->cancelled_at ? 'cancelled' : 'pending'),
            'dueAt' => $reset->due_at->toIso8601String(),
            // The same signed address, for the button's POST.
            'action' => $request->fullUrl(),
        ])->toResponse($request)->withHeaders(['Referrer-Policy' => 'no-referrer']); // security review: the signed link stays here
    }

    public function store(Request $request): RedirectResponse
    {
        $reset = TwoFactorReset::query()->findOrFail($request->route('reset'));

        CancelTwoFactorResetAction::new()->execute($reset, TwoFactorReset::CANCELLED_BY_LINK);

        // Back to the page itself (the same signed address), not wherever the Referer says.
        return redirect()->to($request->fullUrl(), 303);
    }

    public function destroy(Request $request): JsonResponse
    {
        if (! CancelTwoFactorResetAction::new()->forUser($request->user(), TwoFactorReset::CANCELLED_FROM_PROFILE, $request->user())) {
            return response()->json(['message' => 'There is no scheduled reset to cancel.'], 422);
        }

        return response()->json(['cancelled' => true]);
    }
}
