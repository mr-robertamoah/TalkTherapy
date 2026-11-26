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
use App\Models\OrganizationInvoiceLine;
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
use Closure;
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
//
// SCRUM-469: the commission given up under fee waivers (commissionWaived) -- see waivedCommission().
class GetPlatformStatsAction extends Action
{
    // One payload shared by every administrator: anything role-specific must be filtered after Cache::remember, or be
    // cached under its own key.
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
            'commissionWaived' => $this->waivedCommission($in),
        ];
    }

    // SCRUM-469: the platform fee not taken because a fee waiver was in force, per currency (minor units), in total and
    // split by the waiver's scope: platform-wide (no counsellor), one counsellor's own (SCRUM-475), or unattributed --
    // so total = platformWide + perCounsellor + unattributed. Two grouped queries per period, whatever the number of
    // rows.
    //
    // Where a waived amount is stored, and why nothing is counted twice:
    // - Personal payments: only on the counsellor earning.
    // - Organisation card charges: on the transaction's `breakdown` lines when the charge is made, then copied onto one
    //   earning per line when the charge succeeds (GenerateCounsellorEarningsAction::generateFromBreakdown). The
    //   breakdown is never summed: an earning exists exactly when the charge succeeded, and a charge still pending,
    //   failed or abandoned took no money, so its breakdown counts for nothing (as its fee isn't revenue). The one gap,
    //   a successful charge whose line was skipped because the counsellor is gone entirely, is logged there and left
    //   out here too, as its fee is left out of platformFees.
    // - Retainer invoices: on the invoice line when the session is held, then copied onto an earning (with
    //   organization_invoice_line_id) when the invoice settles (generateForSettledInvoice). So these are counted from
    //   the line only -- dated by when the line was recorded, so an invoice settling later doesn't move the amount to
    //   another period -- and earnings with an invoice line are skipped.
    //
    // What counts, as for the fee figures: earnings that aren't reversed (a refunded organisation charge reverses
    // them), and invoice lines unless their invoice failed (no money collected) or the line's earning was reversed. A
    // line on an open or pending invoice counts: the work was billed with the fee waived.
    //
    // Unattributed: a waived amount stored with no waiver id. GenerateCounsellorEarningsAction writes these: when an
    // organisation charge's amount exceeds its breakdown and every line was waived, the surplus goes on the first line
    // as a fee taken and its waiver id is dropped while its waived amount stays (generateFromBreakdown); and a stored
    // waiver that no longer exists is recorded without its id (storedWaiver).
    private function waivedCommission(Closure $in): array
    {
        $earnings = $in(CounsellorEarning::query(), 'counsellor_earnings.created_at')
            ->whereNull('counsellor_earnings.organization_invoice_line_id')
            ->where('counsellor_earnings.status', '!=', CounsellorEarningStatusEnum::reversed->value)
            ->where('counsellor_earnings.waived_fee_amount', '>', 0)
            ->leftJoin('platform_fee_waivers', 'platform_fee_waivers.id', '=', 'counsellor_earnings.platform_fee_waiver_id');

        $lines = $in(OrganizationInvoiceLine::query(), 'organization_invoice_lines.created_at')
            ->join('organization_invoices', 'organization_invoices.id', '=', 'organization_invoice_lines.organization_invoice_id')
            ->where('organization_invoices.status', '!=', OrganizationInvoiceStatusEnum::failed->value)
            ->whereNotExists(fn ($query) => $query->selectRaw('1')
                ->from('counsellor_earnings')
                ->whereColumn('counsellor_earnings.organization_invoice_line_id', 'organization_invoice_lines.id')
                ->where('counsellor_earnings.status', CounsellorEarningStatusEnum::reversed->value))
            ->where('organization_invoice_lines.waived_fee_amount', '>', 0)
            ->leftJoin('platform_fee_waivers', 'platform_fee_waivers.id', '=', 'organization_invoice_lines.platform_fee_waiver_id');

        $scope = "case when platform_fee_waivers.id is null then 'unattributed' when platform_fee_waivers.counsellor_id is null then 'platformWide' else 'perCounsellor' end";
        $result = ['total' => [], 'platformWide' => [], 'perCounsellor' => [], 'unattributed' => []];

        foreach ([[$earnings, 'counsellor_earnings'], [$lines, 'organization_invoice_lines']] as [$query, $table]) {
            $rows = $query->toBase()
                ->selectRaw("{$table}.currency as currency, {$scope} as scope, sum({$table}.waived_fee_amount) as total")
                ->groupBy("{$table}.currency")
                ->groupByRaw($scope)
                ->get();

            foreach ($rows as $row) {
                foreach (['total', $row->scope] as $key) {
                    $result[$key][$row->currency] = ($result[$key][$row->currency] ?? 0) + (int) $row->total;
                }
            }
        }

        return array_map(function (array $totals) {
            ksort($totals);

            return $totals;
        }, $result);
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
