<?php

namespace App\Actions\Star;

use App\Actions\Action;
use App\Models\Counsellor;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

// TT-7.11a/SCRUM-470: how many new-rule stars counsellors earned in a window, in one grouped query -- what
// recognition ranks on. Only stars on the Counsellor morph that a rule gave (source set) count; legacy stars (NULL
// source) and stars on a counsellor's User morph never do. The profile star counts are not computed here (they stay
// on their old queries until SCRUM-476).
//
// Returns a collection keyed by counsellor id of ['stars' => int, 'first_star_at' => Carbon] -- first_star_at is the
// earliest star in the window (a ranking tie-break). Counsellors with no stars in the window are absent. The window is
// half-open, [from, to); either end may be null (unbounded). $counsellorIds null means every counsellor.
// config('recognition.stars_count_from') (Y-m-d), when set, is an extra floor on the window.
class CountCounsellorStarsAction extends Action
{
    public function execute(?array $counsellorIds, ?CarbonInterface $from, ?CarbonInterface $to): Collection
    {
        if ($counsellorIds === []) {
            return collect();
        }

        if ($floor = static::countFrom()) {
            $from = $from && $from->greaterThan($floor) ? $from : $floor;
        }

        return DB::table('stars')
            ->selectRaw('starred_id as counsellor_id, count(*) as stars, min(created_at) as first_star_at')
            ->where('starred_type', (new Counsellor)->getMorphClass())
            ->whereNotNull('source')
            ->when($counsellorIds !== null, fn ($q) => $q->whereIn('starred_id', $counsellorIds))
            ->when($from, fn ($q) => $q->where('created_at', '>=', $from))
            ->when($to, fn ($q) => $q->where('created_at', '<', $to))
            ->groupBy('starred_id')
            ->get()
            ->mapWithKeys(fn ($row) => [
                (int) $row->counsellor_id => [
                    'stars' => (int) $row->stars,
                    'first_star_at' => Carbon::parse($row->first_star_at),
                ],
            ]);
    }

    // The configured floor, start of that day; null when unset. Anything but Y-m-d is ignored, with one warning per
    // request (the config is fixed for the request, so once is enough), rather than failing the count.
    public static function countFrom(): ?Carbon
    {
        $value = config('recognition.stars_count_from');

        if ($value === null || $value === '') {
            return null;
        }

        if (is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            [$year, $month, $day] = array_map('intval', explode('-', $value));

            if (checkdate($month, $day, $year)) {
                return Carbon::create($year, $month, $day);
            }
        }

        // Bound on the container, so once per request (a queue worker warns once per process).
        if (! app()->bound('recognition.invalid_count_from_warned')) {
            app()->instance('recognition.invalid_count_from_warned', true);
            Log::warning('recognition.stars_count_from is not a Y-m-d date; ignoring it.', ['value' => $value]);
        }

        return null;
    }
}
