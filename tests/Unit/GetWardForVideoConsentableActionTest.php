<?php

use App\Actions\VideoConsent\GetWardForVideoConsentableAction;
use App\Models\GroupTherapy;
use App\Models\Session;
use App\Models\Therapy;
use App\Models\User;

// TT-3.1e-b/SCRUM-281 (security-engineer finding): a Session's `for` can be a GroupTherapy, which
// has no single "the minor" to resolve. therapyFor() must return null for that case, not crash.

test('resolves the ward and therapy from a Therapy consentable', function () {
    $minor = User::factory()->create();
    $therapy = Therapy::factory()->create(['addedby_type' => User::class, 'addedby_id' => $minor->id]);

    $action = GetWardForVideoConsentableAction::new();

    expect($action->execute($therapy)->is($minor))->toBeTrue()
        ->and($action->therapyFor($therapy)->is($therapy))->toBeTrue();
});

test('resolves the ward and therapy from a Session consentable via its parent therapy', function () {
    $minor = User::factory()->create();
    $therapy = Therapy::factory()->create(['addedby_type' => User::class, 'addedby_id' => $minor->id]);
    $session = Session::factory()->create(['for_type' => Therapy::class, 'for_id' => $therapy->id]);

    $action = GetWardForVideoConsentableAction::new();

    expect($action->execute($session)->is($minor))->toBeTrue()
        ->and($action->therapyFor($session)->is($therapy))->toBeTrue();
});

test('a group-therapy-backed session resolves to no ward and no therapy, without crashing', function () {
    $groupTherapy = GroupTherapy::factory()->create();
    $session = Session::factory()->create(['for_type' => GroupTherapy::class, 'for_id' => $groupTherapy->id]);

    $action = GetWardForVideoConsentableAction::new();

    expect($action->execute($session))->toBeNull()
        ->and($action->therapyFor($session))->toBeNull();
});
