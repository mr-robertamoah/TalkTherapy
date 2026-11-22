<?php

namespace App\Services;

use App\Actions\EnsureIsSuperAdminAction;
use App\Actions\Recognition\GetRecognitionEligibleCounsellorIdsAction;
use App\DTOs\PlatformFeeWaiverDTO;
use App\Enums\PlatformFeeWaiverEventEnum as Event;
use App\Exceptions\PlatformFeeWaiverException;
use App\Models\Counsellor;
use App\Models\CounsellorEarning;
use App\Models\CounsellorRecognition;
use App\Models\OrganizationInvoiceLine;
use App\Models\PlatformFeeWaiver;
use App\Models\PlatformFeeWaiverEvent;
use App\Models\User;
use App\Notifications\CounsellorFeeWaiverGrantedNotification;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

// TT-7.10b/SCRUM-468: commission-free periods. Platform-wide ones (no counsellor) -- every administrator sees them;
// only a super administrator creates one, ends one early or deletes one not yet started. TT-7.11e/SCRUM-475: the same
// for one counsellor's own periods, from that counsellor's routes, by a super administrator only. The two scopes never
// mix: a platform-wide id is "not found" on a counsellor's routes, and a counsellor's on the platform-wide ones (or on
// another counsellor's). Every create, end and delete, in either scope, writes a PlatformFeeWaiverEvent.
//
// There is deliberately no edit: a period's start never moves once set, and "End now" is the only change to one already
// running. Which fee a payment paid is never decided here (that is PlatformFeePolicy's, stored on the money rows), so
// ending or deleting a period never rewrites anything recorded.
class PlatformFeeWaiverService extends Service
{
    public const NOT_ALLOWED = 'Only a super administrator can change commission-free periods.';

    public const NOT_FOUND = 'That commission-free period could not be found.';

    public const COUNSELLOR_NOT_FOUND = 'That counsellor could not be found.';

    // As SCRUM-473's recognition: never a waiver for one's own counsellor profile.
    public const NOT_FOR_YOURSELF = 'You can\'t give yourself a commission-free period.';

    public const NOT_ELIGIBLE = 'This counsellor can\'t be given a commission-free period now: they must be verified, not suspended, and not deleted.';

    // How many periods the admin lists show (newest start first) -- a handful a year at most.
    public const LIST_LIMIT = 50;

    public const EVENTS_LIMIT = 100;

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

    /**
     * The history of the platform-wide periods, newest first (super administrators only).
     *
     * @return Collection<int, PlatformFeeWaiverEvent>
     */
    public function getPlatformWideEventsForAdmin(PlatformFeeWaiverDTO $dto): Collection
    {
        $this->ensureCanManage($dto);

        return $this->events(null);
    }

    /**
     * One counsellor's periods and their history, and whether they could be given a new one now (super administrators
     * only). A soft-deleted counsellor's are still listed, so a running one can be ended.
     *
     * Waivers are not ended automatically when a counsellor later loses verification or is suspended (the user's
     * decision): `eligible` is false then, and the recognition page alerts on it for the administrator to decide.
     *
     * @return array{counsellor: Counsellor, eligible: bool, waivers: Collection<int, PlatformFeeWaiver>, events: Collection<int, PlatformFeeWaiverEvent>}
     */
    public function getForCounsellorForAdmin(PlatformFeeWaiverDTO $dto): array
    {
        $this->ensureCanManage($dto);

        $counsellor = $dto->counsellorId ? Counsellor::withTrashed()->find($dto->counsellorId) : null;
        if (! $counsellor) {
            throw new PlatformFeeWaiverException(self::COUNSELLOR_NOT_FOUND, 404);
        }

        return [
            'counsellor' => $counsellor,
            'eligible' => $this->isEligible($counsellor),
            'waivers' => PlatformFeeWaiver::query()
                ->where('counsellor_id', $counsellor->id)
                ->with(['createdBy', 'endedBy'])
                ->orderByDesc('starts_at')
                ->orderByDesc('id')
                ->limit(self::LIST_LIMIT)
                ->get(),
            'events' => $this->events($counsellor->id),
        ];
    }

