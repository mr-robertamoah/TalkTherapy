<?php

namespace App\Support;

use App\Models\Counsellor;
use App\Models\User;

// SCRUM-113: what a viewer is matched on, as plain id lists (never logged, sent or cached -- religion
// is sensitive). Whole ids only, without repeats, at most PreferenceRanking::MAX_PER_KIND each, so
// older stored data can't grow a query.
// - forUser(): a person's saved preferences (users.settings), matched against what a therapy, group or
//   counsellor has.
// - forCounsellorProfile() (the user's decisions, 2026-11-07): a counsellor browsing therapies and
//   groups, matched on their own cases against the therapy's, and their languages against the
//   client's saved languages -- never religions (special-category data a counsellor could otherwise
//   work out by changing theirs and watching the label).
final class PreferenceMatch
{
    private function __construct(
        public readonly array $cases,
        public readonly array $languages,
        public readonly array $religions,
        public readonly bool $againstClientPreferences = false,
    ) {}

    public static function none(): self
    {
        return new self([], [], []);
    }

    public static function forUser(?User $user): self
    {
        $settings = is_array($user?->settings) ? $user->settings : [];

        return new self(self::ids($settings['cases'] ?? []), self::ids($settings['languages'] ?? []), self::ids($settings['religions'] ?? []));
    }

    public static function forCounsellorProfile(Counsellor $counsellor): self
    {
        return new self(
            self::ids($counsellor->cases()->pluck('therapy_cases.id')->all()),
            self::ids($counsellor->languages()->pluck('languages.id')->all()),
            [],
            againstClientPreferences: true,
        );
    }

    // Therapies and groups: a counsellor viewer by their profile, anyone else by their preferences.
    public static function forTherapiesViewedBy(?User $viewer): self
    {
        return $viewer?->counsellor ? self::forCounsellorProfile($viewer->counsellor) : self::forUser($viewer);
    }

    public function isEmpty(): bool
    {
        return ! $this->cases && ! $this->languages && ! $this->religions;
    }

    private static function ids(mixed $ids): array
    {
        $ids = array_filter(is_array($ids) ? $ids : [], fn ($id) => is_int($id) || (is_string($id) && ctype_digit($id)));

        return array_slice(array_values(array_unique(array_map('intval', $ids))), 0, PreferenceRanking::MAX_PER_KIND);
    }
}
