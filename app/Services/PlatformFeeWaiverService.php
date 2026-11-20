<?php

namespace App\Services;

use App\Actions\EnsureIsSuperAdminAction;
use App\DTOs\PlatformFeeWaiverDTO;
use App\Exceptions\PlatformFeeWaiverException;
use App\Models\CounsellorEarning;
use App\Models\OrganizationInvoiceLine;
use App\Models\PlatformFeeWaiver;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

// TT-7.10b/SCRUM-468: platform-wide commission-free periods (PlatformFeeWaiver rows with no counsellor) -- every
// administrator sees them; only a super administrator creates one, ends one early or deletes one not yet started.
// Per-counsellor waivers are SCRUM-464's. There is deliberately no edit: a period's start never moves once set, and
// "End now" is the only change to one already running. Which fee a payment paid is never decided here (that is
// PlatformFeePolicy's, stored on the money rows), so ending or deleting a period never rewrites anything recorded.
class PlatformFeeWaiverService extends Service
{
    public const NOT_ALLOWED = 'Only a super administrator can change commission-free periods.';

    public const NOT_FOUND = 'That commission-free period could not be found.';

    // How many periods the admin list shows (newest start first) -- a handful a year at most.
    public const LIST_LIMIT = 50;

    // A period starting this soon can no longer be deleted, so a delete can't race the start (it can be ended instead,
    // once it is running).
    public const DELETE_CUTOFF_SECONDS = 60;

    /**
     * @return Collection<int, PlatformFeeWaiver>
     */
    public function getPlatformWideForAdmin(): Collection
    {
        return PlatformFeeWaiver::query()
            ->platformWide()
            ->with(['createdBy', 'endedBy'])
            ->orderByDesc('starts_at')
            ->orderByDesc('id')
            ->limit(self::LIST_LIMIT)
            ->get();
    }

    // The on-page notice for counsellors and organisations: the platform-wide period in force now, else the next one
    // starting within NOTICE_HORIZON_DAYS. Only its start and end -- never the reason or who created it.
    public function getCommissionFreeNotice(): ?array
    {
        $now = now();

        $waiver = PlatformFeeWaiver::query()->platformWide()->coveringTime($now)->orderBy('id')->first()
            ?? PlatformFeeWaiver::query()->platformWide()
                ->where('starts_at', '>', $now)
                ->where('starts_at', '<=', $now->copy()->addDays(PlatformFeeWaiver::NOTICE_HORIZON_DAYS))
                ->orderBy('starts_at')
                ->first();

        return $waiver ? [
            'startsAt' => $waiver->starts_at->toIso8601ZuluString(),
            'endsAt' => $waiver->ends_at?->toIso8601ZuluString(),
        ] : null;
    }

    /**
     * @param  array{starts_at: CarbonInterface, ends_at: CarbonInterface, reason: string}  $values  validated, in UTC
     */
    public function create(PlatformFeeWaiverDTO $dto, array $values): PlatformFeeWaiver
    {
        $this->ensureCanManage($dto);

        // Every caller, not just the FormRequest: a start moved up to now (StorePlatformFeeWaiverRequest::values()) must
        // still leave the end after it.
        if (! $values['ends_at']->gt($values['starts_at'])) {
            throw ValidationException::withMessages([
                'ends_at' => 'The end must be after the start.',
            ]);
        }

        // Two periods created at once can't both pass the overlap check: the platform-wide rows are locked first (and
        // a deadlock between two such creates is retried, when the second then sees the first's row).
        //
        // This relies on InnoDB's REPEATABLE READ (MySQL's default) or SERIALIZABLE isolation, where the locking read
        // also takes gap (next-key) locks on the counsellor_id IS NULL index range, so a concurrent insert of another
        // platform-wide row waits. Under READ COMMITTED there are no gap locks, and two creates over an empty range
        // could both pass.
        return DB::transaction(function () use ($dto, $values) {
            $overlapping = PlatformFeeWaiver::query()
                ->platformWide()
                ->lockForUpdate()
                ->get(['id', 'starts_at', 'ends_at'])
                // Half-open [starts_at, ends_at): touching ends (one's end is the next's start) don't overlap.
                ->contains(fn (PlatformFeeWaiver $waiver) => $waiver->starts_at->lt($values['ends_at'])
                    && ($waiver->ends_at === null || $waiver->ends_at->gt($values['starts_at'])));

            if ($overlapping) {
                throw ValidationException::withMessages([
                    'starts_at' => 'This period overlaps another commission-free period.',
                ]);
            }

            // counsellor_id isn't fillable (SCRUM-464 decides how a counsellor's waiver is made); null is platform-wide.
            $waiver = new PlatformFeeWaiver([
                'starts_at' => $values['starts_at'],
                'ends_at' => $values['ends_at'],
                'reason' => $values['reason'],
            ]);
            $waiver->forceFill(['created_by_id' => $dto->user->id])->save();

            return $waiver;
        }, 3);
    }

