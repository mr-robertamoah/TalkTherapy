<?php

namespace App\Http\Controllers\Auth;

use App\Actions\TwoFactor\ConfirmTwoFactorAction;
use App\Actions\TwoFactor\DisableTwoFactorAction;
use App\Actions\TwoFactor\RegenerateRecoveryCodesAction;
use App\Actions\TwoFactor\StartTwoFactorSetupAction;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

// SCRUM-34: setting up and managing two-factor sign-in from the profile (JSON; a 404 while switched off). Anything that
// shows a secret or codes, or removes the protection, asks for the password again -- and, once on, a code too.
class TwoFactorController extends Controller
{
    public function setup(Request $request): JsonResponse
    {
        $request->validate(['current_password' => ['required', 'string', 'current_password']]);

        return response()->json(StartTwoFactorSetupAction::new()->execute($request->user(), $request->session()));
    }

    public function confirm(Request $request): JsonResponse
    {
        $validated = $request->validate(['code' => ['required', 'string', 'max:20']]);

        return response()->json(['recoveryCodes' => ConfirmTwoFactorAction::new()->execute($request->user(), $request->session(), $validated['code'])]);
    }

    public function recoveryCodes(Request $request): JsonResponse
    {
        $validated = $this->validatePasswordAndCode($request);

        return response()->json(['recoveryCodes' => RegenerateRecoveryCodesAction::new()->execute($request->user(), $validated['code'])]);
    }

    public function destroy(Request $request): Response
    {
        $validated = $this->validatePasswordAndCode($request);

        DisableTwoFactorAction::new()->execute($request->user(), $validated['code']);

        return response()->noContent();
    }

    private function validatePasswordAndCode(Request $request): array
    {
        return $request->validate([
            'current_password' => ['required', 'string', 'current_password'],
            'code' => ['required', 'string', 'max:20'],
        ]);
    }
}
