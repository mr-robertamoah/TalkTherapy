<?php

namespace App\Http\Controllers\Auth;

use App\Actions\User\ConfirmPendingEmailAction;
use App\Actions\User\RequestEmailChangeAction;
use App\Actions\User\StopEmailChangeAction;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

// SCRUM-427: the two links of an email change, and the profile's buttons. The link to the new address confirms it; the
// one to the current address ("This wasn't me") stops it. Opening either only shows a page with the button (security
// review: mail scanners open links -- a scanner must not confirm someone else's typo). No sign-in needed for either.
class ConfirmPendingEmailController extends Controller
{
    private const MESSAGES = [
        ConfirmPendingEmailAction::CONFIRMED => 'Your new email address is confirmed.',
        ConfirmPendingEmailAction::WAITING => 'Confirmed. The new address becomes your account\'s email in a couple of days -- the current address has been told.',
        ConfirmPendingEmailAction::INVALID => 'That link is no longer valid -- the change may have been cancelled, replaced or already confirmed.',
        ConfirmPendingEmailAction::TAKEN => 'That address is now used by another account, so the change was cancelled.',
    ];

    public function showConfirm(Request $request): Response
    {
        return $this->page($request, 'confirm');
    }

    public function confirm(Request $request): RedirectResponse
    {
        $userId = (int) $request->route('id');
        $status = ConfirmPendingEmailAction::new()->execute($userId, (string) $request->route('hash'));

        if (! Auth::check()) {
            return redirect()->route('login')->with('status', self::MESSAGES[$status]);
        }

        // Opened in their own signed-in browser: carry on with the account as it is now, so this session takes the new
        // fingerprint and stays (a change the team asked for ends only the others -- code review).
        if (Auth::id() === $userId) {
            Auth::setUser(User::query()->findOrFail($userId));
        }

        return redirect()->route('profile.show')->with('status', 'email-change-'.$status);
    }

    public function showStop(Request $request): Response
    {
        return $this->page($request, 'stop');
    }

    public function stop(Request $request): RedirectResponse
    {
        $stopped = StopEmailChangeAction::new()->execute((int) $request->route('id'), (string) $request->route('hash'));

        if ($stopped && Auth::id() === (int) $request->route('id')) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return redirect()->route('login')->with('status', match ($stopped) {
            StopEmailChangeAction::STOPPED => 'The change was stopped and your account was signed out everywhere. Reset your password now (Forgot your password?).',
            // SCRUM-433: no address to send a password reset to -- the team checks it's them, then sets one.
            StopEmailChangeAction::STOPPED_WITHOUT_EMAIL => 'The change was stopped and your account was signed out everywhere. Your account has no email address to reset the password through, so please contact support to get back in.',
            default => 'That link is no longer valid -- the change may already have been stopped, or have taken effect. If it took effect, contact support.',
        });
    }

    public function resend(Request $request): JsonResponse
    {
        return RequestEmailChangeAction::new()->resend($request->user())
            ? response()->json(['sent' => true])
            : response()->json(['message' => 'There is no new address waiting for its link.'], 422);
    }

    public function cancel(Request $request): JsonResponse
    {
        return RequestEmailChangeAction::new()->cancel($request->user())
            ? response()->json(['cancelled' => true])
            : response()->json(['message' => 'There is no new address waiting to be confirmed.'], 422);
    }

    // The link's page: the button POSTs to the same signed address. The address stays on this page (no Referer out).
    private function page(Request $request, string $mode): Response
    {
        return Inertia::render('Auth/EmailChangeLink', ['mode' => $mode, 'action' => $request->fullUrl()])
            ->toResponse($request)->withHeaders(['Referrer-Policy' => 'no-referrer']);
    }
}
