<?php

namespace App\Actions\Admin;

use App\Actions\Action;
use App\Actions\Guardianship\FindMinorsWithoutActiveGuardianAction;
use App\Actions\Request\EnsureUserCanRespondToRequestAction;
use App\Actions\Request\PreloadRequestListAction;
use App\Actions\SafeguardingException\EnsureCanManageSafeguardingExceptionAction;
use App\Actions\SafeguardingException\FindNoLongerNeededSafeguardingRequestsAction;
use App\Enums\CounsellorEarningStatusEnum;
use App\Enums\CounsellorPayoutStatusEnum;
use App\Enums\RefundStatusEnum;
use App\Enums\ReportStatusEnum;
use App\Enums\RequestTypeEnum;
use App\Models\CounsellorPayout;
use App\Models\Organization;
use App\Models\Refund;
use App\Models\Report;
use App\Models\Request;
use App\Models\Suspension;
use App\Models\User;
use App\Support\AdminRequestQueue;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// SCRUM-387 (+ SCRUM-49): what is waiting on the administrators -- the admin overview's to-do list
// and the sidebar's badges. Each count is only worked out for a viewer allowed to see it.
//
// Caching: the counts every administrator shares are cached for a minute under one key, worked out
// with no viewer at all (the super-only request types are left out by construction), so it can't
// differ by who filled it; the super administrators' own counts have their own key. A count that
// depends on who is looking (an admin's own age verification is left out; the super administrator's
// "you can answer" depends on whom they counsel) is worked out every time or cached for that one
// person. Every one of those keys carries a generation that forget() moves on -- the Request model
// calls it when an admin queue request is created or changes status -- so a new or answered request
// shows straight away. The paused-minors count is expensive, so it is on the overview only (never a
// badge), cached for five minutes outside the generation: answering a request doesn't change it.
class GetAdminToDoCountsAction extends Action
{
    private const GENERATION_KEY = 'admin-todo:generation';

    private const PAUSED_MINORS_KEY = 'admin-todo:paused-minors';

    private const TTL_SECONDS = 60;

    private const PAUSED_MINORS_TTL_SECONDS = 300;

    public static function forget(): void
    {
        Cache::forever(self::GENERATION_KEY, Str::random(12));
    }

    /**
     * The overview's to-do list: each item's count, what it is and where it is resolved (a route and
     * its pre-filter). Empty for a non-admin.
     *
     * @return array<int, array{key: string, label: string, count: int, route: ?string, params: array}>
     */
    public function execute(User $viewer): array
    {
        if (! $viewer->isAdmin()) {
            return [];
        }

        $counts = $this->counts($viewer, overview: true);

        // A queue type's count goes to its own page when it has one, else to the queue filtered to it.
        $requestItems = array_map(function (string $type) {
            $page = AdminRequestQueue::page($type);

            return [
                'key' => AdminRequestQueue::countKey($type),
                'label' => AdminRequestQueue::countLabel($type),
                'route' => $page['route'] ?? 'administrator.requests',
                'params' => $page ? [] : ['type' => $type],
            ];
        }, AdminRequestQueue::sharedTypes());

        $items = [
            ...$requestItems,
            ['key' => 'reports', 'label' => 'Reports open or under review', 'route' => 'administrator.reports', 'params' => []],
            ['key' => 'suspensionAppeals', 'label' => 'Suspension appeals awaiting a decision', 'route' => 'administrator.suspensions', 'params' => []],
            ['key' => 'pausedMinors', 'label' => 'Paused minors with an open therapy and no exception', 'route' => 'administrator.safeguarding_exceptions', 'params' => []],
            ['key' => 'failedPayouts', 'label' => 'Failed payouts not yet paid again', 'route' => 'administrator.payouts', 'params' => []],
            ['key' => 'failedRefunds', 'label' => 'Failed refunds', 'route' => 'administrator.refund_requests', 'params' => []],
            ['key' => 'organizationBilling', 'label' => 'Organisations billing-suspended or with a failed invoice', 'route' => 'administrator.organization_billing', 'params' => []],
        ];

        if ($viewer->isSuperAdmin()) {
            $queue = ['route' => 'administrator.requests', 'params' => ['type' => RequestTypeEnum::safeguardingException->value]];

            $items = [
                ...$items,
                ['key' => 'safeguardingAnswerable', 'label' => 'Safeguarding requests you can answer', ...$queue],
                ['key' => 'safeguardingInvolved', 'label' => 'Safeguarding requests you are involved in (another super administrator decides)', ...$queue],
                ['key' => 'safeguardingNoLongerNeeded', 'label' => 'Safeguarding requests no longer needed', 'route' => 'administrator.safeguarding_exceptions', 'params' => []],
                ['key' => 'failedJobs', 'label' => 'Failed background jobs', 'route' => null, 'params' => []],
            ];
        }

        return array_map(fn (array $item) => [...$item, 'count' => $counts[$item['key']] ?? 0], $items);
    }

