<?php

use App\Actions\Message\EnsureUserCanAccessTherapyContentAction;
use App\Enums\CounsellorGroupTherapyStateEnum;
use App\Models\Administrator;
use App\Models\Counsellor;
use App\Models\GroupTherapy;
use App\Models\Session;
use App\Models\Transaction;
use App\Models\User;

// TT-7.5b-b3/SCRUM-267: widens the shared session/topic/reply content-access check to actually
// gate GroupTherapy (previously it only ever recognized `$therapy instanceof Therapy`, so a
// strict-gated GroupTherapy's chat content stayed fully reachable through this action regardless
// of payment status -- the exact gap TT-7.5b-b2's page-load gate didn't close). Also covers the
// late-joiner "historical content" exemption this same ticket introduces.

function strictGatedPaidGroupTherapyForContent(array $overrides = []): GroupTherapy
{
    return GroupTherapy::factory()->create(array_merge([
        'addedby_type' => User::class,
        'addedby_id' => User::factory(),
        'public' => false,
        'payment_type' => 'PAID',
        'payment_data' => ['per' => 'PER_THERAPY', 'amount' => 50, 'currency' => 'GHS', 'inPersonAmount' => 60, 'strictPaymentGate' => true, 'allowFreeHistoricalAccess' => true],
    ], $overrides));
}

test('a joined member with no grant or successful transaction is denied content access', function () {
    $groupTherapy = strictGatedPaidGroupTherapyForContent();
    $member = User::factory()->create();
    $groupTherapy->users()->attach($member->id, ['anonymous' => false]);

    expect(EnsureUserCanAccessTherapyContentAction::new()->execute($groupTherapy, $member))->toBeFalse();
});

test('a joined member with a successful transaction is granted content access', function () {
    $groupTherapy = strictGatedPaidGroupTherapyForContent();
    $member = User::factory()->create();
    $groupTherapy->users()->attach($member->id, ['anonymous' => false]);
    Transaction::factory()->create([
        'for_type' => GroupTherapy::class,
        'for_id' => $groupTherapy->id,
        'user_id' => $member->id,
        'status' => 'SUCCESS',
    ]);

    expect(EnsureUserCanAccessTherapyContentAction::new()->execute($groupTherapy, $member))->toBeTrue();
});

test('any active counsellor is never gated from content access', function () {
    $groupTherapy = strictGatedPaidGroupTherapyForContent();
    $counsellorUser = User::factory()->create();
    $counsellor = Counsellor::factory()->create(['user_id' => $counsellorUser->id]);
    $groupTherapy->counsellors()->attach($counsellor->id, ['state' => CounsellorGroupTherapyStateEnum::active->value, 'role' => 'NORMAL']);

    expect(EnsureUserCanAccessTherapyContentAction::new()->execute($groupTherapy, $counsellorUser))->toBeTrue();
});

test('an admin is never gated from content access', function () {
    $groupTherapy = strictGatedPaidGroupTherapyForContent();
    $admin = User::factory()->has(Administrator::factory())->create();

    expect(EnsureUserCanAccessTherapyContentAction::new()->execute($groupTherapy, $admin))->toBeTrue();
});

test('a non-participant is denied content access to a non-public group therapy regardless of payment', function () {
    $groupTherapy = strictGatedPaidGroupTherapyForContent();
    $unrelatedUser = User::factory()->create();

    expect(EnsureUserCanAccessTherapyContentAction::new()->execute($groupTherapy, $unrelatedUser))->toBeFalse();
});

test('a public strict-gated group therapy is unaffected for a non-participant', function () {
    $groupTherapy = strictGatedPaidGroupTherapyForContent(['public' => true]);
    $unrelatedUser = User::factory()->create();

    expect(EnsureUserCanAccessTherapyContentAction::new()->execute($groupTherapy, $unrelatedUser))->toBeTrue();
});

test('a trust-based (non-strict) paid group therapy is unaffected', function () {
    $groupTherapy = GroupTherapy::factory()->create([
        'addedby_type' => User::class,
        'addedby_id' => User::factory(),
        'public' => false,
        'payment_type' => 'PAID',
        'payment_data' => ['per' => 'PER_THERAPY', 'amount' => 50, 'currency' => 'GHS', 'inPersonAmount' => 60, 'strictPaymentGate' => false],
    ]);
    $member = User::factory()->create();
    $groupTherapy->users()->attach($member->id, ['anonymous' => false]);

    expect(EnsureUserCanAccessTherapyContentAction::new()->execute($groupTherapy, $member))->toBeTrue();
});

// --- Late-joiner historical-content exemption ---

test('content dated before the member\'s join date is exempt from the gate when allowFreeHistoricalAccess is on', function () {
    $groupTherapy = strictGatedPaidGroupTherapyForContent();
    $member = User::factory()->create();
    $groupTherapy->users()->attach($member->id, ['anonymous' => false, 'created_at' => now()->subDays(5)]);

    $contentTimestamp = now()->subDays(10);

    expect(EnsureUserCanAccessTherapyContentAction::new()->execute($groupTherapy, $member, null, $contentTimestamp))->toBeTrue();
});

