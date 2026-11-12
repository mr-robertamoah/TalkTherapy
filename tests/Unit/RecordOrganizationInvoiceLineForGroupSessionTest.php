<?php

use App\Actions\Organization\RecordOrganizationInvoiceLineForSessionAction;
use App\Actions\Session\ChangeSessionStatusAction;
use App\DTOs\CreateSessionDTO;
use App\Enums\OrganizationCounsellorCompensationBasisEnum;
use App\Enums\OrganizationCounsellorCompensationTypeEnum;
use App\Enums\OrganizationCounsellorStatusEnum;
use App\Enums\OrganizationMemberBillingModeEnum;
use App\Enums\SessionStatusEnum;
use App\Http\Resources\OrganizationInvoiceLineResource;
use App\Models\Counsellor;
use App\Models\GroupTherapy;
use App\Models\Organization;
use App\Models\OrganizationCounsellor;
use App\Models\OrganizationCounsellorCompensation;
use App\Models\OrganizationInvoiceLine;
use App\Models\OrganizationMember;
use App\Models\OrganizationMemberBillingConfig;
use App\Models\Session;
use App\Models\User;
use Illuminate\Support\Facades\Log;

// SCRUM-420: a held group session the retainer covers is billed once per covered member and once per
// counsellor running it -- before, Session::therapy() was null for a group and nothing was ever invoiced.

function rgiOrganization(): Organization
{
    return Organization::factory()->create(['is_consumer' => true, 'verified_at' => now()]);
}

// A counsellor; with $compensation, also active with $organization on those terms.
function rgiCounsellor(?Organization $organization = null, array $compensation = []): Counsellor
{
    $counsellor = Counsellor::factory()->create(['user_id' => User::factory()->adult()->create()->id]);

    if ($organization) {
        $affiliation = OrganizationCounsellor::factory()->create([
            'organization_id' => $organization->id,
            'counsellor_id' => $counsellor->id,
            'status' => OrganizationCounsellorStatusEnum::active->value,
        ]);
        OrganizationCounsellorCompensation::factory()->create(['organization_counsellor_id' => $affiliation->id] + $compensation);
    }

    return $counsellor;
}

function rgiMember(Organization $organization, string $mode = 'retainer'): User
{
    $user = User::factory()->adult()->create();
    $member = OrganizationMember::factory()->create(['organization_id' => $organization->id, 'user_id' => $user->id]);
    OrganizationMemberBillingConfig::factory()->create([
        'organization_member_id' => $member->id,
        'mode' => $mode === 'retainer' ? OrganizationMemberBillingModeEnum::retainer->value : OrganizationMemberBillingModeEnum::payPerUse->value,
    ]);

    return $user;
}

// A paid group run by $counsellors (the first creates it), with $members joined; and its held session.
function rgiHeldGroupSession(array $counsellors, array $members, array $groupOverrides = []): Session
{
    $group = GroupTherapy::factory()->create(array_merge([
        'addedby_type' => Counsellor::class,
        'addedby_id' => $counsellors[0]->id,
        'payment_type' => 'PAID',
        'payment_data' => ['per' => 'PER_SESSION', 'amount' => 100, 'currency' => 'GHS'],
    ], $groupOverrides));

    foreach (array_slice($counsellors, 1) as $counsellor) {
        $group->counsellors()->attach($counsellor->id, ['state' => 'ACTIVE', 'role' => 'NORMAL']);
    }

    foreach ($members as $member) {
        $group->users()->attach($member->id, ['anonymous' => false, 'was_minor_at_join' => false]);
    }

    return Session::factory()->create([
        'for_id' => $group->id,
        'for_type' => GroupTherapy::class,
        'start_time' => now()->addMinute(),
        'end_time' => now()->addHour(),
    ]);
}

beforeEach(fn () => config(['settings.platform_fee_percentage' => 10]));