    // The on-page notice: a commission-free period in force now, else the next one starting within
    // NOTICE_HORIZON_DAYS. Without a counsellor (an organisation's page), platform-wide periods only; with one (their
    // own payouts and dashboard), their own periods too. One in force now comes before one coming up, and when both
    // scopes are in force (or start together) the platform-wide one wins, as it does for the fee (PlatformFeePolicy).
    // Only its dates and scope -- never the reason or who created it.
    public function getCommissionFreeNotice(?Counsellor $counsellor = null): ?array
    {
        $now = now();
        $scoped = fn () => PlatformFeeWaiver::query()->where(fn ($query) => $query
            ->whereNull('counsellor_id')
            ->when($counsellor, fn ($query) => $query->orWhere('counsellor_id', $counsellor->id)));

        // Platform-wide first (NULLs sort first ascending on MySQL and SQLite), then the oldest row.
        $waiver = $scoped()->coveringTime($now)->orderBy('counsellor_id')->orderBy('id')->first()
            ?? $scoped()
                ->where('starts_at', '>', $now)
                ->where('starts_at', '<=', $now->copy()->addDays(PlatformFeeWaiver::NOTICE_HORIZON_DAYS))
                ->orderBy('starts_at')
                ->orderBy('counsellor_id')
                ->first();

        return $waiver ? [
            'startsAt' => $waiver->starts_at->toIso8601ZuluString(),
            'endsAt' => $waiver->ends_at?->toIso8601ZuluString(),
            'scope' => $waiver->isPlatformWide() ? 'platform' : 'personal',
        ] : null;
    }

    /**
     * A platform-wide period.
     *
     * @param  array{starts_at: CarbonInterface, ends_at: CarbonInterface, reason: string}  $values  validated, in UTC
     */
    public function create(PlatformFeeWaiverDTO $dto, array $values): PlatformFeeWaiver
    {
        $this->ensureCanManage($dto);
        $this->ensureEndsAfterStart($values);

        // Two periods created at once can't both pass the overlap check: the platform-wide rows are locked first (and
        // a deadlock between two such creates is retried, when the second then sees the first's row).
        //
        // This relies on InnoDB's REPEATABLE READ (MySQL's default) or SERIALIZABLE isolation, where the locking read
        // also takes gap (next-key) locks on the counsellor_id IS NULL index range, so a concurrent insert of another
        // platform-wide row waits. Under READ COMMITTED there are no gap locks, and two creates over an empty range
        // could both pass.
        return DB::transaction(function () use ($dto, $values) {
            $this->ensureNoOverlap(PlatformFeeWaiver::query()->platformWide()->lockForUpdate()->get(['id', 'starts_at', 'ends_at']), $values);

            return $this->insert($dto, $values, null);
        }, 3);
    }

    /**
     * One counsellor's own period -- the route's counsellor (DTO counsellorId), optionally linked to an approved
     * recognition of theirs (DTO recognitionId). It may overlap a platform-wide period (that one's id is then recorded
     * on payments, PlatformFeePolicy), never another of the counsellor's own.
     *
     * @param  array{starts_at: CarbonInterface, ends_at: CarbonInterface, reason: string}  $values  validated, in UTC
     */
    public function createForCounsellor(PlatformFeeWaiverDTO $dto, array $values): PlatformFeeWaiver
    {
        $this->ensureCanManage($dto);
        $this->ensureEndsAfterStart($values);

        // The counsellor's row is the lock: two creates for one counsellor queue on it (an end or delete takes it too),
        // so the overlap check can't be raced, whatever the isolation level. Lock order: counsellor, then recognition.
        return DB::transaction(function () use ($dto, $values) {
            $counsellor = $this->lockCounsellor($dto);

            if ((int) $counsellor->user_id === $dto->user->id) {
                throw new PlatformFeeWaiverException(self::NOT_FOR_YOURSELF, 403);
            }

            $recognition = $dto->recognitionId ? $this->lockRecognition($dto->recognitionId, $counsellor) : null;

            // Eligible now, read under the lock. A waiver for a recognition also needs no open report against them
            // (SCRUM-473: the recognition itself is hidden while one is open).
            if (! $this->isEligible($counsellor, withoutOpenReports: (bool) $recognition)) {
                throw ValidationException::withMessages([
                    'counsellor' => $recognition
                        ? 'This counsellor can\'t be given a commission-free period for a recognition now: they must be verified, not suspended, not deleted, and not the subject of an open report.'
                        : self::NOT_ELIGIBLE,
                ]);
            }

            $this->ensureNoOverlap(PlatformFeeWaiver::query()->where('counsellor_id', $counsellor->id)->get(['id', 'starts_at', 'ends_at']), $values);

            $waiver = $this->insert($dto, $values, $counsellor, $recognition);
            $recognition?->forceFill(['platform_fee_waiver_id' => $waiver->id])->save();

            // After commit (the notification's own afterCommit()).
            $counsellor->notify(new CounsellorFeeWaiverGrantedNotification($waiver));

            return $waiver;
        }, 3);
    }

