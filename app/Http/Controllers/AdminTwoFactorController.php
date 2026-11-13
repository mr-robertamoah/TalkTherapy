<?php

namespace App\Http\Controllers;

use App\Actions\TwoFactor\ResetTwoFactorByAdminAction;
use App\Http\Resources\AdminUserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

// SCRUM-34c: a super administrator resets someone's two-factor sign-in (the admin users list). Asks for the
// administrator's own password again -- and their own code, when they have two-factor on. Not behind the feature
// switch: it can only turn two-factor off.
class AdminTwoFactorController extends Controller
{
    public function reset(Request $request): JsonResponse
    {
        // Who may, before anything is checked (the action checks again).
        abort_unless($request->user()->isSuperAdmin(), 403);

        $validated = $request->validate([
            'current_password' => ['required', 'string', 'current_password'],
            'code' => [$request->user()->hasTwoFactorEnabled() ? 'required' : 'nullable', 'string', 'max:20'],
        ]);
        $user = User::query()->findOrFail($request->route('userId'));

        ResetTwoFactorByAdminAction::new()->execute($request->user(), $user, $validated['code'] ?? null, $request->ip());

        return response()->json(['user' => new AdminUserResource($user->fresh(['administrator']))]);
    }
}