test('each covered member is billed once per counsellor, on an equal part of the price, sharing the fee', function () {
    $organization = rgiOrganization();
    $first = rgiCounsellor($organization, [
        'type' => OrganizationCounsellorCompensationTypeEnum::percentage->value,
        'basis' => OrganizationCounsellorCompensationBasisEnum::counsellorRate->value,
        'percentage' => 50,
    ]);
    $second = rgiCounsellor($organization, ['type' => OrganizationCounsellorCompensationTypeEnum::fixed->value, 'amount' => 2000, 'currency' => 'GHS']);
    $covered = rgiMember($organization);
    $alsoCovered = rgiMember($organization);
    $notCovered = rgiMember($organization, 'payPerUse');
    $session = rgiHeldGroupSession([$first, $second], [$covered, $alsoCovered, $notCovered]);

    RecordOrganizationInvoiceLineForSessionAction::new()->execute($session);

    $lines = OrganizationInvoiceLine::query()->orderBy('id')->get();
    expect($lines)->toHaveCount(4)
        ->and($lines->pluck('user_id')->unique()->sort()->values()->all())->toBe(collect([$covered->id, $alsoCovered->id])->sort()->values()->all())
        ->and($lines->pluck('organization_invoice_id')->unique())->toHaveCount(1);

    // 100.00 split in two: 5000 each. 50% of 5000 for each member. The fixed 2000 is for the whole session:
    // a third for each of its three members (the first takes the remainder) -- the uncovered one's third is
    // never billed. The fee, 10% of 10000, in two.
    foreach ([[$covered, 668], [$alsoCovered, 666]] as [$member, $fixedShare]) {
        $mine = $lines->where('user_id', $member->id)->keyBy('counsellor_id');
        expect($mine[$first->id]->net_amount)->toBe(2500)
            ->and($mine[$second->id]->net_amount)->toBe($fixedShare)
            ->and($mine->sum('fee_amount'))->toBe(1000)
            ->and($mine[$first->id]->fee_amount)->toBe(500);
    }
});

test('an uneven price leaves its remainder with the first counsellor, and every minor unit is billed', function () {
    $organization = rgiOrganization();
    $terms = [
        'type' => OrganizationCounsellorCompensationTypeEnum::percentage->value,
        'basis' => OrganizationCounsellorCompensationBasisEnum::counsellorRate->value,
        'percentage' => 100,
    ];
    $counsellors = [rgiCounsellor($organization, $terms), rgiCounsellor($organization, $terms), rgiCounsellor($organization, $terms)];
    $member = rgiMember($organization);
    $session = rgiHeldGroupSession($counsellors, [$member]);

    RecordOrganizationInvoiceLineForSessionAction::new()->execute($session);

    // Which counsellor is first is the group's own order (activeCounsellors()); one of them gets the extra unit.
    $lines = OrganizationInvoiceLine::query()->get();
    expect($lines->pluck('net_amount')->sort()->values()->all())->toBe([3333, 3333, 3334])
        ->and($lines->pluck('fee_amount')->sort()->values()->all())->toBe([333, 333, 334])
        ->and($lines->sum('net_amount'))->toBe(10000)
        ->and($lines->sum('fee_amount'))->toBe(1000);
});

test('a counsellor the organisation has no terms with is skipped, and the others are still billed', function () {
    Log::spy();
    $organization = rgiOrganization();
    $affiliated = rgiCounsellor($organization, ['type' => OrganizationCounsellorCompensationTypeEnum::fixed->value, 'amount' => 2000, 'currency' => 'GHS']);
    $outsider = rgiCounsellor();
    $member = rgiMember($organization);
    $session = rgiHeldGroupSession([$outsider, $affiliated], [$member]);

    RecordOrganizationInvoiceLineForSessionAction::new()->execute($session);

    $line = OrganizationInvoiceLine::query()->sole();
    expect($line->counsellor_id)->toBe($affiliated->id)
        ->and($line->user_id)->toBe($member->id)
        ->and($line->net_amount)->toBe(2000)
        // The fee on the half billed: 10% of 5000.
        ->and($line->fee_amount)->toBe(500);
    Log::shouldHaveReceived('warning')->withArgs(fn ($message) => str_contains($message, 'no active compensation terms'))->once();
});

test('a group\'s user creator is billed as one of its members', function () {
    $organization = rgiOrganization();
    $counsellor = rgiCounsellor($organization, ['type' => OrganizationCounsellorCompensationTypeEnum::fixed->value, 'amount' => 2000, 'currency' => 'GHS']);
    $creator = rgiMember($organization);
    $session = rgiHeldGroupSession([$counsellor], [], ['addedby_type' => User::class, 'addedby_id' => $creator->id]);
    $session->for->counsellors()->attach($counsellor->id, ['state' => 'ACTIVE', 'role' => 'NORMAL']);

    RecordOrganizationInvoiceLineForSessionAction::new()->execute($session);

    expect(OrganizationInvoiceLine::query()->sole()->user_id)->toBe($creator->id);
});

