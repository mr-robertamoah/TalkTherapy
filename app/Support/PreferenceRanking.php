<?php

namespace App\Support;

use App\Enums\CounsellorGroupTherapyStateEnum;
use App\Models\Counsellor;
use App\Models\GroupTherapy;
use App\Models\Therapy;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

// SCRUM-113 (the user's decisions): soft ranking -- everything still shows; what matches the viewer
// comes first, more matched kinds (cases, languages, religions; one each) higher. Worked out in SQL
// before paging, then a seeded order that stays the same for a visit (?seed=), so "load more" never
// repeats or skips a row. Guests and people with no preferences get the seeded order alone.
// The match count is a select (preference_matches); resources send only whether it matched.
final class PreferenceRanking
{
    public const MAX_PER_KIND = 20;

    public const COLUMN = 'preference_matches';

    private const PRIME = 1000003;

    // The visit's seed: the client's (?seed=, 1 to 2^31-1), else a new one.
    public static function seed(mixed $value): int
    {
        $seed = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 2147483647]]);

        return $seed === false ? random_int(1, 2147483647) : $seed;
    }

    public static function apply(Builder $query, PreferenceMatch $match, int $seed): Builder
    {
        $model = $query->getModel();
        $table = $model->getTable();

        if (! $match->isEmpty()) {
            [$sql, $bindings] = self::score($model, $table, $match);

            // The row's own columns too (a raw select alone would replace them).
            if (is_null($query->getQuery()->columns)) {
                $query->select("{$table}.*");
            }

            $query->selectRaw("({$sql}) as ".self::COLUMN, $bindings)->orderByDesc(self::COLUMN);
        }

        return $query->orderByRaw(self::sortKey("{$table}.id", $seed))->orderBy("{$table}.id");
    }

    // The seeded order's key for an id column -- also used by unions (GetPublicTherapyFeedAction).
    public static function sortKey(string $idColumn, int $seed): string
    {
        // In the middle of the range, so small multipliers can't leave the order looking like ids
        // (reviewer finding).
        $quarter = intdiv(self::PRIME, 4);
        $multiplier = $quarter + ($seed % (2 * $quarter));

        return "(({$idColumn} * {$multiplier}) % ".self::PRIME.')';
    }

    /** @return array{0: string, 1: array} the match count's SQL and bindings */
    public static function score($model, string $table, PreferenceMatch $match): array
    {
        $parts = [];
        $bindings = [];
        $add = function (?array $part) use (&$parts, &$bindings) {
            if ($part) {
                $parts[] = "(case when exists ({$part[0]}) then 1 else 0 end)";
                array_push($bindings, ...$part[1]);
            }
        };

        $counsellorType = Counsellor::class;

        if ($model instanceof Counsellor) {
            $add(self::pivot('caseables', 'case_id', 'caseable', $counsellorType, "{$table}.id", $match->cases));
            $add(self::pivot('languageables', 'language_id', 'languageable', $counsellorType, "{$table}.id", $match->languages));
            $add(self::pivot('religionables', 'religion_id', 'religionable', $counsellorType, "{$table}.id", $match->religions));
        } elseif ($model instanceof Therapy || $model instanceof GroupTherapy) {
            $add(self::pivot('caseables', 'case_id', 'caseable', $model::class, "{$table}.id", $match->cases));

            if ($match->againstClientPreferences) {
                // Never the client's religions (security review, the user's decision 2026-11-07): a
                // counsellor could change theirs and watch the label to learn a client's religion.
                $add(self::clientPreference($table, 'languages', $match->languages));
            } else {
                foreach ([['languageables', 'language_id', 'languageable', $match->languages], ['religionables', 'religion_id', 'religionable', $match->religions]] as [$pivot, $column, $morph, $ids]) {
                    $add($model instanceof Therapy
                        ? self::pivot($pivot, $column, $morph, $counsellorType, "{$table}.counsellor_id", $ids)
                        : self::groupCounsellors($table, $pivot, $column, $morph, $ids));
                }
            }
        } else {
            throw new InvalidArgumentException('PreferenceRanking ranks therapies, groups and counsellors only.');
        }

        return [$parts ? implode(' + ', $parts) : '0', $bindings];
    }

    // A row in $pivot for $ownerColumn with one of $ids.
    private static function pivot(string $pivot, string $idColumn, string $morph, string $type, string $ownerColumn, array $ids): ?array
    {
        if (! $ids) {
            return null;
        }

        $placeholders = implode(', ', array_fill(0, count($ids), '?'));

        return ["select 1 from {$pivot} p where p.{$morph}_type = ? and p.{$morph}_id = {$ownerColumn} and p.{$idColumn} in ({$placeholders})", [$type, ...$ids]];
    }

    // One of a group's counsellors -- an active one, or a counsellor creator -- has one of $ids. Two
    // halves, each able to use the morph index (devops review: one OR kept it from doing so).
    private static function groupCounsellors(string $table, string $pivot, string $idColumn, string $morph, array $ids): ?array
    {
        if (! $ids) {
            return null;
        }

        $placeholders = implode(', ', array_fill(0, count($ids), '?'));
        $type = Counsellor::class;

        return [
            "select 1 from counsellor_group_therapy cgt join {$pivot} p on p.{$morph}_type = ? and p.{$morph}_id = cgt.counsellor_id"
            ." where cgt.group_therapy_id = {$table}.id and cgt.state = ? and p.{$idColumn} in ({$placeholders})"
            ." union all select 1 from {$pivot} p where {$table}.addedby_type = ? and p.{$morph}_type = ? and p.{$morph}_id = {$table}.addedby_id and p.{$idColumn} in ({$placeholders})",
            [$type, CounsellorGroupTherapyStateEnum::active->value, ...$ids, $type, $type, ...$ids],
        ];
    }

    // The client's (the therapy's or group's creator, a User) saved $kind holds one of $ids.
    private static function clientPreference(string $table, string $kind, array $ids): ?array
    {
        if (! $ids) {
            return null;
        }

        $placeholders = implode(', ', array_fill(0, count($ids), '?'));
        $owner = "u.id = {$table}.addedby_id and {$table}.addedby_type = ?";

        $sql = DB::connection()->getDriverName() === 'sqlite'
            ? "select 1 from users u, json_each(u.settings, '$.{$kind}') je where {$owner} and cast(je.value as integer) in ({$placeholders})"
            : "select 1 from users u, json_table(coalesce(json_extract(u.settings, '$.{$kind}'), json_array()), '$[*]' columns (v bigint path '$')) jt where {$owner} and jt.v in ({$placeholders})";

        return [$sql, [User::class, ...$ids]];
    }
}
