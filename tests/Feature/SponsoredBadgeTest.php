<?php

use App\Actions\Therapy\ResolveTherapySponsorAction;
use App\Actions\Transaction\ChargeOrganizationForModelAction;
use App\Enums\OrganizationCounsellorStatusEnum;
use App\Enums\OrganizationMemberBillingModeEnum;
use App\Enums\RefundStatusEnum;
use App\Enums\TherapyPaymentTypeEnum;
use App\Enums\TransactionStatusEnum;
use App\Models\Counsellor;
use App\Models\GroupTherapy;
use App\Models\Guardianship;
use App\Models\Organization;
use App\Models\OrganizationCounsellor;
use App\Models\OrganizationMember;
use App\Models\OrganizationMemberBillingConfig;
use App\Models\Refund;
use App\Models\Session;
use App\Models\Therapy;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// SCRUM-180: the "Sponsored" badge -- for its client only, and only when an organisation really paid.

function spbTherapy(User $client, array $attributes = []): Therapy
{
    return Therapy::factory()->create([
        'addedby_type' => User::class,
        'addedby_id' => $client->id,
        'payment_type' => TherapyPaymentTypeEnum::paid->value,
        ...$attributes,
    ]);
}

function spbOrganization(string $name = 'Acme Wellness'): Organization
{
    return Organization::factory()->create(['is_consumer' => true, 'verified_at' => now(), 'name' => $name]);
}

// A charge to the organisation's own card (ChargeOrganizationForModelAction), unless $reference says otherwise.
function spbCharge(User $client, Organization $organization, Therapy|Session|GroupTherapy $for, array $attributes = []): Transaction
{
    return Transaction::factory()->create([
        'user_id' => $client->id,
        'organization_id' => $organization->id,
        'for_type' => $for::class,
        'for_id' => $for->id,
        'reference' => ChargeOrganizationForModelAction::REFERENCE_PREFIX.Str::uuid(),
        'status' => TransactionStatusEnum::success->value,
        ...$attributes,
    ]);
}

function spbRetainer(User $client, Organization $organization, Counsellor $counsellor): void
{
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
}

function spbCounsellor(): Counsellor
{
    return Counsellor::factory()->create(['user_id' => User::factory()->adult()->create()->id]);
}

// The badge on the client's own list, keyed by therapy id.
function spbOwnList($test, User $client): array
{
    return collect($test->actingAs($client)->getJson(route('api.therapies.user'))->assertOk()->json('data'))->keyBy('id')->all();
}

test('a therapy the organisation paid for shows "Paid by" to its client, by the therapy or one of its sessions', function () {
    $client = User::factory()->adult()->create();
    [$byTherapy, $bySession, $unpaid] = [spbTherapy($client), spbTherapy($client), spbTherapy($client)];
    spbCharge($client, spbOrganization('Acme Wellness'), $byTherapy);
    $session = Session::unguarded(fn () => Session::factory()->create(['for_type' => Therapy::class, 'for_id' => $bySession->id]));
    spbCharge($client, spbOrganization('Beta School'), $session);

    $list = spbOwnList($this, $client);

    expect($list[$byTherapy->id]['sponsor'])->toBe(['organizationName' => 'Acme Wellness'])
        ->and($list[$bySession->id]['sponsor'])->toBe(['organizationName' => 'Beta School'])
        ->and($list[$unpaid->id])->not->toHaveKey('sponsor');
});

test('only a successful, unrefunded charge to the organisation\'s own card counts', function () {
    $client = User::factory()->adult()->create();
    $organization = spbOrganization();
    $pending = spbTherapy($client);
    spbCharge($client, $organization, $pending, ['status' => TransactionStatusEnum::pending->value]);
    $failed = spbTherapy($client);
    spbCharge($client, $organization, $failed, ['status' => TransactionStatusEnum::failed->value]);
    $refunded = spbTherapy($client);
    Refund::factory()->create(['transaction_id' => spbCharge($client, $organization, $refunded)->id, 'status' => RefundStatusEnum::success->value]);
    // The member's own card, with the organisation only as a label (pre-SCRUM-234, or a group's).
    $labelOnly = spbTherapy($client);
    spbCharge($client, $organization, $labelOnly, ['reference' => (string) Str::uuid()]);

    $list = spbOwnList($this, $client);

    foreach ([$pending, $failed, $refunded, $labelOnly] as $therapy) {
        expect($list[$therapy->id])->not->toHaveKey('sponsor');
    }
});