    // Ends a running platform-wide period at this moment.
    public function endNow(PlatformFeeWaiverDTO $dto): PlatformFeeWaiver
    {
        $this->ensureCanManage($dto);

        return DB::transaction(fn () => $this->end($dto, $this->lockWaiver(null, $dto->waiverId)));
    }

    public function endNowForCounsellor(PlatformFeeWaiverDTO $dto): PlatformFeeWaiver
    {
        $this->ensureCanManage($dto);

        return DB::transaction(fn () => $this->end($dto, $this->lockWaiver($this->lockCounsellor($dto)->id, $dto->waiverId)));
    }

    // Deletes a platform-wide period that hasn't started and that nothing refers to.
    public function delete(PlatformFeeWaiverDTO $dto): void
    {
        $this->ensureCanManage($dto);

        DB::transaction(fn () => $this->remove($dto, $this->lockWaiver(null, $dto->waiverId)));
    }

    // A linked recognition's platform_fee_waiver_id is nulled by its foreign key; the deleted event keeps the link.
    public function deleteForCounsellor(PlatformFeeWaiverDTO $dto): void
    {
        $this->ensureCanManage($dto);

        DB::transaction(fn () => $this->remove($dto, $this->lockWaiver($this->lockCounsellor($dto)->id, $dto->waiverId)));
    }

    // Currently verified, not suspended, not deleted (nor their account) -- as of now; with $withoutOpenReports, also
    // not the reported party of an open report (GetRecognitionEligibleCounsellorIdsAction, SCRUM-473's rule).
    public function isEligible(Counsellor $counsellor, bool $withoutOpenReports = false): bool
    {
        return GetRecognitionEligibleCounsellorIdsAction::new()->execute([$counsellor->id], $withoutOpenReports ? null : []) === [$counsellor->id];
    }

    private function ensureEndsAfterStart(array $values): void
    {
        // Every caller, not just the FormRequest: a start moved up to now (ValidatesFeeWaiverPeriod::values()) must
        // still leave the end after it.
        if (! $values['ends_at']->gt($values['starts_at'])) {
            throw ValidationException::withMessages([
                'ends_at' => 'The end must be after the start.',
            ]);
        }
    }

    /**
     * @param  Collection<int, PlatformFeeWaiver>  $waivers  the same scope's periods, read under its lock
     */
    private function ensureNoOverlap(Collection $waivers, array $values): void
    {
        // Half-open [starts_at, ends_at): touching ends (one's end is the next's start) don't overlap.
        $overlapping = $waivers->contains(fn (PlatformFeeWaiver $waiver) => $waiver->starts_at->lt($values['ends_at'])
            && ($waiver->ends_at === null || $waiver->ends_at->gt($values['starts_at'])));

        if ($overlapping) {
            throw ValidationException::withMessages([
                'starts_at' => 'This period overlaps another commission-free period.',
            ]);
        }
    }

    private function insert(PlatformFeeWaiverDTO $dto, array $values, ?Counsellor $counsellor, ?CounsellorRecognition $recognition = null): PlatformFeeWaiver
    {
        // counsellor_id and created_by_id are never fillable: from the route's counsellor (null is platform-wide) and
        // the acting user.
        $waiver = new PlatformFeeWaiver([
            'starts_at' => $values['starts_at'],
            'ends_at' => $values['ends_at'],
            'reason' => $values['reason'],
        ]);
        $waiver->forceFill(['counsellor_id' => $counsellor?->id, 'created_by_id' => $dto->user->id])->save();

        $this->recordEvent($waiver, Event::created, $dto->user, array_filter([
            'counsellor_recognition_id' => $recognition?->id,
        ]));

        return $waiver;
    }

    // Only one in force now: an upcoming one is deleted instead, and one that has ended (or was already ended) has
    // nothing left to end.
    private function end(PlatformFeeWaiverDTO $dto, PlatformFeeWaiver $waiver): PlatformFeeWaiver
    {
        if ($waiver->status() !== PlatformFeeWaiver::ACTIVE) {
            throw ValidationException::withMessages([
                'waiver' => 'Only a commission-free period that is running now can be ended.',
            ]);
        }

        $scheduledEnd = $waiver->ends_at?->toIso8601ZuluString();
        $waiver->forceFill(['ends_at' => now(), 'ended_by_id' => $dto->user->id])->save();

        $this->recordEvent($waiver, Event::ended, $dto->user, ['scheduled_ends_at' => $scheduledEnd]);

        return $waiver;
    }