    /**
     * The sidebar's badges: the cheap counts only. `requests` is everything in the queue still
     * pending that the viewer may see; `refunds` is the refund page's pending refund requests and
     * failed refunds together. Empty for a non-admin.
     *
     * @return array<string, int>
     */
    public function badges(User $viewer): array
    {
        if (! $viewer->isAdmin()) {
            return [];
        }

        $counts = $this->counts($viewer);
        $counsellorVerifications = AdminRequestQueue::countKey(RequestTypeEnum::counsellor->value);

        return [
            'requests' => array_sum(array_intersect_key($counts, AdminRequestQueue::countKeys(AdminRequestQueue::typesFor($viewer)))),
            $counsellorVerifications => $counts[$counsellorVerifications],
            'refunds' => $counts['refundRequests'] + $counts['failedRefunds'],
            'reports' => $counts['reports'],
            'suspensionAppeals' => $counts['suspensionAppeals'],
            'failedPayouts' => $counts['failedPayouts'],
            'organizationBilling' => $counts['organizationBilling'],
        ];
    }

    /** @return array<string, int> */
    public function counts(User $viewer, bool $overview = false): array
    {
        if (! $viewer->isAdmin()) {
            return [];
        }

        $generation = Cache::rememberForever(self::GENERATION_KEY, fn () => Str::random(12));

        $counts = Cache::remember("admin-todo:{$generation}:shared", self::TTL_SECONDS, fn () => $this->sharedCounts());

        // Person-scoped, so never under the shared key: the viewer's own age verification isn't
        // theirs to answer (EnsureUserCanRespondToRequestAction), so it isn't on their list.
        $counts['ageVerifications'] = max(0, $counts['ageVerifications'] - $this->ownPendingAgeVerifications($viewer));

        if ($viewer->isSuperAdmin()) {
            $counts = [
                ...$counts,
                ...Cache::remember("admin-todo:{$generation}:super", self::TTL_SECONDS, fn () => $this->superCounts()),
                ...Cache::remember("admin-todo:{$generation}:user:{$viewer->id}", self::TTL_SECONDS, fn () => $this->safeguardingSplit($viewer)),
            ];
        }

        if ($overview) {
            $counts['pausedMinors'] = Cache::remember(
                self::PAUSED_MINORS_KEY,
                self::PAUSED_MINORS_TTL_SECONDS,
                fn () => FindMinorsWithoutActiveGuardianAction::new()->withOpenTherapy()->count(),
            );
        }

        return $counts;
    }

    // The same for every administrator, and worked out without one: the admin queue's types every
    // administrator sees (Request::scopeOfAdminQueueTypes(), the queue's own rule) and the rest.
    private function sharedCounts(): array
    {
        $countKeys = AdminRequestQueue::countKeys(AdminRequestQueue::sharedTypes());

        $byType = Request::query()
            ->ofAdminQueueTypes(array_values($countKeys))
            ->wherePending()
            ->selectRaw('type, count(*) as aggregate')
            ->groupBy('type')
            ->pluck('aggregate', 'type');

        return [
            ...array_map(fn (string $type) => (int) ($byType[$type] ?? 0), $countKeys),
            'reports' => Report::query()->whereIn('status', [ReportStatusEnum::open->value, ReportStatusEnum::under_review->value])->count(),
            'suspensionAppeals' => Suspension::query()->whereNotNull('appealed_at')->whereNull('appeal_decided_at')->count(),
            // A failed payout's earnings go back to pending but stay pointing at it until a later
            // payout claims them, so "still has pending earnings" means it hasn't been paid again yet.
            'failedPayouts' => CounsellorPayout::query()
                ->where('status', CounsellorPayoutStatusEnum::failed->value)
                ->whereHas('earnings', fn ($query) => $query->where('status', CounsellorEarningStatusEnum::pending->value))
                ->count(),
            // Nothing retries a failed refund (RecordRefundStatusAction), so each one waits on a
            // person; the refund requests page lists them.
            'failedRefunds' => Refund::query()->where('status', RefundStatusEnum::failed->value)->count(),
            // The organisation billing page lists both.
            'organizationBilling' => Organization::query()->needsBillingAttention()->count(),
        ];
    }

    private function ownPendingAgeVerifications(User $viewer): int
    {
        return Request::query()->ownAgeVerificationOf($viewer)->wherePending()->count();
    }

    // Super administrators only, and the same for each of them.
    private function superCounts(): array
    {
        return [
            AdminRequestQueue::countKey(RequestTypeEnum::safeguardingException->value) => Request::query()->pendingSafeguardingException()->count(),
            // The safeguarding page's own list (FindNoLongerNeededSafeguardingRequestsAction).
            'safeguardingNoLongerNeeded' => FindNoLongerNeededSafeguardingRequestsAction::new()->execute()->count(),
            // SCRUM-49's "system health".
            'failedJobs' => DB::table('failed_jobs')->count(),
        ];
    }

    // Super administrators only, and person-scoped: one who counsels the minor (or sent the
    // request) can't answer it -- the same answer the queue's buttons get.
    private function safeguardingSplit(User $viewer): array
    {
        $pending = PreloadRequestListAction::new()->execute(Request::query()->pendingSafeguardingException()->get());
        $manage = EnsureCanManageSafeguardingExceptionAction::new()->remembering();
        $respond = EnsureUserCanRespondToRequestAction::new();
        $answerable = $pending->filter(fn (Request $request) => $respond->userCanRespond($viewer, $request, $manage))->count();

        return [
            'safeguardingAnswerable' => $answerable,
            'safeguardingInvolved' => $pending->count() - $answerable,
        ];
    }
}
