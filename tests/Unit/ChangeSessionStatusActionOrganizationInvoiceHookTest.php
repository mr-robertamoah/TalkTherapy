<?php

use App\Actions\Session\ChangeSessionStatusAction;
use App\DTOs\CreateSessionDTO;
use App\Enums\OrganizationCounsellorCompensationTypeEnum;
use App\Enums\OrganizationCounsellorStatusEnum;
use App\Enums\OrganizationMemberBillingModeEnum;
use App\Enums\SessionStatusEnum;
use App\Models\Counsellor;
use App\Models\Organization;
use App\Models\OrganizationCounsellor;
use App\Models\OrganizationCounsellorCompensation;
use App\Models\OrganizationInvoiceLine;
use App\Models\OrganizationMember;
use App\Models\OrganizationMemberBillingConfig;
use App\Models\Session;
use App\Models\Therapy;
use App\Models\User;

// TT-7.3b-e/SCRUM-236: proves the actual wiring, not just RecordOrganizationInvoiceLineForSessionAction's
// own contract in isolation -- the hook must fire on the FINAL, rewritten status (a caller
// requesting `held` while not already `held`/`held_confirmation` gets rewritten to
// `held_confirmation` first), not the raw parameter this action received.

function aRetainerCoveredSessionAt(string $status): array
{
    $organization = Organization::factory()->create(['is_consumer' => true, 'verified_at' => now()]);
    $counsellor = Counsellor::factory()->create(['user_id' => User::factory()]);
    $affiliation = OrganizationCounsellor::factory()->create([
        'organization_id' => $organization->id,
        'counsellor_id' => $counsellor->id,
        'status' => OrganizationCounsellorStatusEnum::active->value,
    ]);
    OrganizationCounsellorCompensation::factory()->create([
        'organization_counsellor_id' => $affiliation->id,
        'type' => OrganizationCounsellorCompensationTypeEnum::fixed->value,
        'amount' => 5000,
        'currency' => 'GHS',
    ]);
    $member = User::factory()->create();
    $organizationMember = OrganizationMember::factory()->create(['organization_id' => $organization->id, 'user_id' => $member->id]);
    OrganizationMemberBillingConfig::factory()->create([
        'organization_member_id' => $organizationMember->id,
        'mode' => OrganizationMemberBillingModeEnum::retainer->value,
    ]);
    $therapy = Therapy::factory()->create([
        'addedby_type' => User::class,
        'addedby_id' => $member->id,
        'counsellor_id' => $counsellor->id,
        'payment_data' => ['per' => 'PER_THERAPY', 'amount' => 100, 'currency' => 'GHS'],
    ]);
    $session = Session::factory()->create(['for_id' => $therapy->id, 'for_type' => Therapy::class, 'status' => $status]);

    return [$session, $member];
}

test('a session already at held_confirmation that is confirmed to held records an invoice line', function () {
    [$session, $member] = aRetainerCoveredSessionAt(SessionStatusEnum::held_confirmation->value);

    $updated = ChangeSessionStatusAction::new()->execute(
        CreateSessionDTO::new()->fromArray(['session' => $session, 'user' => $member]),
        SessionStatusEnum::held->value
    );

    expect($updated->status)->toBe(SessionStatusEnum::held->value);
    $this->assertDatabaseCount('organization_invoice_lines', 1);
    expect(OrganizationInvoiceLine::first()->session_id)->toBe($session->id);
});

test('a first request for held on a session not already in a held state is rewritten to held_confirmation and records nothing yet', function () {
    [$session, $member] = aRetainerCoveredSessionAt(SessionStatusEnum::in_session->value);

    $updated = ChangeSessionStatusAction::new()->execute(
        CreateSessionDTO::new()->fromArray(['session' => $session, 'user' => $member]),
        SessionStatusEnum::held->value
    );

    expect($updated->status)->toBe(SessionStatusEnum::held_confirmation->value);
    $this->assertDatabaseCount('organization_invoice_lines', 0);
});

test('a replayed held confirmation does not create a duplicate invoice line', function () {
    [$session, $member] = aRetainerCoveredSessionAt(SessionStatusEnum::held_confirmation->value);
    $dto = CreateSessionDTO::new()->fromArray(['session' => $session, 'user' => $member]);

    ChangeSessionStatusAction::new()->execute($dto, SessionStatusEnum::held->value);
    ChangeSessionStatusAction::new()->execute($dto, SessionStatusEnum::held->value);

    $this->assertDatabaseCount('organization_invoice_lines', 1);
});

test('a non-retainer session reaching held records no invoice line and does not break the status transition', function () {
    $counsellor = Counsellor::factory()->create(['user_id' => User::factory()]);
    $therapy = Therapy::factory()->create([
        'addedby_type' => User::class,
        'addedby_id' => User::factory(),
        'counsellor_id' => $counsellor->id,
    ]);
    $session = Session::factory()->create([
        'for_id' => $therapy->id,
        'for_type' => Therapy::class,
        'status' => SessionStatusEnum::held_confirmation->value,
    ]);

    $updated = ChangeSessionStatusAction::new()->execute(
        CreateSessionDTO::new()->fromArray(['session' => $session]),
        SessionStatusEnum::held->value
    );

    expect($updated->status)->toBe(SessionStatusEnum::held->value);
    $this->assertDatabaseCount('organization_invoice_lines', 0);
});
