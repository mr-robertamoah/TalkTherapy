<?php

use App\Actions\Star\AwardCounsellorStarAction;
use App\Actions\Star\CountCounsellorStarsAction;
use App\Actions\Star\RevokeCounsellorStarsAction;
use App\Enums\StarSourceEnum;
use App\Enums\StarTypeEnum;
use App\Models\Counsellor;
use App\Models\Star;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

// TT-7.11a/SCRUM-470: the star foundation -- awarding, revoking and counting counsellor stars.

function sfdCounsellor(): Counsellor
{
    return Counsellor::factory()->create(['user_id' => User::factory()]);
}

// A star as the legacy call sites make it: no source, no award key, on whichever morph they star.
function sfdLegacyStar(Model $starred, string $at = 'now'): Star
{
    $star = Star::create(['type' => StarTypeEnum::contribution->value]);
    $star->starred()->associate($starred);
    $star->forceFill(['created_at' => Carbon::parse($at)])->save();

    return $star;
}

function sfdAward(Counsellor $counsellor, string $key, string $at = 'now', ?Model $starreable = null): ?Star
{
    $star = AwardCounsellorStarAction::new()->execute(
        $counsellor, StarSourceEnum::sessionHeld, $starreable ?? $counsellor->user, $key,
    );
    $star?->forceFill(['created_at' => Carbon::parse($at)])->save();

    return $star;
}

function sfdCount(?array $ids, ?string $from = null, ?string $to = null)
{
    return CountCounsellorStarsAction::new()->execute(
        $ids, $from ? Carbon::parse($from) : null, $to ? Carbon::parse($to) : null,
    );
}

// SCRUM-471: the legacy award points and CreateStarAction are gone; AwardCounsellorStarAction is the one writer.
test('nothing but AwardCounsellorStarAction writes stars', function () {
    expect(file_exists(app_path('Actions/Star/CreateStarAction.php')))->toBeFalse();

    $writers = collect(File::allFiles(app_path()))
        ->filter(fn ($file) => preg_match(
            '/Star::(query\(\)->)?(create|forceCreate|insert|firstOrCreate|updateOrCreate|make)\b|new Star\b|->(stars|starreable|starred)\(\)->(create|save|forceCreate)\b/',
            $file->getContents(),
        ))
        ->map(fn ($file) => str_replace(app_path().DIRECTORY_SEPARATOR, '', $file->getPathname()))
        ->values()
        ->all();

    expect($writers)->toBe(['Actions/Star/AwardCounsellorStarAction.php']);
});

test('an award stars the counsellor morph with its source, key and type', function () {
    $counsellor = sfdCounsellor();

    $star = AwardCounsellorStarAction::new()->execute(
        $counsellor, StarSourceEnum::postPublished, $counsellor->user, 'post_published:1',
    );

    expect($star->fresh())
        ->starred_type->toBe(Counsellor::class)
        ->starred_id->toBe($counsellor->id)
        ->source->toBe(StarSourceEnum::postPublished)
        ->award_key->toBe('post_published:1')
        ->type->toBe(StarTypeEnum::contribution->value)
        // No super administrator exists here, so nobody is recorded as giving it.
        ->starredby_type->toBeNull()
        ->starredby_id->toBeNull();
    expect(StarSourceEnum::sessionHeld->starType())->toBe(StarTypeEnum::participation)
        ->and(StarSourceEnum::discussionHeld->starType())->toBe(StarTypeEnum::participation)
        ->and(StarSourceEnum::postInteraction->starType())->toBe(StarTypeEnum::contribution);
});

test('awarding the same key twice keeps one star', function () {
    $counsellor = sfdCounsellor();

    expect(sfdAward($counsellor, 'session_held:7'))->toBeInstanceOf(Star::class)
        ->and(sfdAward($counsellor, 'session_held:7'))->toBeNull()
        ->and(Star::where('award_key', 'session_held:7')->count())->toBe(1);
});

test('each counsellor of the same event holds one star for its key', function () {
    $a = sfdCounsellor();
    $b = sfdCounsellor();

    expect(sfdAward($a, 'session_held:9'))->toBeInstanceOf(Star::class)
        ->and(sfdAward($b, 'session_held:9'))->toBeInstanceOf(Star::class)
        ->and(sfdAward($a, 'session_held:9'))->toBeNull()
        ->and(sfdAward($b, 'session_held:9'))->toBeNull()
        ->and(Star::where('award_key', 'session_held:9')->pluck('starred_id')->sort()->values()->all())
        ->toBe([$a->id, $b->id]);

    expect(RevokeCounsellorStarsAction::new()->forCounsellorAndKey($a, 'session_held:9'))->toBe(1)
        ->and(Star::where('award_key', 'session_held:9')->pluck('starred_id')->all())->toBe([$b->id]);
});

test('a revoked star can be awarded again, but never twice at once', function () {
    $counsellor = sfdCounsellor();
    sfdAward($counsellor, 'session_held:8');

    expect(RevokeCounsellorStarsAction::new()->forCounsellorAndKey($counsellor, 'session_held:8'))->toBe(1)
        ->and(Star::where('award_key', 'session_held:8')->exists())->toBeFalse();

    expect(sfdAward($counsellor, 'session_held:8'))->toBeInstanceOf(Star::class)
        ->and(sfdAward($counsellor, 'session_held:8'))->toBeNull()
        ->and(Star::where('award_key', 'session_held:8')->count())->toBe(1);
});