    // One that started is ended instead, so the record of which payments it covered stays.
    private function remove(PlatformFeeWaiverDTO $dto, PlatformFeeWaiver $waiver): void
    {
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

        // Written first, in the same transaction: the row is about to go, so the event keeps what it said (the reason
        // is administrator-only data, and these events are read by super administrators only).
        $this->recordEvent($waiver, Event::deleted, $dto->user, array_filter([
            'reason' => $waiver->reason,
            'counsellor_recognition_id' => CounsellorRecognition::query()->where('platform_fee_waiver_id', $waiver->id)->value('id'),
        ], fn ($value) => $value !== null));

        try {
            $waiver->delete();
        } catch (QueryException $e) {
            // The money rows' foreign keys restrict -- a reference the check above missed. Any other database error is
            // thrown on (and logged as a 500).
            if ($e->getCode() !== '23000' && ($e->errorInfo[0] ?? null) !== '23000') {
                throw $e;
            }

            throw $this->stillReferenced();
        }
    }

    private function ensureCanManage(PlatformFeeWaiverDTO $dto): void
    {
        EnsureIsSuperAdminAction::new()->execute($dto, self::NOT_ALLOWED, 403);
    }

    // The period, locked -- only within its own scope: platform-wide ($counsellorId null) or that counsellor's. Any
    // other id is "not found", the same as one that doesn't exist.
    private function lockWaiver(?int $counsellorId, ?int $id): PlatformFeeWaiver
    {
        $waiver = $id
            ? PlatformFeeWaiver::query()
                ->when($counsellorId === null, fn ($query) => $query->platformWide(), fn ($query) => $query->where('counsellor_id', $counsellorId))
                ->lockForUpdate()
                ->find($id)
            : null;

        if (! $waiver) {
            throw new PlatformFeeWaiverException(self::NOT_FOUND, 404);
        }

        return $waiver;
    }

    // The route's counsellor, locked -- the mutex for that counsellor's periods. Soft-deleted ones too, so a running
    // period of theirs can still be ended (a new one is refused as not eligible).
    private function lockCounsellor(PlatformFeeWaiverDTO $dto): Counsellor
    {
        $counsellor = $dto->counsellorId ? Counsellor::withTrashed()->lockForUpdate()->find($dto->counsellorId) : null;

        if (! $counsellor) {
            throw new PlatformFeeWaiverException(self::COUNSELLOR_NOT_FOUND, 404);
        }

        return $counsellor;
    }

    // The recognition to link: the counsellor's own, approved, and not linked to a period already.
    private function lockRecognition(int $id, Counsellor $counsellor): CounsellorRecognition
    {
        $recognition = CounsellorRecognition::query()->lockForUpdate()->find($id);

        if (! $recognition || (int) $recognition->counsellor_id !== $counsellor->id || ! $recognition->isApproved()) {
            throw ValidationException::withMessages([
                'counsellor_recognition_id' => 'Choose an approved recognition of this counsellor.',
            ]);
        }

        if ($recognition->platform_fee_waiver_id !== null) {
            throw ValidationException::withMessages([
                'counsellor_recognition_id' => 'This recognition already has a commission-free period.',
            ]);
        }

        return $recognition;
    }

    private function recordEvent(PlatformFeeWaiver $waiver, Event $event, User $user, array $data = []): void
    {
        PlatformFeeWaiverEvent::create([
            'platform_fee_waiver_id' => $waiver->id,
            'counsellor_id' => $waiver->counsellor_id,
            'user_id' => $user->id,
            'event' => $event,
            'data' => [
                'starts_at' => $waiver->starts_at->toIso8601ZuluString(),
                'ends_at' => $waiver->ends_at?->toIso8601ZuluString(),
                ...$data,
            ],
        ]);
    }

    /**
     * @return Collection<int, PlatformFeeWaiverEvent>
     */
    private function events(?int $counsellorId): Collection
    {
        return PlatformFeeWaiverEvent::query()
            ->when($counsellorId === null, fn ($query) => $query->whereNull('counsellor_id'), fn ($query) => $query->where('counsellor_id', $counsellorId))
            ->with('user')
            ->orderByDesc('id')
            ->limit(self::EVENTS_LIMIT)
            ->get();
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