test('the latest organisation to pay is named, even after it was deleted', function () {
    $client = User::factory()->adult()->create();
    $therapy = spbTherapy($client);
    spbCharge($client, spbOrganization('Older Org'), $therapy);
    $latest = spbOrganization('Latest Org');
    spbCharge($client, $latest, $therapy);
    $latest->delete();

    expect(spbOwnList($this, $client)[$therapy->id]['sponsor'])->toBe(['organizationName' => 'Latest Org']);
});

test('a retainer covering the client sponsors a paid therapy, not a free one', function () {
    $client = User::factory()->adult()->create();
    $counsellor = spbCounsellor();
    spbRetainer($client, spbOrganization('Retainer Org'), $counsellor);
    $paid = spbTherapy($client, ['counsellor_id' => $counsellor->id]);
    $free = spbTherapy($client, ['counsellor_id' => $counsellor->id, 'payment_type' => TherapyPaymentTypeEnum::free->value]);
    $otherCounsellor = spbTherapy($client, ['counsellor_id' => spbCounsellor()->id]);

    $list = spbOwnList($this, $client);

    expect($list[$paid->id]['sponsor'])->toBe(['organizationName' => 'Retainer Org'])
        ->and($list[$free->id])->not->toHaveKey('sponsor')
        ->and($list[$otherCounsellor->id])->not->toHaveKey('sponsor');
});

test('nobody but the client is sent it: not its counsellor, nor anyone on a public list', function () {
    $client = User::factory()->adult()->create();
    $counsellor = spbCounsellor();
    $therapy = spbTherapy($client, ['counsellor_id' => $counsellor->id, 'public' => true]);
    spbCharge($client, spbOrganization(), $therapy);

    $asCounsellor = collect($this->actingAs($counsellor->user)->getJson(route('api.therapies.counsellor'))->assertOk()->json('data'))->firstWhere('id', $therapy->id);
    $publicFeed = collect($this->actingAs(User::factory()->adult()->create())->getJson(route('api.therapies.public'))->assertOk()->json('data'))->firstWhere('id', $therapy->id);

    expect($asCounsellor)->not->toBeNull()->not->toHaveKey('sponsor')
        ->and($publicFeed)->not->toBeNull()->not->toHaveKey('sponsor');

    $this->actingAs($counsellor->user)->get(route('therapies.get', ['therapyId' => $therapy->id]))
        ->assertOk()->assertInertia(fn ($page) => $page->missing('therapy.sponsor'));
    $this->actingAs($client)->get(route('therapies.get', ['therapyId' => $therapy->id]))
        ->assertOk()->assertInertia(fn ($page) => $page->where('therapy.sponsor', ['organizationName' => 'Acme Wellness']));
});

test('a group shows it to a member a retainer covers -- never for a payment the organisation is only a label on', function () {
    $member = User::factory()->adult()->create();
    $counsellor = spbCounsellor();
    $organization = spbOrganization('Retainer Org');
    $covered = GroupTherapy::factory()->create(['addedby_type' => Counsellor::class, 'addedby_id' => $counsellor->id, 'payment_type' => TherapyPaymentTypeEnum::paid->value]);
    $labelled = GroupTherapy::factory()->create(['addedby_type' => Counsellor::class, 'addedby_id' => spbCounsellor()->id, 'payment_type' => TherapyPaymentTypeEnum::paid->value]);
    $covered->users()->attach($member->id, ['anonymous' => false]);
    $labelled->users()->attach($member->id, ['anonymous' => false]);
    spbRetainer($member, $organization, $counsellor);
    // What "my organisation pays" does for a group today: the member's card, the organisation as a label.
    spbCharge($member, $organization, $labelled, ['reference' => (string) Str::uuid()]);

    $this->actingAs($member)->get(route('group.therapies.get', ['groupTherapyId' => $covered->id]))
        ->assertOk()->assertInertia(fn ($page) => $page->where('therapy.sponsor', ['organizationName' => 'Retainer Org']));
    $this->actingAs($member)->get(route('group.therapies.get', ['groupTherapyId' => $labelled->id]))
        ->assertOk()->assertInertia(fn ($page) => $page->missing('therapy.sponsor'));
    $this->actingAs($counsellor->user)->get(route('group.therapies.get', ['groupTherapyId' => $covered->id]))
        ->assertOk()->assertInertia(fn ($page) => $page->missing('therapy.sponsor'));
});

