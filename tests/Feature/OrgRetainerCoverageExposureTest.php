<?php

use App\Enums\OrganizationCounsellorStatusEnum;
use App\Enums\OrganizationMemberBillingModeEnum;
use App\Enums\TherapyPaymentTypeEnum;
use App\Models\Counsellor;
use App\Models\Guardianship;
use App\Models\Organization;
use App\Models\OrganizationCounsellor;
use App\Models\OrganizationMember;
use App\Models\OrganizationMemberBillingConfig;
use App\Models\Therapy;
use App\Models\User;

// TT-7.3b-k/SCRUM-242: TherapyResource's client-facing org-retainer-coverage disclosure --
// exercised end-to-end through the therapy page's own Inertia response, mirroring
// PaymentStatusExposureTest's pattern for this same resource.

test('a retainer-covered therapy exposes the covering organization name', function () {
    $user = User::factory()->create();
    $counsellorUser = User::factory()->create();
    $counsellor = Counsellor::factory()->create(['user_id' => $counsellorUser->id]);
    $therapy = Therapy::factory()->create([
        'addedby_type' => User::class,
        'addedby_id' => $user->id,
        'counsellor_id' => $counsellor->id,
        'payment_type' => TherapyPaymentTypeEnum::paid->value,
    ]);

    $organization = Organization::factory()->create(['is_consumer' => true, 'verified_at' => now(), 'name' => 'Acme Wellness']);
    OrganizationCounsellor::factory()->create([
        'organization_id' => $organization->id,
        'counsellor_id' => $counsellor->id,
        'status' => OrganizationCounsellorStatusEnum::active->value,
    ]);
    $member = OrganizationMember::factory()->create(['organization_id' => $organization->id, 'user_id' => $user->id]);
    OrganizationMemberBillingConfig::factory()->create([
        'organization_member_id' => $member->id,
        'mode' => OrganizationMemberBillingModeEnum::retainer->value,
    ]);

    $response = $this->actingAs($user)->get(route('therapies.get', ['therapyId' => $therapy->id]));

    $response->assertOk()->assertInertia(fn ($page) => $page
        ->where('therapy.orgRetainerCoverage.organizationName', 'Acme Wellness')
    );
});

test('a therapy with no org coverage exposes a null orgRetainerCoverage', function () {
    $user = User::factory()->create();
    $therapy = Therapy::factory()->create([
        'addedby_type' => User::class,
        'addedby_id' => $user->id,
    ]);

    $response = $this->actingAs($user)->get(route('therapies.get', ['therapyId' => $therapy->id]));

    $response->assertOk()->assertInertia(fn ($page) => $page
        ->where('therapy.orgRetainerCoverage', null)
    );
});

test('a pay-per-use org member does not trigger the disclosure', function () {
    $user = User::factory()->create();
    $counsellorUser = User::factory()->create();
    $counsellor = Counsellor::factory()->create(['user_id' => $counsellorUser->id]);
    $therapy = Therapy::factory()->create([
        'addedby_type' => User::class,
        'addedby_id' => $user->id,
        'counsellor_id' => $counsellor->id,
        'payment_type' => TherapyPaymentTypeEnum::paid->value,
    ]);

    $organization = Organization::factory()->create(['is_consumer' => true, 'verified_at' => now()]);
    OrganizationCounsellor::factory()->create([
        'organization_id' => $organization->id,
        'counsellor_id' => $counsellor->id,
        'status' => OrganizationCounsellorStatusEnum::active->value,
    ]);
    $member = OrganizationMember::factory()->create(['organization_id' => $organization->id, 'user_id' => $user->id]);
    OrganizationMemberBillingConfig::factory()->create([
        'organization_member_id' => $member->id,
        'mode' => OrganizationMemberBillingModeEnum::payPerUse->value,
    ]);

    $response = $this->actingAs($user)->get(route('therapies.get', ['therapyId' => $therapy->id]));

    $response->assertOk()->assertInertia(fn ($page) => $page
        ->where('therapy.orgRetainerCoverage', null)
    );
});

// Security-engineer finding (SCRUM-242 review): orgRetainerCoverage must respect the exact same
// anonymity masking as the 'user' field (addedByUserIsMaskedFor) -- naming the covering org to a
// non-owning viewer on an anonymous therapy would re-identify the addedby, the same class of leak
// TT-1.5 already guards against for the 'user' field itself.

// SCRUM-180 (the user's decision, 2026-11-12): its counsellor is told it's covered -- so their page doesn't
// show it unpaid -- but not by which organisation.
test('an anonymous therapy tells the assigned counsellor it is covered, never by which organisation', function () {
    $user = User::factory()->create();
    $counsellorUser = User::factory()->create();
    $counsellor = Counsellor::factory()->create(['user_id' => $counsellorUser->id]);
    $therapy = Therapy::factory()->create([
        'addedby_type' => User::class,
        'addedby_id' => $user->id,
        'counsellor_id' => $counsellor->id,
        'payment_type' => TherapyPaymentTypeEnum::paid->value,
        'anonymous' => true,
    ]);

    $organization = Organization::factory()->create(['is_consumer' => true, 'verified_at' => now(), 'name' => 'Acme Wellness']);
    OrganizationCounsellor::factory()->create([
        'organization_id' => $organization->id,
        'counsellor_id' => $counsellor->id,
        'status' => OrganizationCounsellorStatusEnum::active->value,
    ]);
    $member = OrganizationMember::factory()->create(['organization_id' => $organization->id, 'user_id' => $user->id]);
    OrganizationMemberBillingConfig::factory()->create([
        'organization_member_id' => $member->id,
        'mode' => OrganizationMemberBillingModeEnum::retainer->value,
    ]);

    $response = $this->actingAs($counsellorUser)->get(route('therapies.get', ['therapyId' => $therapy->id]));

    $response->assertOk()->assertInertia(fn ($page) => $page
        ->where('therapy.orgRetainerCoverage', ['organizationName' => null])
    );
});

