<?php

use App\Actions\Link\CreateLinkAction;
use App\Actions\Link\PerformGuardianshipLinkAction;
use App\DTOs\CreateLinkDTO;
use App\Enums\LinkStateEnum;
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

test('using a guardianship link deactivates it so it cannot be replayed (SCRUM-101)', function () {
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

    expect($link->fresh()->state)->toBe(LinkStateEnum::inactive->value);
});

test('using a guardianship link a second time (even by the same user) throws instead of an uncaught exception', function () {
    // Pre-SCRUM-101, this threw the domain-specific "already a guardian" error (via the
    // guardian_id/ward_id unique index). Now the link is deactivated after its first use, so a
    // repeat use of the SAME link hits the new "no longer active" gate first, before that
    // domain check is ever reached -- the link is single-use, full stop, regardless of who
    // reuses it.
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
    ))->toThrow(LinkException::class, 'This link is no longer active.');

    expect(Guardianship::count())->toBe(1);
});

test('a second, different user cannot also use a general guardianship link once it has been used (SCRUM-101)', function () {
    // A general link (to=null) can be used by any user, so the guardian_id/ward_id unique
    // index alone doesn't stop a second, DIFFERENT user from also becoming a guardian via the
    // same link. Loading $secondDTO->link BEFORE the first use commits, then only performing
    // the first use afterwards, proves the second use's active-state check re-reads the link
    // fresh under lock rather than trusting this stale, already-loaded model.
    $firstGuardian = User::factory()->create();
    $secondGuardian = User::factory()->create();
    $ward = User::factory()->create();

    $link = CreateLinkAction::new()->execute(
        CreateLinkDTO::new()->fromArray([
            'addedby' => $ward,
            'for' => $ward,
            'type' => LinkTypeEnum::guardianship->value,
        ])
    );

    $secondDTO = CreateLinkDTO::new()->fromArray([
        'user' => $secondGuardian,
        'link' => $link,
    ]);

    PerformGuardianshipLinkAction::new()->execute(
        CreateLinkDTO::new()->fromArray([
            'user' => $firstGuardian,
            'link' => $link,
        ])
    );

    expect(fn () => PerformGuardianshipLinkAction::new()->execute($secondDTO))
        ->toThrow(LinkException::class, 'This link is no longer active.');

    expect($secondGuardian->isGuardianOf($ward))->toBeFalse();
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