    // Ends a running period at this moment. Only one in force now: an upcoming one is deleted instead, and one that
    // has ended (or was already ended) has nothing left to end.
    public function endNow(PlatformFeeWaiverDTO $dto): PlatformFeeWaiver
    {
        $this->ensureCanManage($dto);

        return DB::transaction(function () use ($dto) {
            $waiver = $this->lockPlatformWide($dto);

            if ($waiver->status() !== PlatformFeeWaiver::ACTIVE) {
                throw ValidationException::withMessages([
                    'waiver' => 'Only a commission-free period that is running now can be ended.',
                ]);
            }

            $waiver->forceFill(['ends_at' => now(), 'ended_by_id' => $dto->user->id])->save();

            return $waiver;
        });
    }

    // Deletes a period that hasn't started and that nothing refers to. One that started is ended instead, so the
    // record of which payments it covered stays.
    public function delete(PlatformFeeWaiverDTO $dto): void
    {
        $this->ensureCanManage($dto);

        DB::transaction(function () use ($dto) {
            $waiver = $this->lockPlatformWide($dto);

            if ($waiver->status() !== PlatformFeeWaiver::UPCOMING) {
                throw ValidationException::withMessages([
                    'waiver' => 'Only a commission-free period that has not started can be deleted. End it instead.',
                ]);
            }

            if ($waiver->starts_at->lte(now()->addSeconds(self::DELETE_CUTOFF_SECONDS))) {
                throw ValidationException::withMessages([
                    'waiver' => 'This commission-free period starts within a minute, so it can no longer be deleted. End it once it has started.',
                ]);
            }

            if ($this->isReferenced($waiver)) {
                throw $this->stillReferenced();
            }

            try {
                $waiver->delete();
            } catch (QueryException $e) {
                // The money rows' foreign keys restrict -- a reference the check above missed. Any other database
                // error is thrown on (and logged as a 500).
                if ($e->getCode() !== '23000' && ($e->errorInfo[0] ?? null) !== '23000') {
                    throw $e;
                }

                throw $this->stillReferenced();
            }
        });
    }

    private function ensureCanManage(PlatformFeeWaiverDTO $dto): void
    {
        EnsureIsSuperAdminAction::new()->execute($dto, self::NOT_ALLOWED, 403);
    }

    // The period, locked -- the only lookup, and only a platform-wide one (a counsellor's waiver isn't managed here).
    private function lockPlatformWide(PlatformFeeWaiverDTO $dto): PlatformFeeWaiver
    {
        $waiver = $dto->waiverId
            ? PlatformFeeWaiver::query()->platformWide()->lockForUpdate()->find($dto->waiverId)
            : null;

        if (! $waiver) {
            throw new PlatformFeeWaiverException(self::NOT_FOUND, 404);
        }

        return $waiver;
    }

    // An unstarted period can't have been used: a fee is only ever waived under the period covering the payment's
    // moment. Checked anyway, on the rows with a foreign key to it (a charge breakdown, JSON, can only name a period
    // that had started when the charge was made, so it isn't read).
    private function isReferenced(PlatformFeeWaiver $waiver): bool
    {
        return CounsellorEarning::query()->where('platform_fee_waiver_id', $waiver->id)->exists()
            || OrganizationInvoiceLine::query()->where('platform_fee_waiver_id', $waiver->id)->exists();
    }

    private function stillReferenced(): ValidationException
    {
        return ValidationException::withMessages([
            'waiver' => 'This commission-free period has been used by a payment, so it cannot be deleted.',
        ]);
    }
}
