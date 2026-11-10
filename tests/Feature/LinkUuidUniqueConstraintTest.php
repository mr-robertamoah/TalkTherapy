<?php

use App\Enums\LinkStateEnum;
use App\Enums\LinkTypeEnum;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// SCRUM-135: links.uuid is used as a capability-token-style lookup (LinkController::performAction/
// LinkService::performAction via Link::where('uuid', ...)) but had no unique constraint at the
// database level. uuid is always generated via Str::uuid() in CreateLinkAction, so a real-world
// collision is vanishingly unlikely -- this locks in the constraint as defense-in-depth.

test('the DB enforces a unique uuid on links', function () {
    $uuid = (string) Str::uuid();

    DB::table('links')->insert([
        'uuid' => $uuid,
        'type' => LinkTypeEnum::therapyCounsellor->value,
        'state' => LinkStateEnum::active->value,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect(fn () => DB::table('links')->insert([
        'uuid' => $uuid,
        'type' => LinkTypeEnum::therapyCounsellor->value,
        'state' => LinkStateEnum::active->value,
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(UniqueConstraintViolationException::class);
});
