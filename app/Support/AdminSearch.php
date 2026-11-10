<?php

namespace App\Support;

use App\Models\User;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

// SCRUM-390: one search for every admin list page (?search=). Short or empty text searches nothing;
// longer than MAX is refused. A page matches a person's names, username (and email where the page
// shows it) -- details any administrator can already see on People -- never a masked or narrowed
// field, with whereContains (wildcards matched literally, ORs grouped so they can't escape the page's
// other filters). A row the viewer only sees as an "involved" summary never matches a search
// (withoutInvolved()): otherwise a name search would confirm who is in a case they're kept out of.
final class AdminSearch
{
    public const MIN = 2;

    public const MAX = 100;

    // How many matches the involvement check reads before asking for a narrower search.
    public const CAP = 200;

    // The page's search text, or null for none. More than MAX characters is refused (422).
    public static function term(Request $request): ?string
    {
        $value = $request->input('search');

        if (! is_null($value) && (! is_string($value) || mb_strlen($value) > self::MAX)) {
            throw ValidationException::withMessages(['search' => 'Search for at most '.self::MAX.' characters.']);
        }

        $term = trim((string) $value);

        return mb_strlen($term) >= self::MIN ? $term : null;
    }

    // A person's names and username (the columns every admin list shows of them).
    public static function person(Builder $query, string $term, bool $withEmail = false): Builder
    {
        return $query->whereContains(['firstName', 'lastName', 'otherNames', 'username', ...($withEmail ? ['email'] : [])], $term);
    }

    // Narrows $query (already searched) to the matches $viewer isn't involved in ($involves($row,
    // $viewer), the page's own definition), reading at most CAP of them. Returns whether there were
    // more than CAP (the page then asks for a narrower search).
    public static function withoutInvolved(Builder $query, User $viewer, Closure $involves): bool
    {
        $table = $query->getModel()->getTable();
        // Newest first, so which rows are read is stable when there are more than CAP.
        $rows = (clone $query)->reorder("{$table}.id", 'desc')->limit(self::CAP + 1)->get();
        $ids = $rows->take(self::CAP)->reject(fn ($row) => $involves($row, $viewer))->pluck('id');

        $query->whereIn("{$table}.id", $ids->all());

        return $rows->count() > self::CAP;
    }
}