test('working it out for a page costs the same queries for 4 cards as for 11', function () {
    $client = User::factory()->adult()->create();
    $counsellor = spbCounsellor();
    $organization = spbOrganization();
    spbRetainer($client, $organization, $counsellor);
    $make = function (int $count) use ($client, $counsellor, $organization) {
        foreach (range(1, $count) as $i) {
            $therapy = spbTherapy($client, ['counsellor_id' => $i % 2 ? $counsellor->id : null]);
            if ($i % 3 === 0) {
                spbCharge($client, $organization, $therapy);
            }
        }
        $group = GroupTherapy::factory()->create(['addedby_type' => Counsellor::class, 'addedby_id' => $counsellor->id, 'payment_type' => TherapyPaymentTypeEnum::paid->value]);
        $group->users()->attach($client->id, ['anonymous' => false]);
    };
    $count = function () use ($client) {
        $rows = Therapy::query()->get()->concat(GroupTherapy::query()->get());
        DB::flushQueryLog();
        DB::enableQueryLog();
        ResolveTherapySponsorAction::new()->execute($rows, $client);
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        return [$queries, $rows->filter(fn ($row) => $row->viewerSponsor)->count()];
    };

    // The first page already has a charge, so the organisations' eager load runs both times.
    $make(3);
    [$few, $sponsoredFew] = $count();
    $make(6);
    [$many, $sponsoredMany] = $count();

    expect($many)->toBe($few)->and($sponsoredMany)->toBeGreaterThan($sponsoredFew);
});

// Security review: the lists the client's guardian and counsellor see never carry it.
test('a guardian\'s ward lists and the counsellor\'s home page never carry it', function () {
    $client = User::factory()->adult()->create();
    $guardian = User::factory()->adult()->create();
    Guardianship::query()->create(['guardian_id' => $guardian->id, 'ward_id' => $client->id]);
    $counsellor = spbCounsellor();
    $therapy = spbTherapy($client, ['counsellor_id' => $counsellor->id, 'public' => false, 'anonymous' => false]);
    spbCharge($client, spbOrganization(), $therapy);

    $ward = collect($this->actingAs($guardian)->getJson(route('api.therapies.ward'))->assertOk()->json('data'))->firstWhere('id', $therapy->id);
    expect($ward)->not->toBeNull()->not->toHaveKey('sponsor');

    $recent = $this->actingAs($counsellor->user)->get('/')->assertOk()->viewData('page')['props']['recentTherapies'] ?? [];
    $row = collect($recent['data'] ?? $recent)->firstWhere('id', $therapy->id);
    expect($row)->not->toBeNull()->not->toHaveKey('sponsor');

    $own = $this->actingAs($client)->get('/')->assertOk()->viewData('page')['props']['recentTherapies'] ?? [];
    expect(collect($own['data'] ?? $own)->firstWhere('id', $therapy->id)['sponsor'])->toBe(['organizationName' => 'Acme Wellness']);
});

test('a refunded session charge, or a charge for a group\'s session, never counts', function () {
    $client = User::factory()->adult()->create();
    $organization = spbOrganization();
    $therapy = spbTherapy($client);
    $session = Session::unguarded(fn () => Session::factory()->create(['for_type' => Therapy::class, 'for_id' => $therapy->id]));
    Refund::factory()->create(['transaction_id' => spbCharge($client, $organization, $session)->id, 'status' => RefundStatusEnum::success->value]);
    // A group session whose id matches nothing of the therapy's -- and one whose for_id equals the therapy's id.
    $group = GroupTherapy::factory()->create(['addedby_type' => User::class, 'addedby_id' => $client->id, 'payment_type' => TherapyPaymentTypeEnum::paid->value]);
    $groupSession = Session::unguarded(fn () => Session::factory()->create(['for_type' => GroupTherapy::class, 'for_id' => $therapy->id]));
    spbCharge($client, $organization, $groupSession);

    expect(spbOwnList($this, $client)[$therapy->id])->not->toHaveKey('sponsor');
    expect(collect($this->actingAs($client)->getJson(route('api.group.therapies.user'))->assertOk()->json('data'))->firstWhere('id', $group->id))
        ->not->toHaveKey('sponsor');
});
