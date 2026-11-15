<?php

namespace App\Actions\Session;

use App\Actions\Action;
use App\Enums\SessionTypeEnum;
use App\Models\Session;
use Illuminate\Support\Facades\Log;

// SCRUM-447 (TT-2.9f, the user's decision on SCRUM-115): an in-person session's exact map pin is cleared a set
// period after its end time (config in_person.pin_retention_days); the written landmark is kept. Soft-deleted
// sessions too. Written straight to the table so updated_at keeps meaning "last changed by a person". Daily, in
// batches.
class ClearOldSessionPinsAction extends Action
{
    private const BATCH = 500;

    // Clearing can't be undone: a mistyped setting skips the run rather than clearing early.
    public const MINIMUM_DAYS = 1;

    // The configured period, or null when it isn't usable (the run is then skipped). The privacy policy and
    // "Your data" show this same value.
    public static function days(): ?int
    {
        $days = config('in_person.pin_retention_days');

        return is_numeric($days) && (int) $days >= self::MINIMUM_DAYS ? (int) $days : null;
    }

    public function execute(int $batch = self::BATCH): int
    {
        $days = self::days();
        if ($days === null) {
            Log::error('Session pins not cleared: SESSION_PIN_RETENTION_DAYS must be a number of at least '.self::MINIMUM_DAYS.' day.', ['configured' => config('in_person.pin_retention_days')]);

            return 0;
        }

        $before = now()->subDays($days);
        $cleared = 0;

        Session::withTrashed()
            ->select('id')
            ->where('type', SessionTypeEnum::in_person->value)
            ->where('end_time', '<', $before)
            ->where(fn ($query) => $query->whereNotNull('latitude')->orWhereNotNull('longitude'))
            ->chunkById($batch, function ($sessions) use (&$cleared) {
                // toBase(): an Eloquent update would set updated_at.
                $cleared += Session::withTrashed()->whereKey($sessions->modelKeys())->toBase()
                    ->update(['latitude' => null, 'longitude' => null]);
            });

        Log::info('Session pins cleared.', ['cleared' => $cleared, 'endedBefore' => $before->toIso8601String()]);

        return $cleared;
    }
}