test('content dated after the member\'s join date is still gated normally, even with allowFreeHistoricalAccess on', function () {
    $groupTherapy = strictGatedPaidGroupTherapyForContent();
    $member = User::factory()->create();
    $groupTherapy->users()->attach($member->id, ['anonymous' => false, 'created_at' => now()->subDays(5)]);

    $contentTimestamp = now()->subDay();

    expect(EnsureUserCanAccessTherapyContentAction::new()->execute($groupTherapy, $member, null, $contentTimestamp))->toBeFalse();
});

test('the historical exemption does not apply when allowFreeHistoricalAccess is off', function () {
    $groupTherapy = strictGatedPaidGroupTherapyForContent([
        'payment_data' => ['per' => 'PER_THERAPY', 'amount' => 50, 'currency' => 'GHS', 'inPersonAmount' => 60, 'strictPaymentGate' => true, 'allowFreeHistoricalAccess' => false],
    ]);
    $member = User::factory()->create();
    $groupTherapy->users()->attach($member->id, ['anonymous' => false, 'created_at' => now()->subDays(5)]);

    $contentTimestamp = now()->subDays(10);

    expect(EnsureUserCanAccessTherapyContentAction::new()->execute($groupTherapy, $member, null, $contentTimestamp))->toBeFalse();
});

// No $contentTimestamp passed at all (e.g. message CREATION never passes one) -- no exemption
// can apply even for genuinely old-predating content, since there is nothing to compare.
test('the historical exemption never applies when no content timestamp is provided at all', function () {
    $groupTherapy = strictGatedPaidGroupTherapyForContent();
    $member = User::factory()->create();
    $groupTherapy->users()->attach($member->id, ['anonymous' => false, 'created_at' => now()->subDays(5)]);

    expect(EnsureUserCanAccessTherapyContentAction::new()->execute($groupTherapy, $member))->toBeFalse();
});

// The User-type creator's own join date is the group's creation date (no pivot row) -- proves the
// exemption resolves correctly for them too, not just a pivot-joined member.
test('the historical exemption resolves correctly for the group\'s own User-type creator (no pivot row)', function () {
    $creator = User::factory()->create();
    $groupTherapy = strictGatedPaidGroupTherapyForContent([
        'addedby_id' => $creator->id,
        'created_at' => now()->subDays(20),
    ]);

    expect(EnsureUserCanAccessTherapyContentAction::new()->execute($groupTherapy, $creator, null, now()->subDays(25)))->toBeTrue()
        ->and(EnsureUserCanAccessTherapyContentAction::new()->execute($groupTherapy, $creator, null, now()->subDays(1)))->toBeFalse();
});

// A member with a successful transaction is granted access regardless of the historical
// exemption's outcome either way -- the two mechanisms are independent, not mutually exclusive.
test('a paying member is granted content access to NEW content too, independent of the historical exemption', function () {
    $groupTherapy = strictGatedPaidGroupTherapyForContent();
    $member = User::factory()->create();
    $groupTherapy->users()->attach($member->id, ['anonymous' => false, 'created_at' => now()->subDays(5)]);
    Transaction::factory()->create([
        'for_type' => GroupTherapy::class,
        'for_id' => $groupTherapy->id,
        'user_id' => $member->id,
        'status' => 'SUCCESS',
    ]);

    expect(EnsureUserCanAccessTherapyContentAction::new()->execute($groupTherapy, $member, null, now()->subDay()))->toBeTrue();
});

test('a counsellor is never gated regardless of the historical exemption', function () {
    $groupTherapy = strictGatedPaidGroupTherapyForContent();
    $counsellorUser = User::factory()->create();
    $counsellor = Counsellor::factory()->create(['user_id' => $counsellorUser->id]);
    $groupTherapy->counsellors()->attach($counsellor->id, ['state' => CounsellorGroupTherapyStateEnum::active->value, 'role' => 'NORMAL']);

    expect(EnsureUserCanAccessTherapyContentAction::new()->execute($groupTherapy, $counsellorUser, null, now()))->toBeTrue();
});

// A PER_SESSION-payable group works identically -- the historical exemption doesn't care which
// billing mode the group uses, only whether the content itself predates the join.
test('the historical exemption applies the same way for a PER_SESSION-payable group', function () {
    $groupTherapy = strictGatedPaidGroupTherapyForContent([
        'payment_data' => ['per' => 'PER_SESSION', 'amount' => 50, 'currency' => 'GHS', 'inPersonAmount' => 60, 'strictPaymentGate' => true, 'allowFreeHistoricalAccess' => true],
    ]);
    $member = User::factory()->create();
    $groupTherapy->users()->attach($member->id, ['anonymous' => false, 'created_at' => now()->subDays(5)]);
    $oldSession = Session::factory()->create(['for_id' => $groupTherapy->id, 'for_type' => GroupTherapy::class, 'start_time' => now()->subDays(10)]);
    $newSession = Session::factory()->create(['for_id' => $groupTherapy->id, 'for_type' => GroupTherapy::class, 'start_time' => now()->subDay()]);

    expect(EnsureUserCanAccessTherapyContentAction::new()->execute($groupTherapy, $member, $oldSession, $oldSession->start_time))->toBeTrue()
        ->and(EnsureUserCanAccessTherapyContentAction::new()->execute($groupTherapy, $member, $newSession, $newSession->start_time))->toBeFalse();
});
