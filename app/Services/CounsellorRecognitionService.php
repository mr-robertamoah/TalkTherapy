<?php

namespace App\Services;

use App\Actions\EnsureIsSuperAdminAction;
use App\Actions\Recognition\GetRecognitionEligibleCounsellorIdsAction;
use App\Actions\Recognition\GetRecognitionSuggestionsAction;
use App\Actions\Recognition\RecordCounsellorRecognitionEventAction;
use App\Actions\Request\CreateRequestAction;
use App\DTOs\CounsellorRecognitionDTO;
use App\DTOs\CreateRequestDTO;
use App\Enums\CounsellorRecognitionConsentEnum as Consent;
use App\Enums\CounsellorRecognitionDecisionEnum as Decision;
use App\Enums\CounsellorRecognitionEventEnum as Event;
use App\Enums\RequestStatusEnum;
use App\Enums\RequestTypeEnum;
use App\Exceptions\CounsellorRecognitionException;
use App\Models\Counsellor;
use App\Models\CounsellorRecognition;
use App\Models\Request;
use App\Notifications\CounsellorRecognisedNotification;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

// TT-7.11d1/SCRUM-473: counsellor recognition -- a super administrator approves or skips the month's suggestions, or
// adds a counsellor who wasn't suggested; the counsellor answers the featuring consent request
// (RespondToCounsellorRecognitionRequestAction), and may later withdraw it or opt back in.
//
// Decisions: one row per counsellor per month. At most config('recognition.per_month') approved a month, checked with
// that month's rows locked. Approving (again) sends a new consent request and the notification; skipping an approved
// one closes its pending consent request (made moot). Repeating the current decision changes nothing.
class CounsellorRecognitionService extends Service
{
    public const NOT_ALLOWED = 'Only a super administrator can recognise counsellors.';

    public const NOT_FOUND = 'That recognition could not be found.';

    public const WITHDRAW = 'withdraw';

    public const RESTORE = 'restore';

    public function getSuggestions(CounsellorRecognitionDTO $dto): array
    {
        $this->ensureCanManage($dto);

        return GetRecognitionSuggestionsAction::new()->execute($this->month($dto));
    }

    // A suggested counsellor (ranked within the month's cap, ties included).
    public function approve(CounsellorRecognitionDTO $dto): CounsellorRecognition
    {
        $this->ensureCanManage($dto);
        $month = $this->month($dto);
        $counsellor = $this->counsellor($dto);

        $ranked = $this->suggestion($month, $counsellor->id);
        if (! $ranked) {
            throw ValidationException::withMessages([
                'counsellor_id' => 'This counsellor is not among this month\'s suggestions. Add them instead.',
            ]);
        }

        return $this->recognise($dto, $month, $counsellor, $ranked['stars'], $ranked['rank'], manually: false);
    }

    // A counsellor who isn't suggested -- still only one eligible now, and within the cap.
    public function add(CounsellorRecognitionDTO $dto): CounsellorRecognition
    {
        $this->ensureCanManage($dto);
        $month = $this->month($dto);
        $counsellor = $this->counsellor($dto);

        $ranked = GetRecognitionSuggestionsAction::new()->ranking($month)['ranked']->get($counsellor->id);
        if ($ranked && $ranked['rank'] <= GetRecognitionSuggestionsAction::perMonth()) {
            throw ValidationException::withMessages([
                'counsellor_id' => 'This counsellor is already suggested for this month. Approve them instead.',
            ]);
        }

        if (! in_array($counsellor->id, GetRecognitionEligibleCounsellorIdsAction::new()->execute([$counsellor->id]), true)) {
            throw ValidationException::withMessages([
                'counsellor_id' => 'This counsellor can\'t be recognised now: they must be verified, not suspended, and not the subject of an open report.',
            ]);
        }

        return $this->recognise($dto, $month, $counsellor, $ranked['stars'] ?? 0, $ranked['rank'] ?? null, manually: true);
    }

