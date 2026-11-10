<?php

namespace App\Support;

use App\Enums\RequestTypeEnum;
use App\Models\User;

// SCRUM-387: the admin area's sidebar, defined once and already filtered for the viewer's role.
// Shared with every admin page (HandleInertiaRequestsV2); AdminLayout.vue only renders it.
// Each item: `key`, `label`, `route` (its name), `match` (the route names it is current for, as
// route().current() takes them) and `countKey` (its badge in GetAdminToDoCountsAction::badges(),
// or null for none).
class AdminNavigation
{
    /** @return array<int, array{key: string, label: string, items: array<int, array>}> */
    public static function for(?User $user): array
    {
        if (! $user?->isAdmin()) {
            return [];
        }

        $isSuper = $user->isSuperAdmin();

        return [
            ['key' => 'overview', 'label' => 'Overview', 'items' => [
                self::item('overview', 'Overview', 'administrator'),
            ]],
            ['key' => 'people', 'label' => 'People', 'items' => [
                self::item('users', 'Users', 'administrator.users'),
                self::item('counsellors', 'Counsellors', 'administrator.counsellors'),
                self::item('counsellor-verification', 'Counsellor verification', 'administrator.counsellor_verification', AdminRequestQueue::countKey(RequestTypeEnum::counsellor->value)),
            ]],
            ['key' => 'requests', 'label' => 'Requests to resolve', 'items' => [
                self::item('requests', 'Requests to resolve', 'administrator.requests', 'requests'),
            ]],
            ['key' => 'safety', 'label' => 'Safety', 'items' => array_values(array_filter([
                // SCRUM-33 (TT-4.4b): people waiting for urgent help (the badge is live).
                self::item('help-requests', 'Urgent help', 'administrator.help_requests', 'helpRequests'),
                // SCRUM-32: counsellors' safety flags (hidden until SAFETY_FLAGS_ENABLED; the badge is live).
                config('safety.enabled') ? self::item('safety-flags', 'Safety alerts', 'administrator.safety_flags', 'safetyAlerts') : null,
                // A report's own page counts as Reports.
                self::item('reports', 'Reports', 'administrator.reports', 'reports', 'administrator.reports*'),
                self::item('suspensions', 'Suspensions', 'administrator.suspensions', 'suspensionAppeals'),
                self::item('safeguarding-exceptions', 'Safeguarding exceptions', 'administrator.safeguarding_exceptions'),
                // SCRUM-33: what the "I need help now" dialog shows.
                self::item('emergency-numbers', 'Emergency numbers', 'administrator.emergency_numbers'),
                $isSuper ? self::item('read-log', 'Read log', 'administrator.content-accesses') : null,
            ]))],
            ['key' => 'money', 'label' => 'Money', 'items' => [
                self::item('payouts', 'Payouts', 'administrator.payouts', 'failedPayouts'),
                // Pending refund requests and failed refunds: the page lists both.
                self::item('refund-requests', 'Refund requests', 'administrator.refund_requests', 'refunds'),
                self::item('organization-billing', 'Organisation billing', 'administrator.organization_billing', 'organizationBilling'),
            ]],
            ['key' => 'content', 'label' => 'Content', 'items' => [
                self::item('testimonials', 'Testimonials', 'administrator.testimonials'),
                self::item('how-tos', 'How-tos', 'administrator.how_tos'),
            ]],
        ];
    }

    private static function item(string $key, string $label, string $route, ?string $countKey = null, ?string $match = null): array
    {
        return ['key' => $key, 'label' => $label, 'route' => $route, 'match' => $match ?? $route, 'countKey' => $countKey];
    }
}