test('a public anonymous therapy does not disclose org coverage to an unauthenticated guest', function () {
    $user = User::factory()->create();
    $counsellorUser = User::factory()->create();
    $counsellor = Counsellor::factory()->create(['user_id' => $counsellorUser->id]);
    $therapy = Therapy::factory()->create([
        'addedby_type' => User::class,
        'addedby_id' => $user->id,
        'counsellor_id' => $counsellor->id,
        'payment_type' => TherapyPaymentTypeEnum::paid->value,
        'public' => true,
        'anonymous' => true,
    ]);

    $organization = Organization::factory()->create(['is_consumer' => true, 'verified_at' => now(), 'name' => 'Acme Wellness']);
    OrganizationCounsellor::factory()->create([
        'organization_id' => $organization->id,
        'counsellor_id' => $counsellor->id,
        'status' => OrganizationCounsellorStatusEnum::active->value,
    ]);
    $member = OrganizationMember::factory()->create(['organization_id' => $organization->id, 'user_id' => $user->id]);
    OrganizationMemberBillingConfig::factory()->create([
        'organization_member_id' => $member->id,
        'mode' => OrganizationMemberBillingModeEnum::retainer->value,
    ]);

    $response = $this->get(route('therapies.get', ['therapyId' => $therapy->id]));

    // Unauthenticated requests skip HandleInertiaRequestsV2's auth-only withoutWrapping() call, so
    // JsonResource's default wrap('data') behavior still applies here -- 'therapy.data.*', not
    // 'therapy.*', unlike every other (authenticated) test in this file.
    $response->assertOk()->assertInertia(fn ($page) => $page
        ->where('therapy.data.orgRetainerCoverage', null)
    );
});

test('a public, non-anonymous therapy still names the organisation to the assigned counsellor', function () {
    $user = User::factory()->create();
    $counsellorUser = User::factory()->create();
    $counsellor = Counsellor::factory()->create(['user_id' => $counsellorUser->id]);
    $therapy = Therapy::factory()->create([
        'addedby_type' => User::class,
        'addedby_id' => $user->id,
        'counsellor_id' => $counsellor->id,
        'payment_type' => TherapyPaymentTypeEnum::paid->value,
        'public' => true,
        'anonymous' => false,
    ]);

    $organization = Organization::factory()->create(['is_consumer' => true, 'verified_at' => now(), 'name' => 'Acme Wellness']);
    OrganizationCounsellor::factory()->create([
        'organization_id' => $organization->id,
        'counsellor_id' => $counsellor->id,
        'status' => OrganizationCounsellorStatusEnum::active->value,
    ]);
    $member = OrganizationMember::factory()->create(['organization_id' => $organization->id, 'user_id' => $user->id]);
    OrganizationMemberBillingConfig::factory()->create([
        'organization_member_id' => $member->id,
        'mode' => OrganizationMemberBillingModeEnum::retainer->value,
    ]);

    $response = $this->actingAs($counsellorUser)->get(route('therapies.get', ['therapyId' => $therapy->id]));

    $response->assertOk()->assertInertia(fn ($page) => $page
        ->where('therapy.orgRetainerCoverage.organizationName', 'Acme Wellness')
    );
});

// SCRUM-180: the organisation may be the client's employer or school -- on a private therapy only the client
// learns which. Its counsellor is told it's covered; a guardian, nothing.
test('a private therapy names the organisation to its client only', function () {
    $client = User::factory()->adult()->create();
    $guardian = User::factory()->adult()->create();
    Guardianship::query()->create(['guardian_id' => $guardian->id, 'ward_id' => $client->id]);
    $counsellor = Counsellor::factory()->create(['user_id' => User::factory()->adult()->create()->id]);
    $therapy = Therapy::factory()->create([
        'addedby_type' => User::class,
        'addedby_id' => $client->id,
        'counsellor_id' => $counsellor->id,
        'payment_type' => TherapyPaymentTypeEnum::paid->value,
        'public' => false,
        'anonymous' => false,
    ]);
    $organization = Organization::factory()->create(['is_consumer' => true, 'verified_at' => now(), 'name' => 'Acme Wellness']);
    OrganizationCounsellor::factory()->create([
        'organization_id' => $organization->id,
        'counsellor_id' => $counsellor->id,
        'status' => OrganizationCounsellorStatusEnum::active->value,
    ]);
    $member = OrganizationMember::factory()->create(['organization_id' => $organization->id, 'user_id' => $client->id]);
    OrganizationMemberBillingConfig::factory()->create([
        'organization_member_id' => $member->id,
        'mode' => OrganizationMemberBillingModeEnum::retainer->value,
    ]);
    $coverageFor = fn (User $viewer) => $this->actingAs($viewer)->get(route('therapies.get', ['therapyId' => $therapy->id]))
        ->assertOk()->viewData('page')['props']['therapy']['orgRetainerCoverage'];

    expect($coverageFor($client))->toBe(['organizationName' => 'Acme Wellness'])
        ->and($coverageFor($counsellor->user))->toBe(['organizationName' => null]);

    // A guardian may open their ward's therapy -- and isn't told which organisation pays.
    expect($coverageFor($guardian))->toBeNull();
});
