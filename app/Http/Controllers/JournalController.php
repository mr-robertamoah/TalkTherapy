<?php

namespace App\Http\Controllers;

use App\Actions\User\ConfirmItsYouAction;
use App\Models\JournalEntry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

// SCRUM-36 (TT-5.1): the signed-in person's private journal -- always their own (no user in any route); another
// person's entry is a 404, administrators included. Entries go out only on this page, never in shared props.
class JournalController extends Controller
{
    private const PER_PAGE = 15;

    public function index(Request $request): Response
    {
        // The entries themselves are fetched by the page (entries()), never sent as a prop (code review): Back would show
        // the page as it was, deleted entries included.
        return Inertia::render('Profile/Journal', [
            // SCRUM-38: what the export asks for (ConfirmItsYouAction).
            'exportGate' => [
                ...ConfirmItsYouAction::new()->requirements($request->user()),
                'maxEntries' => (int) config('limits.journal_export_max_entries'),
            ],
            'limits' => [
                'content' => (int) config('limits.journal_entry_max_length'),
                'title' => (int) config('limits.journal_title_max_length'),
            ],
        ]);
    }

    // The page's entries, a page at a time. Never stored by the browser (security review: they're decrypted).
    public function entries(Request $request): JsonResponse
    {
        return $this->private(response()->json($this->page($request)));
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $this->validated($request);

        if ($request->user()->journalEntries()->count() >= (int) config('limits.journal_max_entries')) {
            throw ValidationException::withMessages(['form' => 'Your journal is full. Delete some older entries to add new ones.']);
        }

        return $this->private(response()->json(['entry' => $this->present($request->user()->journalEntries()->create($validated))], 201));
    }

    public function update(Request $request, string $entry): JsonResponse
    {
        $found = $request->user()->journalEntries()->findOrFail($entry);
        $found->update($this->validated($request));

        return $this->private(response()->json(['entry' => $this->present($found)]));
    }

    public function destroy(Request $request, string $entry): HttpResponse
    {
        $request->user()->journalEntries()->findOrFail($entry)->delete();

        return response()->noContent();
    }

    // Every entry, for good -- the page asks first.
    public function destroyAll(Request $request): HttpResponse
    {
        $request->user()->journalEntries()->delete();

        return response()->noContent();
    }

    private function page(Request $request): array
    {
        $page = $request->user()->journalEntries()
            ->orderByDesc('written_on')->orderByDesc('id')
            ->cursorPaginate(self::PER_PAGE);

        return [
            'data' => collect($page->items())->map(fn (JournalEntry $entry) => $this->present($entry))->all(),
            'nextCursor' => $page->nextCursor()?->encode(),
        ];
    }

    private function private(JsonResponse $response): JsonResponse
    {
        return $response->header('Cache-Control', 'no-store, private');
    }

    private function validated(Request $request): array
    {
        // Up to tomorrow (code review): the date is the person's own day, and ahead of UTC their "today" is already
        // tomorrow here for a few hours. Titles arrive trimmed, an empty one as null (the global middleware).
        return $request->validate([
            'title' => ['nullable', 'string', 'max:'.(int) config('limits.journal_title_max_length')],
            'content' => ['required', 'string', 'max:'.(int) config('limits.journal_entry_max_length')],
            'written_on' => ['required', 'date_format:Y-m-d', 'after_or_equal:1900-01-01', 'before_or_equal:tomorrow'],
        ], [
            'written_on.before_or_equal' => 'The date can\'t be in the future.',
            'written_on.after_or_equal' => 'Please choose a real date.',
        ]);
    }

    private function present(JournalEntry $entry): array
    {
        return [
            'id' => $entry->id,
            'title' => $entry->title,
            'content' => $entry->content,
            'writtenOn' => $entry->written_on->toDateString(),
            'createdAt' => $entry->created_at?->toIso8601String(),
            'updatedAt' => $entry->updated_at?->toIso8601String(),
        ];
    }
}