    // A suggested counsellor, or one already decided this month (approve -> skip). Skipping needs no eligibility.
    public function skip(CounsellorRecognitionDTO $dto): CounsellorRecognition
    {
        $this->ensureCanManage($dto);
        $month = $this->month($dto);
        $counsellor = $this->counsellor($dto, withTrashed: true);
        $ranked = $this->suggestion($month, $counsellor->id);

        return $this->inTransaction(function () use ($dto, $month, $counsellor, $ranked) {
            $recognition = $this->lockMonth($month)->firstWhere('counsellor_id', $counsellor->id);

            if ($recognition?->decision === Decision::skipped) {
                return $recognition;
            }

            if (! $recognition && ! $ranked) {
                throw ValidationException::withMessages([
                    'counsellor_id' => 'Only a suggested counsellor, or one already decided this month, can be skipped.',
                ]);
            }

            $previous = $recognition?->decision;
            $recognition ??= $this->newRow($counsellor, $month, $ranked['stars'] ?? null, $ranked['rank'] ?? null, false);

            $recognition->forceFill([
                'decision' => Decision::skipped,
                'consent' => null,
                'consent_changed_at' => $previous === Decision::approved ? now() : $recognition->consent_changed_at,
                'decided_by_id' => $dto->user->id,
                'decided_at' => now(),
            ])->save();

            $this->closePendingConsentRequests($recognition);

            RecordCounsellorRecognitionEventAction::new()->execute($recognition, Event::skipped, $dto->user, array_filter([
                'from' => $previous?->value,
            ]));

            return $recognition;
        });
    }

    // The counsellor's own: withdraw (accepted or pending -> withdrawn) or opt back in (withdrawn or declined ->
    // accepted). Someone else's recognition is "not found", the same as one that doesn't exist.
    public function changeConsent(CounsellorRecognitionDTO $dto): CounsellorRecognition
    {
        if (! in_array($dto->consentAction, [self::WITHDRAW, self::RESTORE], true)) {
            throw ValidationException::withMessages(['action' => 'Choose to withdraw or to restore your consent.']);
        }

        return DB::transaction(function () use ($dto) {
            $recognition = $dto->recognitionId ? CounsellorRecognition::query()->lockForUpdate()->find($dto->recognitionId) : null;
            $ownerUserId = $recognition ? Counsellor::withTrashed()->whereKey($recognition->counsellor_id)->value('user_id') : null;

            if (! $recognition || ! $dto->user || (int) $ownerUserId !== $dto->user->id) {
                throw new CounsellorRecognitionException(self::NOT_FOUND, 404);
            }

            $from = $recognition->consent;
            $withdraw = $dto->consentAction === self::WITHDRAW;
            $allowed = $recognition->isApproved() && $from && ($withdraw ? $from->canBeWithdrawn() : $from->canBeRestored());

            if (! $allowed) {
                throw ValidationException::withMessages([
                    'action' => $withdraw
                        ? 'Only a recognition you accepted, or haven\'t answered yet, can be withdrawn.'
                        : 'Only a recognition you declined or withdrew can be restored.',
                ]);
            }

            $recognition->forceFill([
                'consent' => $withdraw ? Consent::withdrawn : Consent::accepted,
                'consent_changed_at' => now(),
            ])->save();

            // Withdrawn before answering: the consent request has nothing left to ask.
            if ($from === Consent::pending) {
                $this->closePendingConsentRequests($recognition);
            }

            RecordCounsellorRecognitionEventAction::new()->execute(
                $recognition,
                $withdraw ? Event::consentWithdrawn : Event::consentRestored,
                $dto->user,
                ['from' => $from->value],
            );

            return $recognition;
        });
    }