test('a bulk revoke removes the rule\'s stars for that thing and nothing else', function () {
    $counsellor = sfdCounsellor();
    $other = sfdCounsellor();
    $thing = User::factory()->create();
    $elsewhere = User::factory()->create();
    sfdAward($counsellor, 'session_held:a', starreable: $thing);
    sfdAward($other, 'session_held:a', starreable: $thing);
    sfdAward($counsellor, 'session_held:c', starreable: $elsewhere);
    AwardCounsellorStarAction::new()->execute($counsellor, StarSourceEnum::postInteraction, $thing, 'post_interaction:a');
    $legacy = sfdLegacyStar($counsellor);
    $legacy->starreable()->associate($thing)->save();

    expect(RevokeCounsellorStarsAction::new()->forStarreable(StarSourceEnum::sessionHeld, $thing))->toBe(2)
        ->and(Star::whereNotNull('award_key')->pluck('award_key')->sort()->values()->all())
        ->toBe(['post_interaction:a', 'session_held:c'])
        ->and($legacy->fresh())->not->toBeNull();
});

test('the new rules count only sourced counsellor-morph stars, per counsellor, in one query', function () {
    $a = sfdCounsellor();
    $b = sfdCounsellor();
    sfdAward($a, 'a1', '2026-12-03 10:00');
    sfdAward($a, 'a2', '2026-12-10 10:00');
    sfdAward($b, 'b1', '2026-12-05 10:00');
    sfdLegacyStar($a, '2026-12-04');        // legacy: no source
    sfdLegacyStar($a->user, '2026-12-04');  // the counsellor's User morph

    DB::enableQueryLog();
    $counts = sfdCount([$a->id, $b->id], '2026-12-01', '2027-01-01');
    expect(DB::getQueryLog())->toHaveCount(1);

    expect($counts[$a->id]['stars'])->toBe(2)
        ->and($counts[$a->id]['first_star_at']->toDateTimeString())->toBe('2026-12-03 10:00:00')
        ->and($counts[$b->id]['stars'])->toBe(1)
        ->and(sfdCount([$a->id])->get($a->id)['stars'])->toBe(2)
        ->and(sfdCount(null)->keys()->sort()->values()->all())->toBe([$a->id, $b->id]);
});

test('a counsellor with only legacy or user-morph stars has none under the new rules', function () {
    $c = sfdCounsellor();
    sfdLegacyStar($c);
    sfdLegacyStar($c->user);

    expect(sfdCount([$c->id]))->toBeEmpty()
        ->and(sfdCount([]))->toBeEmpty();
});

test('the window includes its start and excludes its end', function () {
    $c = sfdCounsellor();
    sfdAward($c, 'before', '2026-11-30 23:59:59');
    sfdAward($c, 'start', '2026-12-01 00:00:00');
    sfdAward($c, 'last', '2026-12-31 23:59:59');
    sfdAward($c, 'end', '2027-01-01 00:00:00');

    $count = sfdCount([$c->id], '2026-12-01', '2027-01-01')->get($c->id);

    expect($count['stars'])->toBe(2)
        ->and($count['first_star_at']->toDateTimeString())->toBe('2026-12-01 00:00:00')
        ->and(sfdCount([$c->id], null, '2026-12-01')->get($c->id)['stars'])->toBe(1)
        ->and(sfdCount([$c->id], '2027-01-01')->get($c->id)['stars'])->toBe(1);
});

test('stars_count_from is a floor on the window', function () {
    config(['recognition.stars_count_from' => '2026-12-01']);
    $c = sfdCounsellor();
    sfdAward($c, 'early', '2026-11-30 23:59:59');
    sfdAward($c, 'late', '2026-12-01 00:00:00');

    expect(sfdCount([$c->id])->get($c->id)['stars'])->toBe(1)
        ->and(sfdCount([$c->id], '2026-11-01')->get($c->id)['stars'])->toBe(1)
        ->and(sfdCount([$c->id], '2026-12-05')->get($c->id))->toBeNull();
});

test('a stars_count_from that is not Y-m-d is ignored with one warning', function (mixed $value) {
    Log::spy();
    config(['recognition.stars_count_from' => $value]);
    $c = sfdCounsellor();
    sfdAward($c, 'early', '2020-01-01');

    expect(sfdCount([$c->id])->get($c->id)['stars'])->toBe(1)
        ->and(sfdCount([$c->id])->get($c->id)['stars'])->toBe(1);

    Log::shouldHaveReceived('warning')->once();
})->with(['not a date', '2026-13-01', '2026-02-30', '2026-12-01 10:00', '01/12/2026', 5]);

test('the migrations roll back and run again', function () {
    $migration = include database_path('migrations/2026_12_13_100000_add_source_and_award_key_to_stars_table.php');
    // SCRUM-471's index on source and starreable, which has to go first.
    $revokeIndex = include database_path('migrations/2026_12_13_200000_add_starreable_source_index_to_stars_table.php');

    $revokeIndex->down();
    expect(Schema::hasIndex('stars', 'stars_source_starreable_index'))->toBeFalse();
    $migration->down();
    expect(Schema::hasColumns('stars', ['source', 'award_key']))->toBeFalse()
        ->and(Schema::hasIndex('stars', 'stars_period_count_index'))->toBeFalse()
        ->and(Schema::hasIndex('stars', 'stars_award_key_unique'))->toBeFalse();

    $migration->up();
    expect(Schema::hasColumns('stars', ['source', 'award_key']))->toBeTrue()
        ->and(Schema::hasIndex('stars', 'stars_period_count_index'))->toBeTrue()
        ->and(Schema::hasIndex('stars', ['starred_type', 'starred_id', 'award_key'], 'unique'))->toBeTrue();

    $revokeIndex->up();
    expect(Schema::hasIndex('stars', ['source', 'starreable_type', 'starreable_id']))->toBeTrue();
});