test('a member who has left the group is not billed', function () {
    $organization = rgiOrganization();
    $counsellor = rgiCounsellor($organization, ['type' => OrganizationCounsellorCompensationTypeEnum::fixed->value, 'amount' => 2000, 'currency' => 'GHS']);
    $stayed = rgiMember($organization);
    $left = rgiMember($organization);
    $session = rgiHeldGroupSession([$counsellor], [$stayed, $left]);
    $session->for->users()->updateExistingPivot($left->id, ['left_at' => now()]);

    RecordOrganizationInvoiceLineForSessionAction::new()->execute($session);

    expect(OrganizationInvoiceLine::query()->sole()->user_id)->toBe($stayed->id);
});

test('recording the same group session twice adds no lines', function () {
    $organization = rgiOrganization();
    $counsellor = rgiCounsellor($organization, ['type' => OrganizationCounsellorCompensationTypeEnum::fixed->value, 'amount' => 2000, 'currency' => 'GHS']);
    $session = rgiHeldGroupSession([$counsellor, rgiCounsellor($organization, ['type' => OrganizationCounsellorCompensationTypeEnum::free->value])], [rgiMember($organization), rgiMember($organization)]);

    RecordOrganizationInvoiceLineForSessionAction::new()->execute($session);
    RecordOrganizationInvoiceLineForSessionAction::new()->execute($session);

    $this->assertDatabaseCount('organization_invoice_lines', 4);
});

test('the organisation sees a group session\'s line as a group session, never naming the member', function () {
    $organization = rgiOrganization();
    $counsellor = rgiCounsellor($organization, ['type' => OrganizationCounsellorCompensationTypeEnum::fixed->value, 'amount' => 2000, 'currency' => 'GHS']);
    $member = rgiMember($organization);
    $session = rgiHeldGroupSession([$counsellor], [$member]);
    $session->update(['start_time' => now()->setDate(2026, 2, 3)]);

    RecordOrganizationInvoiceLineForSessionAction::new()->execute($session);

    $line = (new OrganizationInvoiceLineResource(OrganizationInvoiceLine::query()->with('session')->sole()))->resolve();
    expect($line['sessionLabel'])->toBe('Group session on 3 Feb 2026')
        ->and(json_encode($line))->not->toContain((string) $member->username)
        ->and($line)->not->toHaveKey('userId');
});

test('confirming a group session as held bills it, through the real status change', function () {
    $organization = rgiOrganization();
    $counsellor = rgiCounsellor($organization, ['type' => OrganizationCounsellorCompensationTypeEnum::fixed->value, 'amount' => 2000, 'currency' => 'GHS']);
    $member = rgiMember($organization);
    $session = rgiHeldGroupSession([$counsellor], [$member]);
    $session->update(['status' => SessionStatusEnum::held_confirmation->value]);

    $updated = ChangeSessionStatusAction::new()->execute(
        CreateSessionDTO::new()->fromArray(['session' => $session, 'user' => $counsellor->user]),
        SessionStatusEnum::held->value
    );

    expect($updated->status)->toBe(SessionStatusEnum::held->value)
        ->and(OrganizationInvoiceLine::query()->sole()->only(['session_id', 'counsellor_id', 'user_id']))
        ->toBe(['session_id' => $session->id, 'counsellor_id' => $counsellor->id, 'user_id' => $member->id]);
});

test('ending a held session again bills nobody new -- not a member or counsellor who joined since', function () {
    $organization = rgiOrganization();
    $terms = ['type' => OrganizationCounsellorCompensationTypeEnum::fixed->value, 'amount' => 2000, 'currency' => 'GHS'];
    $counsellor = rgiCounsellor($organization, $terms);
    $session = rgiHeldGroupSession([$counsellor], [rgiMember($organization)]);
    RecordOrganizationInvoiceLineForSessionAction::new()->execute($session);
    $before = OrganizationInvoiceLine::query()->get(['id', 'net_amount', 'fee_amount'])->toArray();

    $session->for->users()->attach(rgiMember($organization)->id, ['anonymous' => false, 'was_minor_at_join' => false, 'created_at' => now()->subDay()]);
    $session->for->counsellors()->attach(rgiCounsellor($organization, $terms)->id, ['state' => 'ACTIVE', 'role' => 'NORMAL']);
    RecordOrganizationInvoiceLineForSessionAction::new()->execute($session->fresh());

    expect(OrganizationInvoiceLine::query()->get(['id', 'net_amount', 'fee_amount'])->toArray())->toBe($before);
});

