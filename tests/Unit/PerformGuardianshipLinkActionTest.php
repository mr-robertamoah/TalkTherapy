<?php

use App\Actions\Link\CreateLinkAction;
use App\Actions\Link\PerformGuardianshipLinkAction;
use App\DTOs\CreateLinkDTO;
use App\Enums\LinkTypeEnum;
use App\Exceptions\LinkException;
use App\Models\Guardianship;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

// SCRUM-99: the guardianship(guardian_id, ward_id) unique index closes a duplicate-row race
// that isn't specific to the request-accept flow -- PerformGuardianshipLinkAction creates
// Guardianship rows too, and needed the same catch(UniqueConstraintViolationException)
// treatment so that race surfaces as the existing graceful LinkException, not an uncaught 500.

test('using a guardianship link establishes the relationship', function () {
    $guardian = User::factory()->create();
    $ward = User::factory()->create();

    $link = CreateLinkAction::new()->execute(
        CreateLinkDTO::new()->fromArray([
            'addedby' => $ward,
            'for' => $ward,
            'type' => LinkTypeEnum::guardianship->value,
        ])
    );

    PerformGuardianshipLinkAction::new()->execute(
        CreateLinkDTO::new()->fromArray([
            'user' => $guardian,
            'link' => $link,
        ])
    );

    expect($guardian->isGuardianOf($ward))->toBeTrue();
});

test('using a guardianship link a second time throws the existing "already a guardian" error, not an uncaught exception', function () {
    $guardian = User::factory()->create();
    $ward = User::factory()->create();

    $link = CreateLinkAction::new()->execute(
        CreateLinkDTO::new()->fromArray([
            'addedby' => $ward,
            'for' => $ward,
            'type' => LinkTypeEnum::guardianship->value,
        ])
    );

    PerformGuardianshipLinkAction::new()->execute(
        CreateLinkDTO::new()->fromArray([
            'user' => $guardian,
            'link' => $link,
        ])
    );

    expect(fn () => PerformGuardianshipLinkAction::new()->execute(
        CreateLinkDTO::new()->fromArray([
            'user' => $guardian,
            'link' => $link,
        ])
    ))->toThrow(LinkException::class, 'You are already a guardian of this user.');

    expect(Guardianship::count())->toBe(1);
});

test('the DB enforces a unique (guardian_id, ward_id) pair on guardianship', function () {
    $guardian = User::factory()->create();
    $ward = User::factory()->create();

    DB::table('guardianship')->insert([
        'guardian_id' => $guardian->id,
        'ward_id' => $ward->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect(fn () => DB::table('guardianship')->insert([
        'guardian_id' => $guardian->id,
        'ward_id' => $ward->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(UniqueConstraintViolationException::class);
});
