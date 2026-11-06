<?php

use App\Actions\User\AnonymiseClosedAccountAction;
use App\Models\DatabaseNotification;
use App\Models\User;
use App\Notifications\ClientAccessPausedNotification;
use App\Notifications\GroupTherapyMemberLeftNotification;
use App\Support\WardRef;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// SCRUM-386: notifications.ward_ref is generated from data's WardRef, and anonymising a closed account
// finds the notices naming them through it rather than a LIKE over data. SCRUM-387: neither the
// column nor the data key is ever serialised. Helpers are file-local and nwrc-prefixed (--parallel).
// The column's tests also run on MySQL in CI (the `mysql-triggers` group, .github/workflows/ci.yml),
// where it is a stored column with its own expression.

function nwrcInsert(User $notifiable, string $type, string $data): string
{
    $id = (string) Str::uuid();
    DB::table('notifications')->insert([
        'id' => $id, 'type' => $type, 'notifiable_type' => User::class, 'notifiable_id' => $notifiable->id,
        'data' => $data, 'created_at' => now(), 'updated_at' => now(),
    ]);

    return $id;
}

test('ward_ref holds the notice\'s WardRef, and is null without one or for unreadable data', function () {
    $notifiable = User::factory()->adult()->create();
    $member = User::factory()->adult()->create();

    $withRef = nwrcInsert($notifiable, GroupTherapyMemberLeftNotification::class, json_encode(['memberName' => 'A', WardRef::KEY => WardRef::of($member)]));
    $withoutRef = nwrcInsert($notifiable, GroupTherapyMemberLeftNotification::class, json_encode(['memberName' => 'B']));
    $unreadable = nwrcInsert($notifiable, ClientAccessPausedNotification::class, '{"wardRef":"'.WardRef::of($member).'", not json');

    $refs = DB::table('notifications')->pluck('ward_ref', 'id');

    expect($refs[$withRef])->toBe(WardRef::of($member))
        ->and($refs[$withoutRef])->toBeNull()
        ->and($refs[$unreadable])->toBeNull();
})->group('mysql-triggers');

test('ward_ref is null, not the string "null", for a JSON null WardRef', function () {
    $notifiable = User::factory()->adult()->create();
    $id = nwrcInsert($notifiable, GroupTherapyMemberLeftNotification::class, json_encode(['memberName' => 'A', WardRef::KEY => null]));

    expect(DB::table('notifications')->where('id', $id)->value('ward_ref'))->toBeNull();
})->group('mysql-triggers');

test('anonymising a closed account looks its notices up by ward_ref, not by scanning data', function () {
    $notifiable = User::factory()->adult()->create();
    $member = User::factory()->adult()->create();
    $id = nwrcInsert($notifiable, GroupTherapyMemberLeftNotification::class, json_encode(['memberName' => 'Leaving', WardRef::KEY => WardRef::of($member)]));

    DB::enableQueryLog();
    expect(AnonymiseClosedAccountAction::new()->execute($member->fresh()))->toBeTrue();
    $lookups = collect(DB::getQueryLog())->pluck('query')->filter(fn (string $query) => preg_match('/from [`"]notifications[`"]/', $query) === 1 && str_contains($query, 'select'));
    DB::disableQueryLog();

    expect(json_decode(DB::table('notifications')->where('id', $id)->value('data'), true)['memberName'])->toBe(AnonymiseClosedAccountAction::CLOSED_ACCOUNT_NAME)
        ->and($lookups)->not->toBeEmpty()
        ->and($lookups->every(fn (string $query) => preg_match('/[`"]ward_ref[`"] in/', $query) === 1 && ! str_contains($query, ' like ')))->toBeTrue();
})->group('mysql-triggers');

test('a stored notice never serialises its WardRef, as the column or in its data', function () {
    $notifiable = User::factory()->adult()->create();
    $member = User::factory()->adult()->create();
    nwrcInsert($notifiable, GroupTherapyMemberLeftNotification::class, json_encode(['memberName' => 'A', WardRef::KEY => WardRef::of($member)]));

    $notice = $notifiable->notifications()->first();

    expect($notice)->toBeInstanceOf(DatabaseNotification::class)
        ->and($notice->ward_ref)->toBe(WardRef::of($member))
        ->and($notice->toArray())->not->toHaveKey('ward_ref')
        ->and($notice->toArray()['data'])->toBe(['memberName' => 'A'])
        ->and($notice->toJson())->not->toContain(WardRef::of($member))
        ->and($notifiable->notifications->toJson())->not->toContain(WardRef::of($member))
        ->and($notifiable->unreadNotifications->first())->toBeInstanceOf(DatabaseNotification::class);
});
