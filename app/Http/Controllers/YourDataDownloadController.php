<?php

namespace App\Http\Controllers;

use App\Actions\TwoFactor\VerifyTwoFactorCodeAction;
use App\Actions\User\ExportYourDataAction;
use App\Exceptions\TwoFactorException;
use App\Notifications\DataCopyDownloadedNotification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

// SCRUM-432: download a copy of your data -- always the signed-in person's own (no user in the route). Their password
// first, and their two-factor code when it's on (the user's decision): a session left open isn't enough. Not while a
// new email is waiting (the moment an intruder would want it). The account is told, by email too when its address is
// verified. Nothing is stored: the file is written as it downloads.
class YourDataDownloadController extends Controller
{
    public function __invoke(Request $request): StreamedResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'current_password' => ['required', 'string'],
            'code' => [$user->hasTwoFactorEnabled() ? 'required' : 'nullable', 'string', 'max:20'],
        ]);

        if ($user->pending_email) {
            throw ValidationException::withMessages(['form' => 'A new email address is waiting on your account. You can download a copy of your data once that change is finished or stopped.']);
        }

        $passwordOk = Hash::check($validated['current_password'], (string) $user->getAuthPassword());

        if (! $user->hasTwoFactorEnabled()) {
            if (! $passwordOk) {
                throw ValidationException::withMessages(['current_password' => __('validation.current_password')]);
            }
        } else {
            // Security review: one answer for a wrong password or a wrong code, so the code step doesn't confirm the
            // password. A code is only checked (and used up) after the right password.
            try {
                $used = $passwordOk && VerifyTwoFactorCodeAction::new()->execute($user, $validated['code']);
            } catch (TwoFactorException $exception) {
                throw ValidationException::withMessages(['form' => $exception->getMessage()]);
            }

            if (! $used) {
                throw ValidationException::withMessages(['form' => 'That password or code didn\'t work. Try again with the newest code from your app, or a recovery code.']);
            }
        }

        Log::notice('Data copy downloaded', ['user_id' => $user->id]);
        $notification = new DataCopyDownloadedNotification(now()->toDayDateTimeString().' (UTC)');
        $user->notify($notification);
        // Security review: after a recent email change, the address it replaced hears too -- it may be the owner's.
        if ($previous = $user->recentlyReplacedEmail()) {
            Notification::route('mail', $previous)->notify($notification);
        }

        return response()->streamDownload(function () use ($user) {
            // A long history mustn't hit the CPU limit halfway (devops review); the throttle bounds the cost.
            set_time_limit(300);

            try {
                foreach (ExportYourDataAction::new()->execute($user) as $piece) {
                    echo $piece;
                    flush();
                }
            } catch (\Throwable $exception) {
                // The headers are out: the person has a cut-off file. Logged so "downloaded" isn't read as finished.
                Log::error('Data copy failed while writing', ['user_id' => $user->id, 'error' => $exception::class]);

                throw $exception;
            }
        }, 'talktherapy-your-data-'.now()->toDateString().'.json', [
            'Content-Type' => 'application/json; charset=UTF-8',
            'Cache-Control' => 'no-store, private',
            'X-Accel-Buffering' => 'no',
        ]);
    }
}
