<?php

namespace App\Actions\Admin;

use App\Actions\Action;
use App\Enums\CounsellorEarningStatusEnum;
use App\Enums\CounsellorPayoutStatusEnum;
use App\Enums\OrganizationInvoiceStatusEnum;
use App\Enums\RefundStatusEnum;
use App\Enums\RequestStatusEnum;
use App\Enums\RequestTypeEnum;
use App\Enums\SessionStatusEnum;
use App\Enums\TherapyStatusEnum;
use App\Enums\TransactionStatusEnum;
use App\Models\Counsellor;
use App\Models\CounsellorEarning;
use App\Models\CounsellorPayout;
use App\Models\GroupTherapy;
use App\Models\Organization;
use App\Models\OrganizationInvoice;
use App\Models\Refund;
use App\Models\Report;
use App\Models\Request;
use App\Models\Session;
use App\Models\Suspension;
use App\Models\Therapy;
use App\Models\Transaction;
use App\Models\User;
use App\Models\VideoSession;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

// SCRUM-387 (+ SCRUM-49): the admin overview's platform figures -- totals right now, and for this
// week, this month and all time what happened in the period, with the previous week or month
// alongside (the user's decision). Totals only: no names, ids or lists. Money (every administrator
// sees it, the user's decision) is always per currency, never added up across currencies, and
// counts only successful, non-reversed rows. Cached for 15 minutes; `asOf` says when it was worked
// out.
//
// Periods are calendar ones in the app's timezone: this week (from Monday) or this month so far,
// against the whole previous week or month. A row falls in a period by when it was created (the
// payment, payout, refund and invoice tables keep no separate "completed at"), except sessions,
// by when they were due to start, and video calls, by when they started.
class GetPlatformStatsAction extends Action
{
    public const CACHE_KEY = 'admin-platform-stats';

    private const TTL_SECONDS = 900;

    /** @return array<string, mixed> empty for a non-admin */
    public function execute(User $viewer): array
    {
        if (! $viewer->isAdmin()) {
            return [];
        }

        return Cache::remember(self::CACHE_KEY, self::TTL_SECONDS, fn () => $this->compute(CarbonImmutable::now()));
    }

    private function compute(CarbonImmutable $now): array
    {
        $week = $now->startOfWeek(CarbonInterface::MONDAY);
        $month = $now->startOfMonth();

        return [
            'asOf' => $now->toIso8601String(),
            'totals' => $this->totals($now),
            'periods' => [
                'week' => [
                    'current' => $this->forPeriod($week),
                    'previous' => $this->forPeriod($week->subWeek(), $week),
                ],
                'month' => [
                    'current' => $this->forPeriod($month),
                    'previous' => $this->forPeriod($month->subMonth(), $month),
                ],
                'all' => [
                    'current' => $this->forPeriod(),
                    'previous' => null,
                ],
            ],
        ];
    }

    private function totals(CarbonImmutable $now): array
    {
        $awaitingVerification = Request::query()
            ->select('for_id')
            ->where('type', RequestTypeEnum::counsellor->value)
            ->where('for_type', Counsellor::class)
            ->where('status', RequestStatusEnum::pending->value);

        // SCRUM-33: quick help chats aren't therapies here (TT-4.4b gives them their own place).
        $therapies = Therapy::query()->withoutHelpChats()->toBase()
            ->selectRaw('sum(case when status = ? then 1 else 0 end) as active, sum(case when status = ? then 1 else 0 end) as pending', [TherapyStatusEnum::in_session->value, TherapyStatusEnum::pending->value])
            ->first();
        $groups = GroupTherapy::query()->toBase()
            ->selectRaw('sum(case when status = ? then 1 else 0 end) as active, sum(case when status = ? then 1 else 0 end) as pending', [TherapyStatusEnum::in_session->value, TherapyStatusEnum::pending->value])
            ->first();

        return [
            'users' => $this->users()->count(),
            'counsellors' => Counsellor::query()->count(),
            'counsellorsVerified' => Counsellor::query()->whereCurrentlyVerified()->count(),
            // SCRUM-389: not currently verified (never, ended -- or no end date, read as ended -- or
            // revoked) and asking to be. Written out, not NOT(...): SQL's NULLs would drop a row with
            // no end date (reviewer finding).
            'counsellorsAwaiting' => Counsellor::query()
                ->where(fn ($query) => $query
                    ->whereNull('verified_at')
                    ->orWhereNotNull('verification_revoked_at')
                    ->orWhereNull('verification_expires_at')
                    ->orWhere('verification_expires_at', '<=', $now))
                ->whereIn('id', $awaitingVerification)
                ->count(),
            'organizations' => Organization::query()->count(),
            'organizationsVerified' => Organization::query()->whereNotNull('verified_at')->count(),
            'activeTherapies' => (int) $therapies->active,
            'pendingTherapies' => (int) $therapies->pending,
            'activeGroupTherapies' => (int) $groups->active,
            'pendingGroupTherapies' => (int) $groups->pending,
            'upcomingSessions' => Session::query()->wherePending()->where('start_time', '>', $now)->count(),
            'activeSuspensions' => Suspension::query()->active()->count(),
        ];
    }

