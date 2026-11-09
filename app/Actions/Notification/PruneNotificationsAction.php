<?php

namespace App\Actions\Notification;

use App\Actions\Action;
use App\Models\Counsellor;
use App\Models\DatabaseNotification;
use App\Models\User;
use App\Support\NotificationCenter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;

// SCRUM-39 (TT-5.4d, the user's decision): notifications older than their owner's period are deleted for
// good -- the person's own choice (users.notification_retention_days) or the platform default
// (config notifications.retention_days). A counsellor profile's follow its owner's choice; ones whose owner
// no longer exists at all (a counsellor profile purged for good) follow the default (reviews). An unread
// safety notice is never deleted: only its own dialog dismisses it (SCRUM-32). Daily, in batches (oldest
// first, on the created_at index), one pass per period in use (the default and the profile options).
class PruneNotificationsAction extends Action
{
    private const BATCH = 1000;

    // Deleting can't be undone: a missing or mistyped default never shortens anyone's period.
    public const MINIMUM_DAYS = 7;

    // The configured default, or null when it isn't usable (the run is then skipped). The profile page and
    // the privacy policy show this same value (security review: one reading, never three).
    public static function defaultDays(): ?int
    {
        $days = config('notifications.retention_days');

        return is_numeric($days) && (int) $days >= self::MINIMUM_DAYS ? (int) $days : null;
    }

    public function execute(int $batch = self::BATCH): int
    {
        $default = self::defaultDays();
        if ($default === null) {
            Log::error('Notifications not pruned: NOTIFICATIONS_RETENTION_DAYS must be a number of at least '.self::MINIMUM_DAYS.' days.', ['configured' => config('notifications.retention_days')]);

            return 0;
        }

        $periods = User::withTrashed()->whereNotNull('notification_retention_days')->distinct()
            ->pluck('notification_retention_days')->map(fn ($days) => (int) $days)
            ->push($default)->unique()->values();

        $deleted = [];
        foreach ($periods as $days) {
            $deleted[$days] = $this->forOwnersKeeping($days, $days === $default, $batch);
        }
        $orphaned = $this->delete($this->ownerless($default), $batch);

        // A record of every permanent deletion run.
        Log::info('Notifications pruned.', ['deletedByDays' => $deleted, 'ownerless' => $orphaned, 'defaultDays' => $default]);

        return array_sum($deleted) + $orphaned;
    }

    private function forOwnersKeeping(int $days, bool $isDefault, int $batch): int
    {
        $owners = User::withTrashed()->select('id')->where(fn (Builder $query) => $isDefault
            ? $query->whereNull('notification_retention_days')->orWhere('notification_retention_days', $days)
            : $query->where('notification_retention_days', $days));

        // One pass per owner type, so each can use its (notifiable_type, notifiable_id) index (devops review).
        return $this->delete($this->older($days)->where('notifiable_type', (new User)->getMorphClass())
            ->whereIn('notifiable_id', clone $owners), $batch)
            + $this->delete($this->older($days)->where('notifiable_type', (new Counsellor)->getMorphClass())
                ->whereIn('notifiable_id', Counsellor::withTrashed()->select('id')->whereIn('user_id', clone $owners)), $batch);
    }

    // Notifications whose owner row is gone for good (morph relations don't cascade).
    private function ownerless(int $days): Builder
    {
        return $this->older($days)->where(fn (Builder $query) => $query
            ->where(fn (Builder $query) => $query->where('notifiable_type', (new User)->getMorphClass())
                ->whereNotIn('notifiable_id', User::withTrashed()->select('id')))
            ->orWhere(fn (Builder $query) => $query->where('notifiable_type', (new Counsellor)->getMorphClass())
                ->whereNotIn('notifiable_id', Counsellor::withTrashed()->select('id'))));
    }

    private function older(int $days): Builder
    {
        // A stored choice under the minimum (set by hand) is treated as the minimum.
        return DatabaseNotification::query()
            ->where('created_at', '<', now()->subDays(max($days, self::MINIMUM_DAYS)))
            // Never an unread safety notice.
            ->where(fn (Builder $query) => $query->whereNotNull('read_at')->orWhereNotIn('type', NotificationCenter::NEVER_MARKED_BY_LIST));
    }

    private function delete(Builder $due, int $batch): int
    {
        $deleted = 0;
        do {
            $ids = (clone $due)->orderBy('created_at')->limit($batch)->pluck('id');
            if ($ids->isNotEmpty()) {
                $deleted += DatabaseNotification::query()->whereKey($ids->all())->delete();
            }
        } while ($ids->count() === $batch);

        return $deleted;
    }
}