    private function recognise(CounsellorRecognitionDTO $dto, CarbonImmutable $month, Counsellor $counsellor, int $stars, ?int $rank, bool $manually): CounsellorRecognition
    {
        $perMonth = GetRecognitionSuggestionsAction::perMonth();

        return $this->inTransaction(function () use ($dto, $month, $counsellor, $stars, $rank, $manually, $perMonth) {
            $rows = $this->lockMonth($month);
            $recognition = $rows->firstWhere('counsellor_id', $counsellor->id);

            if ($recognition?->isApproved()) {
                return $recognition;
            }

            if ($rows->filter(fn (CounsellorRecognition $row) => $row->isApproved())->count() >= $perMonth) {
                throw ValidationException::withMessages([
                    'counsellor_id' => "Up to {$perMonth} counsellors can be recognised for a month, and that many already are.",
                ]);
            }

            $previous = $recognition?->decision;
            $recognition ??= $this->newRow($counsellor, $month, $stars, $rank, $manually);

            $recognition->forceFill([
                'decision' => Decision::approved,
                'consent' => Consent::pending,
                'consent_changed_at' => now(),
                'added_manually' => $manually,
                'stars_count' => $stars,
                'rank' => $rank,
                'decided_by_id' => $dto->user->id,
                'decided_at' => now(),
            ])->save();

            RecordCounsellorRecognitionEventAction::new()->execute(
                $recognition,
                $manually ? Event::added : Event::approved,
                $dto->user,
                array_filter(['stars' => $stars, 'rank' => $rank, 'from' => $previous?->value], fn ($value) => ! is_null($value)),
            );

            $request = CreateRequestAction::new()->execute(CreateRequestDTO::new()->fromArray([
                'from' => $dto->user,
                'to' => $counsellor,
                'for' => $recognition,
                'type' => RequestTypeEnum::counsellorRecognition->value,
                'data' => ['month' => $month->format('Y-m')],
            ]));

            // After commit (the notification's own afterCommit()).
            $counsellor->notify(new CounsellorRecognisedNotification($recognition, $request));

            return $recognition;
        });
    }

    // Every row of the month, locked, so the cap holds when two decisions are made at once. Under InnoDB's REPEATABLE
    // READ (MySQL's default) the locking read also takes gap locks on the (month, decision) index, so a first row
    // inserted concurrently for an empty month waits too; the unique (counsellor_id, month) index catches the rest.
    private function lockMonth(CarbonImmutable $month)
    {
        return CounsellorRecognition::query()->forMonth($month)->lockForUpdate()->get();
    }

    private function inTransaction(callable $callback): CounsellorRecognition
    {
        try {
            return DB::transaction($callback, 3);
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages([
                'counsellor_id' => 'This counsellor was just decided by someone else. Reload and try again.',
            ]);
        }
    }

    private function newRow(Counsellor $counsellor, CarbonImmutable $month, ?int $stars, ?int $rank, bool $manually): CounsellorRecognition
    {
        return (new CounsellorRecognition)->forceFill([
            'counsellor_id' => $counsellor->id,
            'month' => CounsellorRecognition::monthKey($month),
            'added_manually' => $manually,
            'stars_count' => $stars,
            'rank' => $rank,
        ]);
    }

    // A pending consent request for it is made moot (INCONSEQUENCIAL) -- skipped, or withdrawn before it was answered.
    private function closePendingConsentRequests(CounsellorRecognition $recognition): void
    {
        Request::query()
            ->where('type', RequestTypeEnum::counsellorRecognition->value)
            ->whereFor($recognition)
            ->wherePending()
            ->lockForUpdate()
            ->get()
            ->each(fn (Request $request) => $request->update(['status' => RequestStatusEnum::inconsequencial->value]));
    }

    // The counsellor's row in the month's ranking, when it is within the cap (a suggestion); null otherwise.
    private function suggestion(CarbonImmutable $month, int $counsellorId): ?array
    {
        $ranked = GetRecognitionSuggestionsAction::new()->ranking($month)['ranked']->get($counsellorId);

        return $ranked && $ranked['rank'] <= GetRecognitionSuggestionsAction::perMonth() ? $ranked : null;
    }

    private function counsellor(CounsellorRecognitionDTO $dto, bool $withTrashed = false): Counsellor
    {
        $counsellor = $dto->counsellorId
            ? Counsellor::query()->when($withTrashed, fn ($query) => $query->withTrashed())->find($dto->counsellorId)
            : null;

        if (! $counsellor) {
            throw ValidationException::withMessages(['counsellor_id' => 'That counsellor could not be found.']);
        }

        return $counsellor;
    }

    // Checked again here for every caller, not only the FormRequest: 'YYYY-MM', no later than the current month.
    private function month(CounsellorRecognitionDTO $dto): CarbonImmutable
    {
        return CounsellorRecognition::parseMonth($dto->month)
            ?? throw ValidationException::withMessages(['month' => 'Choose a month (YYYY-MM) no later than this one.']);
    }

    private function ensureCanManage(CounsellorRecognitionDTO $dto): void
    {
        EnsureIsSuperAdminAction::new()->execute($dto, self::NOT_ALLOWED, 403);
    }
}
