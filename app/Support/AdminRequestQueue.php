<?php

namespace App\Support;

use App\Enums\RequestTypeEnum;
use App\Models\User;

// SCRUM-387: the request types waiting on an administrator -- the admin area's "requests to resolve"
// queue, its counts and badges, and the only types an administrator may answer without being a
// party to them (EnsureUserCanRespondToRequestAction). Defined once here; everything else reads it.
//
// Each type:
// - `label`: the queue's name for one row of it;
// - `countKey`: its count in GetAdminToDoCountsAction (and badge, where it has one);
// - `countLabel`: the overview's name for that count;
// - `page`: the type's own page beside the queue (a route name and link text), or null;
// - `superOnly`: only super administrators see and answer it;
// - `unaddressedOnly`: only a request with no `to` waits on an administrator (a dob change with a
//   guardian is the guardian's). Every other type here waits on them whoever its `to` is: the two
//   verification types are addressed to the first super administrator when created but every
//   administrator reviews them (the user's decision), and the rest never have a `to`.
final class AdminRequestQueue
{
    private const TYPES = [
        RequestTypeEnum::counsellor->value => [
            'label' => 'Counsellor verification',
            'countKey' => 'counsellorVerifications',
            'countLabel' => 'Counsellor verifications',
            'page' => ['route' => 'administrator.counsellor_verification', 'label' => 'Open counsellor verification'],
            'superOnly' => false,
            'unaddressedOnly' => false,
        ],
        RequestTypeEnum::organization->value => [
            'label' => 'Organisation verification',
            'countKey' => 'organizationVerifications',
            'countLabel' => 'Organisation verifications',
            'page' => null,
            'superOnly' => false,
            'unaddressedOnly' => false,
        ],
        RequestTypeEnum::ageVerification->value => [
            'label' => 'Age verification',
            'countKey' => 'ageVerifications',
            'countLabel' => 'Age verifications (not your own)',
            'page' => null,
            'superOnly' => false,
            'unaddressedOnly' => false,
        ],
        RequestTypeEnum::dobChange->value => [
            'label' => 'Date-of-birth change (no guardian)',
            'countKey' => 'dobChanges',
            'countLabel' => 'Date-of-birth changes with no guardian',
            'page' => null,
            'superOnly' => false,
            'unaddressedOnly' => true,
        ],
        RequestTypeEnum::refund->value => [
            'label' => 'Refund',
            'countKey' => 'refundRequests',
            'countLabel' => 'Refund requests',
            'page' => ['route' => 'administrator.refund_requests', 'label' => 'Open refund requests'],
            'superOnly' => false,
            'unaddressedOnly' => false,
        ],
        RequestTypeEnum::safeguardingException->value => [
            'label' => 'Safeguarding exception',
            'countKey' => 'safeguardingRequests',
            'countLabel' => 'Safeguarding requests',
            'page' => null,
            'superOnly' => true,
            'unaddressedOnly' => false,
        ],
    ];

    /** @return array<int, string> every queue type */
    public static function types(): array
    {
        return array_keys(self::TYPES);
    }

    public static function includes(?string $type): bool
    {
        return ! is_null($type) && array_key_exists($type, self::TYPES);
    }

    /** @return array<int, string> the types every administrator sees (no super-only ones) */
    public static function sharedTypes(): array
    {
        return array_keys(array_filter(self::TYPES, fn (array $type) => ! $type['superOnly']));
    }

    /** @return array<int, string> the types $viewer may see (none for a non-admin) */
    public static function typesFor(?User $viewer): array
    {
        if (! $viewer?->isAdmin()) {
            return [];
        }

        return $viewer->isSuperAdmin() ? self::types() : self::sharedTypes();
    }

    /** @return array<int, string> of $types, the ones only a request with no `to` counts for */
    public static function unaddressedOnly(array $types): array
    {
        return array_values(array_filter($types, fn (string $type) => self::TYPES[$type]['unaddressedOnly'] ?? false));
    }

    public static function label(string $type): ?string
    {
        return self::TYPES[$type]['label'] ?? null;
    }

    public static function countKey(string $type): ?string
    {
        return self::TYPES[$type]['countKey'] ?? null;
    }

    public static function countLabel(string $type): ?string
    {
        return self::TYPES[$type]['countLabel'] ?? null;
    }

    /** @return ?array{route: string, label: string} */
    public static function page(string $type): ?array
    {
        return self::TYPES[$type]['page'] ?? null;
    }

    /** @return array<string, string> count key => type, for $types (default: every type) */
    public static function countKeys(?array $types = null): array
    {
        $types ??= self::types();

        return array_combine(
            array_map(fn (string $type) => self::TYPES[$type]['countKey'], $types),
            $types,
        );
    }

    /**
     * What the queue page needs to show each type it may list: its value, label and own page.
     *
     * @return array<int, array{value: string, label: string, page: ?array}>
     */
    public static function optionsFor(User $viewer): array
    {
        return array_map(
            fn (string $type) => ['value' => $type, 'label' => self::label($type), 'page' => self::page($type)],
            self::typesFor($viewer),
        );
    }
}
