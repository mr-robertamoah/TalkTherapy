<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Support\PasswordRules;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class PasswordController extends Controller
{
    /**
     * Update the user's password.
     */
    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'current_password' => ['required', 'current_password'],
            'password' => PasswordRules::for($request->user()),
        ]);

        $request->user()->forceFill([
            'password' => Hash::make($validated['password']),
            // SCRUM-35b: it has just met the whole policy, the breached-list check included.
            'password_weak_reason' => null,
            'password_checked_at' => config('auth.password_policy.uncompromised') ? now() : null,
        ])->save();

        return back();
    }
}
