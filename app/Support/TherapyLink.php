<?php

namespace App\Support;

use App\Models\GroupTherapy;
use App\Models\Therapy;

// SCRUM-381: the page and the ids a notice about an individual therapy or a group points to (the
// pause/resume and continuation notices go to both kinds of counsellor).
final class TherapyLink
{
    public static function to(Therapy|GroupTherapy $therapy): string
    {
        return $therapy instanceof GroupTherapy
            ? route('group.therapies.get', ['groupTherapyId' => $therapy->id])
            : route('therapies.get', ['therapyId' => $therapy->id]);
    }

    public static function ids(Therapy|GroupTherapy $therapy): array
    {
        return $therapy instanceof GroupTherapy ? ['groupTherapyId' => $therapy->id] : ['therapyId' => $therapy->id];
    }

    // SCRUM-39: the link for ids() saved in a notification, or null (no look-up: the page checks access).
    public static function fromIds(array $data): ?string
    {
        return match (true) {
            isset($data['groupTherapyId']) => NotificationEntry::link('group.therapies.get', ['groupTherapyId' => $data['groupTherapyId']]),
            isset($data['therapyId']) => NotificationEntry::link('therapies.get', ['therapyId' => $data['therapyId']]),
            default => null,
        };
    }
}