test('a member who joined after the session ended is not billed for it', function () {
    $organization = rgiOrganization();
    $counsellor = rgiCounsellor($organization, ['type' => OrganizationCounsellorCompensationTypeEnum::fixed->value, 'amount' => 2000, 'currency' => 'GHS']);
    $wasThere = rgiMember($organization);
    $session = rgiHeldGroupSession([$counsellor], [$wasThere]);
    $session->update(['start_time' => now()->subHours(3), 'end_time' => now()->subHours(2)]);
    $session->for->users()->updateExistingPivot($wasThere->id, ['created_at' => now()->subDay()]);
    $session->for->users()->attach(rgiMember($organization)->id, ['anonymous' => false, 'was_minor_at_join' => false]);

    RecordOrganizationInvoiceLineForSessionAction::new()->execute($session);

    expect(OrganizationInvoiceLine::query()->sole()->user_id)->toBe($wasThere->id);
});

test('a free group bills nobody', function () {
    $organization = rgiOrganization();
    $counsellor = rgiCounsellor($organization, ['type' => OrganizationCounsellorCompensationTypeEnum::fixed->value, 'amount' => 2000, 'currency' => 'GHS']);
    $session = rgiHeldGroupSession([$counsellor], [rgiMember($organization)], ['payment_type' => 'FREE']);

    RecordOrganizationInvoiceLineForSessionAction::new()->execute($session);

    $this->assertDatabaseCount('organization_invoice_lines', 0);
});

test('members covered by different organisations each go on their own organisation\'s invoice', function () {
    $first = rgiOrganization();
    $second = rgiOrganization();
    $counsellor = rgiCounsellor($first, ['type' => OrganizationCounsellorCompensationTypeEnum::fixed->value, 'amount' => 2000, 'currency' => 'GHS']);
    $affiliation = OrganizationCounsellor::factory()->create([
        'organization_id' => $second->id,
        'counsellor_id' => $counsellor->id,
        'status' => OrganizationCounsellorStatusEnum::active->value,
    ]);
    OrganizationCounsellorCompensation::factory()->create([
        'organization_counsellor_id' => $affiliation->id,
        'type' => OrganizationCounsellorCompensationTypeEnum::fixed->value,
        'amount' => 3000,
        'currency' => 'GHS',
    ]);
    $ofFirst = rgiMember($first);
    $ofSecond = rgiMember($second);
    $session = rgiHeldGroupSession([$counsellor], [$ofFirst, $ofSecond]);

    RecordOrganizationInvoiceLineForSessionAction::new()->execute($session);

    $lines = OrganizationInvoiceLine::query()->with('organizationInvoice')->get()->keyBy('user_id');
    expect($lines)->toHaveCount(2)
        ->and($lines[$ofFirst->id]->organizationInvoice->organization_id)->toBe($first->id)
        // Each organisation's fixed rate, half of it for its one member of two.
        ->and($lines[$ofFirst->id]->net_amount)->toBe(1000)
        ->and($lines[$ofSecond->id]->organizationInvoice->organization_id)->toBe($second->id)
        ->and($lines[$ofSecond->id]->net_amount)->toBe(1500);
});

test('one of the group\'s own counsellors is never billed as a member', function () {
    $organization = rgiOrganization();
    $counsellor = rgiCounsellor($organization, ['type' => OrganizationCounsellorCompensationTypeEnum::fixed->value, 'amount' => 2000, 'currency' => 'GHS']);
    $member = rgiMember($organization);
    $colleague = rgiCounsellor($organization, ['type' => OrganizationCounsellorCompensationTypeEnum::free->value]);
    $colleagueAsMember = OrganizationMember::factory()->create(['organization_id' => $organization->id, 'user_id' => $colleague->user_id]);
    OrganizationMemberBillingConfig::factory()->create([
        'organization_member_id' => $colleagueAsMember->id,
        'mode' => OrganizationMemberBillingModeEnum::retainer->value,
    ]);
    $session = rgiHeldGroupSession([$counsellor, $colleague], [$member, $colleague->user]);

    RecordOrganizationInvoiceLineForSessionAction::new()->execute($session);

    expect(OrganizationInvoiceLine::query()->pluck('user_id')->unique()->values()->all())->toBe([$member->id]);
});

