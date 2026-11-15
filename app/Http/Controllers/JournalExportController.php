<?php

namespace App\Http\Controllers;

use App\Actions\User\ConfirmItsYouAction;
use App\Actions\User\RunConfirmedExportAction;
use App\Models\JournalEntry;
use App\Models\User;
use App\Notifications\JournalExportedNotification;
use App\Support\PdfTextWeight;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

// SCRUM-38 (TT-5.3): the signed-in person's journal as a PDF -- their own entries only (no user in the route), all of
// them or a date range, oldest first. Behind ConfirmItsYouAction, as "Download a copy" is. Made in memory and sent at
// once: never stored, never emailed. dompdf runs with no network, scripts or PHP and only its own template folder
// (config/dompdf.php); every value in the template is escaped. Capped by entries and by characters (dompdf takes about
// half a second per 10,000), one at a time per person, and the account is told only once the file exists.
class JournalExportController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $user = $request->user();

        $validated = $request->validate([
            ...ConfirmItsYouAction::new()->rules($user),
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);

        $export = RunConfirmedExportAction::new();
        $pdf = $export->execute($user, $validated, 'your journal', fn () => $this->render($user, $validated));

        // Only now that the file exists (code review).
        Log::notice('Journal exported', ['user_id' => $user->id, 'entries' => $pdf['entries']]);
        $export->tell($user, new JournalExportedNotification(now()->toDayDateTimeString().' (UTC)'));

        return response($pdf['file'], 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="talktherapy-journal-'.now()->toDateString().'.pdf"',
            'Cache-Control' => 'no-store, private',
        ]);
    }

    // How much laying out an entry costs dompdf, in characters: each line break costs about as much as 100 characters of
    // prose (devops review, measured -- 15 entries of bare line breaks took 50 s), so the cap counts them that way.
    public static function weight(JournalEntry $entry): int
    {
        return PdfTextWeight::of($entry->content, $entry->title);
    }

    /**
     * @return array{file: string, entries: int}
     */
    private function render(User $user, array $validated): array
    {
        $maxEntries = (int) config('limits.journal_export_max_entries');
        // whereDate (code review): the column's stored form differs between databases; the range takes both ends.
        $entries = $user->journalEntries()
            ->when($validated['from'] ?? null, fn ($q, $from) => $q->whereDate('written_on', '>=', $from))
            ->when($validated['to'] ?? null, fn ($q, $to) => $q->whereDate('written_on', '<=', $to))
            ->orderBy('written_on')->orderBy('id')
            ->limit($maxEntries + 1)
            ->get();

        if ($entries->isEmpty()) {
            throw ValidationException::withMessages(['form' => 'There are no entries to export'.(($validated['from'] ?? $validated['to'] ?? null) ? ' in those dates.' : ' yet.')]);
        }

        if ($entries->count() > $maxEntries || $entries->sum(fn (JournalEntry $entry) => self::weight($entry)) > (int) config('limits.journal_export_max_characters')) {
            throw ValidationException::withMessages(['form' => 'That is too much for one PDF. Please choose a shorter date range.']);
        }

        set_time_limit(60);

        return [
            'file' => Pdf::loadView('pdf.journal', [
                'name' => $user->name ?: $user->username,
                'exportedOn' => now()->toFormattedDateString(),
                'from' => $validated['from'] ?? null,
                'to' => $validated['to'] ?? null,
                'entries' => $entries->map(fn (JournalEntry $entry) => [
                    'title' => $entry->title,
                    'content' => $entry->content,
                    'writtenOn' => $entry->written_on->format('l, j F Y'),
                ]),
            ])->setPaper('a4')->output(),
            'entries' => $entries->count(),
        ];
    }
}
