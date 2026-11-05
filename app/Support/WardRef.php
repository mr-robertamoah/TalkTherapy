<?php

namespace App\Support;

use App\Models\User;

// SCRUM-384: an opaque reference to the person a stored notice names -- the minor in a counsellor's
// pause, resume and continuation notices, and (SCRUM-73) the member in "a member left your group" --
// kept in the notification's data under KEY, so that anonymising that person's closed account
// (AnonymiseClosedAccountAction) can find those rows and replace the name in them. An HMAC of the id
// with the app key, never the raw id (the SCRUM-345 rule): it can't be turned back into an id, or
// matched to the same person elsewhere without the key.
// No endpoint returns database notifications today; one that does must leave KEY out.
// Rotating APP_KEY: rows written before the rotation are still found while the old key is listed in
// APP_PREVIOUS_KEYS (allOf()); once it is dropped from there they no longer match.
final class WardRef
{
    public const KEY = 'wardRef';

    public static function of(User|int $person, ?string $key = null): string
    {
        $id = $person instanceof User ? $person->id : $person;

        return hash_hmac('sha256', "notification-ward:{$id}", $key ?? (string) config('app.key'));
    }

    // The reference under the current app key and under each previous one (config app.previous_keys).
    /** @return list<string> */
    public static function allOf(User|int $person): array
    {
        $keys = [(string) config('app.key'), ...array_map('strval', (array) config('app.previous_keys', []))];

        return array_values(array_unique(array_map(fn (string $key) => self::of($person, $key), $keys)));
    }
}