test('a fixed or negotiated rate pays for the session once, shared across everyone in it', function () {
    $organization = rgiOrganization();
    $fixed = rgiCounsellor($organization, ['type' => OrganizationCounsellorCompensationTypeEnum::fixed->value, 'amount' => 5000, 'currency' => 'GHS']);
    $negotiated = rgiCounsellor($organization, [
        'type' => OrganizationCounsellorCompensationTypeEnum::percentage->value,
        'basis' => OrganizationCounsellorCompensationBasisEnum::negotiatedRate->value,
        'percentage' => 50,
        'negotiated_rate_amount' => 9000,
    ]);
    $session = rgiHeldGroupSession([$fixed, $negotiated], [rgiMember($organization), rgiMember($organization), rgiMember($organization)]);

    RecordOrganizationInvoiceLineForSessionAction::new()->execute($session);

    $lines = OrganizationInvoiceLine::query()->get();
    expect($lines->where('counsellor_id', $fixed->id)->pluck('net_amount')->sort()->values()->all())->toBe([1666, 1666, 1668])
        ->and($lines->where('counsellor_id', $negotiated->id)->pluck('net_amount')->all())->toBe([1500, 1500, 1500]);
});

test('ending a held group session again never bills it -- not even one that has no lines yet', function () {
    $organization = rgiOrganization();
    $counsellor = rgiCounsellor($organization, ['type' => OrganizationCounsellorCompensationTypeEnum::fixed->value, 'amount' => 2000, 'currency' => 'GHS']);
    $session = rgiHeldGroupSession([$counsellor], [rgiMember($organization)]);
    // Held before this was billed (a group session held before SCRUM-420): no lines.
    $session->update(['status' => SessionStatusEnum::held->value]);

    ChangeSessionStatusAction::new()->execute(
        CreateSessionDTO::new()->fromArray(['session' => $session, 'user' => $counsellor->user]),
        SessionStatusEnum::held->value
    );

    $this->assertDatabaseCount('organization_invoice_lines', 0);
});

test('a counsellor who joined the group after the session ended is not billed for it', function () {
    $organization = rgiOrganization();
    $terms = ['type' => OrganizationCounsellorCompensationTypeEnum::fixed->value, 'amount' => 2000, 'currency' => 'GHS'];
    $ranIt = rgiCounsellor($organization, $terms);
    $member = rgiMember($organization);
    $session = rgiHeldGroupSession([$ranIt], [$member]);
    $session->update(['start_time' => now()->subHours(3), 'end_time' => now()->subHours(2)]);
    $session->for->users()->updateExistingPivot($member->id, ['created_at' => now()->subDay()]);
    $session->for->counsellors()->attach(rgiCounsellor($organization, $terms)->id, ['state' => 'ACTIVE', 'role' => 'NORMAL']);

    RecordOrganizationInvoiceLineForSessionAction::new()->execute($session);

    expect(OrganizationInvoiceLine::query()->sole()->counsellor_id)->toBe($ranIt->id);
});

test('a group session that ended before, reopened and ended again, is never billed', function () {
    $organization = rgiOrganization();
    $counsellor = rgiCounsellor($organization, ['type' => OrganizationCounsellorCompensationTypeEnum::fixed->value, 'amount' => 2000, 'currency' => 'GHS']);
    $session = rgiHeldGroupSession([$counsellor], [rgiMember($organization)]);
    // Ended (and held) once already, then taken back through in_session to held_confirmation.
    $session->update(['status' => SessionStatusEnum::held_confirmation->value, 'ended_at' => now()->subWeek()]);

    ChangeSessionStatusAction::new()->execute(
        CreateSessionDTO::new()->fromArray(['session' => $session, 'user' => $counsellor->user]),
        SessionStatusEnum::held->value
    );

    $this->assertDatabaseCount('organization_invoice_lines', 0);
});
