<?php

namespace App\Http\Controllers;

use App\Actions\TwoFactor\CancelTwoFactorResetAction;
use App\Actions\TwoFactor\ResetTwoFactorByAdminAction;
use App\Http\Resources\AdminUserResource;
use App\Models\TwoFactorReset;
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
            // SCRUM-424: a ticket number or short note -- kept with the reset, never shown to the person.
            'reason' => ['nullable', 'string', 'max:255'],
        ]);
        $user = User::query()->findOrFail($request->route('userId'));

        ResetTwoFactorByAdminAction::new()->execute($request->user(), $user, $validated['code'] ?? null, $request->ip(), $validated['reason'] ?? null);

        return response()->json(['user' => new AdminUserResource($user->fresh(['administrator', 'pendingTwoFactorReset']))]);
    }

    // SCRUM-424: a super administrator stops a scheduled reset (only ever makes the account safer -- no password asked).
    public function cancel(Request $request): JsonResponse
    {
        abort_unless($request->user()->isSuperAdmin(), 403);

        $user = User::query()->findOrFail($request->route('userId'));
        $pending = $user->pendingTwoFactorReset()->first();

        // Security review: resets scheduled on the server (administrators' accounts, accounts without a verified email)
        // are cancelled there too -- one taken-over super admin mustn't keep blocking them.
        if ($pending && ($pending->source === TwoFactorReset::SOURCE_SERVER || $user->isAdmin())) {
            return response()->json(['message' => 'This reset was scheduled on the server; cancel it there.'], 422);
        }

        if (! $pending || ! CancelTwoFactorResetAction::new()->execute($pending, TwoFactorReset::CANCELLED_BY_ADMIN, $request->user())) {
            return response()->json(['message' => 'There is no scheduled reset for this account.'], 422);
        }

        return response()->json(['user' => new AdminUserResource($user->fresh(['administrator', 'pendingTwoFactorReset']))]);
    }
}
