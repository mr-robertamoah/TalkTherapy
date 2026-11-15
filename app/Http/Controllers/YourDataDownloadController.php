<?php

namespace App\Http\Controllers;

use App\Actions\User\ConfirmItsYouAction;
use App\Actions\User\ExportYourDataAction;
use App\Notifications\DataCopyDownloadedNotification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Symfony\Component\HttpFoundation\StreamedResponse;

// SCRUM-432: download a copy of your data -- always the signed-in person's own (no user in the route), after
// ConfirmItsYouAction (password, and the two-factor code when it's on). The account is told, by email too when its
// address is verified. Nothing is stored: the file is written as it downloads.
class YourDataDownloadController extends Controller
{
    public function __invoke(Request $request): StreamedResponse
    {
        $user = $request->user();

        $gate = ConfirmItsYouAction::new();
        $gate->execute($user, $request->validate($gate->rules($user)), 'a copy of your data');

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
