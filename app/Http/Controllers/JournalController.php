<?php

namespace App\Http\Controllers;

use App\Actions\User\ConfirmItsYouAction;
use App\Models\JournalEntry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

// SCRUM-36 (TT-5.1): the signed-in person's private journal -- always their own (no user in any route); another
// person's entry is a 404, administrators included. Entries go out only on this page, never in shared props.
class JournalController extends Controller
{
    private const PER_PAGE = 15;

    // SCRUM-440: day notes per page (divides into 1, 2 and 3 columns).
    public const DAYS_PER_PAGE = 12;

    private const SEARCH_MIN = 2;

    private const SEARCH_MAX = 100;

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
        // SCRUM-440: `date` -- one day's entries (the day dialog).
        $date = $request->validate(['date' => ['nullable', 'date_format:Y-m-d']])['date'] ?? null;

        return $this->private(response()->json($this->page($request, $date)));
    }

    // SCRUM-440 (the user's TODO): the journal as day notes -- one per day written, newest first, each with that day's
    // most recent entry (the newest written, MAX(id)) and how many entries it has. Numbered pages.
    public function days(Request $request): JsonResponse
    {
        $page = $request->user()->journalEntries()->toBase()
            ->select('written_on')->selectRaw('count(*) as entries, max(id) as latest_id')
            ->groupBy('written_on')->orderByDesc('written_on')
            ->paginate(self::DAYS_PER_PAGE);

        return $this->private(response()->json($this->dayNotes($request, collect($page->items())->map(fn ($day) => [
            'date' => Carbon::parse($day->written_on)->toDateString(),
            'entries' => (int) $day->entries,
            'latestId' => (int) $day->latest_id,
        ]), $page)));
    }

    // SCRUM-440: search titles and text. Entries are encrypted, so the person's own entries are read and matched here,
    // in memory, a chunk at a time (as many as the journal holds, at most journal_max_entries); nothing is stored, nothing logged, and the term
    // comes in the body (a POST) so it never lands in a URL or an access log. Results are the matching days.
    public function search(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'q' => ['required', 'string', 'min:'.self::SEARCH_MIN, 'max:'.self::SEARCH_MAX],
            'page' => ['nullable', 'integer', 'min:1', 'max:1000'],
        ]);
        set_time_limit(30);

        $term = trim($validated['q']);
        $matches = []; // date => [ids]
        $request->user()->journalEntries()->select(['id', 'title', 'content', 'written_on'])->lazyById(200)
            ->each(function (JournalEntry $entry) use ($term, &$matches) {
                if (Str::contains($entry->title."\n".$entry->content, $term, ignoreCase: true)) {
                    $matches[$entry->written_on->toDateString()][] = $entry->id;
                }
            });
        krsort($matches);

        $perPage = self::DAYS_PER_PAGE;
        // A page past the end comes back as the last one (code review), so the page never walks back a page at a time.
        $current = min((int) ($validated['page'] ?? 1), max(1, (int) ceil(count($matches) / $perPage)));
        $slice = array_slice($matches, ($current - 1) * $perPage, $perPage, true);
        // Each day's whole count (the dates' stored form differs between databases, so all days, then picked).
        $totals = $slice ? $request->user()->journalEntries()->toBase()
            ->selectRaw('written_on, count(*) as entries')->groupBy('written_on')->get()
            ->mapWithKeys(fn ($row) => [Carbon::parse($row->written_on)->toDateString() => (int) $row->entries])
            : collect();

        $paginator = new LengthAwarePaginator([], count($matches), $perPage, $current);

        return $this->private(response()->json($this->dayNotes($request, collect($slice)->map(fn (array $ids, string $date) => [
            'date' => $date,
            'entries' => $totals[$date] ?? count($ids),
            'matches' => count($ids),
            'latestId' => max($ids),
        ])->values(), $paginator)));
    }

    // The notes: each day's preview entry (its title, or the start of its text), loaded in one query.
    private function dayNotes(Request $request, Collection $days, $paginator): array
    {
        $latest = $request->user()->journalEntries()->whereKey($days->pluck('latestId'))->get()->keyBy('id');

        return [
            'data' => $days->map(function (array $day) use ($latest) {
                $entry = $latest->get($day['latestId']);

                return [
                    ...collect($day)->except('latestId')->all(),
                    'latest' => $entry ? [
                        'id' => $entry->id,
                        'title' => $entry->title,
                        'preview' => Str::limit($entry->content, 240),
                    ] : null,
                ];
            })->all(),
            'page' => $paginator->currentPage(),
            'lastPage' => $paginator->lastPage(),
            'total' => $paginator->total(),
        ];
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

    private function page(Request $request, ?string $date = null): array
    {
        $page = $request->user()->journalEntries()
            ->when($date, fn ($q) => $q->whereDate('written_on', $date))
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