    // What happened from $from (or ever) until $to (exclusive), or until now when $to is null.
    private function forPeriod(?CarbonImmutable $from = null, ?CarbonImmutable $to = null): array
    {
        $in = fn (Builder $query, string $column = 'created_at') => $query
            ->when($from, fn ($query) => $query->where($column, '>=', $from))
            ->when($to, fn ($query) => $query->where($column, '<', $to));

        $sessions = $in(Session::query()->withoutHelpChats(), 'start_time')->toBase()
            ->selectRaw(
                'sum(case when status = ? then 1 else 0 end) as held, sum(case when status in (?, ?) then 1 else 0 end) as missed',
                [SessionStatusEnum::held->value, SessionStatusEnum::failed->value, SessionStatusEnum::abandoned->value]
            )
            ->first();
        $held = (int) $sessions->held;
        $missed = (int) $sessions->missed;

        return [
            'newUsers' => $in($this->users())->count(),
            'sessionsHeld' => $held,
            'sessionsFailedOrAbandoned' => $missed,
            // Of the sessions that were due and have an outcome either way, the share that didn't
            // happen (failed) or ended early (abandoned). Null when there were none.
            'failedOrAbandonedShare' => $held + $missed ? round($missed / ($held + $missed), 3) : null,
            'videoMinutesApprox' => $this->videoMinutes($in(VideoSession::query(), 'started_at')),
            'reportsOpened' => $in(Report::query())->count(),
            'money' => [
                'revenue' => $this->perCurrency($in(Transaction::query()->where('status', TransactionStatusEnum::success->value))),
                'platformFees' => $this->perCurrency($in(CounsellorEarning::query()->where('status', '!=', CounsellorEarningStatusEnum::reversed->value)), 'fee_amount'),
                'payoutsPaid' => $this->perCurrency($in(CounsellorPayout::query()->where('status', CounsellorPayoutStatusEnum::succeeded->value))),
                'refundsPaid' => $this->perCurrency($in(Refund::query()->where('status', RefundStatusEnum::success->value))),
                'invoicesSettled' => $this->perCurrency($in(OrganizationInvoice::query()->where('status', OrganizationInvoiceStatusEnum::settled->value))),
            ],
        ];
    }

    // Accounts in use: soft-deleted (closed) and anonymised ones left out.
    private function users(): Builder
    {
        return User::query()->whereNull('anonymised_at');
    }

    /** @return array<string, int> minor units, keyed by currency code, sorted by code */
    private function perCurrency(Builder $query, string $column = 'amount'): array
    {
        return $query->toBase()
            ->selectRaw("currency, sum({$column}) as total")
            ->groupBy('currency')
            ->orderBy('currency')
            ->pluck('total', 'currency')
            ->map(fn ($total) => (int) $total)
            ->all();
    }

    // Approximate: from when the call started to when it ended, so it doesn't know who was on it
    // or for how long each. Calls still going (no end) aren't counted.
    private function videoMinutes(Builder $query): int
    {
        $seconds = DB::connection()->getDriverName() === 'sqlite'
            ? '(julianday(ended_at) - julianday(started_at)) * 86400'
            : 'timestampdiff(second, started_at, ended_at)';

        $total = $query->toBase()
            ->whereNotNull('started_at')
            ->whereNotNull('ended_at')
            ->whereColumn('ended_at', '>', 'started_at')
            ->selectRaw("sum({$seconds}) as seconds")
            ->value('seconds');

        return (int) round(((float) $total) / 60);
    }
}
