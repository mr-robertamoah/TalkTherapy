<?php

namespace App\Actions\User;

use App\Actions\Action;
use App\Models\User;
use Closure;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification as Notifications;
use Illuminate\Validation\ValidationException;

// SCRUM-438 (shared with SCRUM-38): taking something of theirs off the platform as a PDF -- one at a time per person
// (any PDF: it's the CPU being protected), the lock taken before the code is checked so a second request can't use up a
// one-time code; then ConfirmItsYouAction (password, and the two-factor code when it's on); then $make builds the file.
// Nothing about the content is said before the check passes. tell() is the alert, sent only once the file exists.
class RunConfirmedExportAction extends Action
{
    public function execute(User $user, array $validated, string $what, Closure $make): mixed
    {
        $lock = Cache::lock('pdf-export:'.$user->id, 120);

        if (! $lock->get()) {
            throw ValidationException::withMessages(['form' => 'Your PDF is already being made. Please wait a moment.']);
        }

        try {
            ConfirmItsYouAction::new()->execute($user, $validated, $what);

            return $make();
        } finally {
            $lock->release();
        }
    }

    // The account is told -- and, after a recent email change, the address it replaced (it may be the owner's).
    public function tell(User $user, Notification $notification): void
    {
        $user->notify($notification);

        if ($previous = $user->recentlyReplacedEmail()) {
            Notifications::route('mail', $previous)->notify($notification);
        }
    }
}
