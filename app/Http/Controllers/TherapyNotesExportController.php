<?php

namespace App\Http\Controllers;

use App\Actions\SessionNote\GetOwnTherapyNotesAction;
use App\Actions\Suspension\EnsureCanActAsCounsellorAction;
use App\Actions\User\ConfirmItsYouAction;
use App\Actions\User\RunConfirmedExportAction;
use App\Exceptions\SessionNoteException;
use App\Models\GroupTherapy;
use App\Models\Therapy;
use App\Models\User;
use App\Notifications\TherapyNotesExportedNotification;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

// SCRUM-438 (the user's decision: yes, with safeguards): a counsellor takes the notes they wrote on one therapy off the
// platform as a PDF -- only while they're its counsellor (an ended therapy included; a deleted one not, as notes
// aren't viewable there either), never a suspended counsellor, never an urgent help chat,
// never a co-counsellor's notes, never the client's words; an anonymous client stays anonymous; no names in the file
// name. The journal export's gate (RunConfirmedExportAction): password and two-factor code, one at a time, 3 an hour,
// and the account told once the file exists. dompdf locked down as for the journal (config/dompdf.php).
class TherapyNotesExportController extends Controller
{
    public function forTherapy(Request $request, int $therapyId): Response
    {
        return $this->export($request, Therapy::query()->find($therapyId));
    }

    public function forGroup(Request $request, int $groupTherapyId): Response
    {
        return $this->export($request, GroupTherapy::query()->find($groupTherapyId));
    }

    private function export(Request $request, Therapy|GroupTherapy|null $therapy): Response
    {
        $user = $request->user();
        $counsellor = $user->counsellor;

        if (! $therapy || ! $counsellor || ($therapy instanceof Therapy && $therapy->isHelpChat()) || ! $therapy->isCounsellor($counsellor)) {
            throw new SessionNoteException('There is nothing to export here.', 404);
        }

        // Security review: a counsellor whose role is suspended can't take notes off the platform (the safer option).
        EnsureCanActAsCounsellorAction::new()->execute($counsellor);

        $validated = $request->validate(ConfirmItsYouAction::new()->rules($user));
        $export = RunConfirmedExportAction::new();

        $pdf = $export->execute($user, $validated, 'your notes', function () use ($counsellor, $therapy, $user) {
            $sessions = GetOwnTherapyNotesAction::new()->execute($counsellor, $therapy);
            set_time_limit(60);

            return [
                'count' => $sessions->sum(fn ($s) => count($s['notes'])),
                'file' => Pdf::loadView('pdf.therapy-notes', [
                    'counsellor' => $user->name ?: $user->username,
                    'therapy' => $therapy->name,
                    'client' => $therapy instanceof Therapy && $therapy->addedby instanceof User ? $therapy->displayNameOf($therapy->addedby) : null,
                    'isGroup' => $therapy instanceof GroupTherapy,
                    'exportedOn' => now()->toFormattedDateString(),
                    'sessions' => $sessions,
                ])->setPaper('a4')->output(),
            ];
        });

        Log::notice('Therapy notes exported', ['user_id' => $user->id, 'therapy_type' => $therapy->getMorphClass(), 'therapy_id' => $therapy->id, 'notes' => $pdf['count']]);
        $export->tell($user, new TherapyNotesExportedNotification(now()->toDayDateTimeString().' (UTC)'));

        return response($pdf['file'], 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="talktherapy-notes-'.($therapy instanceof GroupTherapy ? 'group-' : '').$therapy->id.'-'.now()->toDateString().'.pdf"',
            'Cache-Control' => 'no-store, private',
        ]);
    }
}
