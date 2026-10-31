<?php

use App\Actions\GetModelWithModelNameAndIdAction;
use App\Models\Administrator;
use App\Models\Counsellor;
use App\Models\GroupTherapy;
use App\Models\Link;
use App\Models\Organization;
use App\Models\OrganizationCounsellor;
use App\Models\Therapy;
use App\Models\User;

// SCRUM-349: the client-named model lookup resolves multi-word models (it lowercased "GroupTherapy"
// to an autoload-miss), and only from an allowlist (it resolved ANY single-word model).

test('multi-word and single-word names resolve, whatever their case', function () {
    $group = GroupTherapy::factory()->create();
    $therapy = Therapy::factory()->create();
    $lookup = GetModelWithModelNameAndIdAction::new();

    foreach (['GroupTherapy', 'grouptherapy', 'GROUPTHERAPY', 'group_therapy'] as $name) {
        expect($lookup->execute($name, $group->id)?->is($group))->toBeTrue();
    }
    foreach (['Therapy', 'therapy', 'THERAPY'] as $name) {
        expect($lookup->execute($name, (string) $therapy->id)?->is($therapy))->toBeTrue();
    }
});

test('models no caller accepts never resolve, even for ids that exist', function () {
    $admin = Administrator::factory()->create(['user_id' => User::factory()->create()->id]);
    $membership = OrganizationCounsellor::factory()->create([
        'organization_id' => Organization::factory()->create()->id,
        'counsellor_id' => Counsellor::factory()->create(['user_id' => User::factory()->create()->id])->id,
    ]);
    $user = User::factory()->create();
    $lookup = GetModelWithModelNameAndIdAction::new();

    expect($lookup->execute('Administrator', $admin->id))->toBeNull()
        ->and($lookup->execute('OrganizationCounsellor', $membership->id))->toBeNull()
        ->and($lookup->execute('Transaction', 1))->toBeNull()
        ->and($lookup->execute('User', [$user->id]))->toBeNull()
        ->and($lookup->execute(['User'], $user->id))->toBeNull()
        ->and($lookup->execute('User', $user->id.' or 1=1'))->toBeNull()
        // Sanity: the same id does resolve through an allowed name.
        ->and($lookup->execute('User', $user->id)?->is($user))->toBeTrue();
});

test('a caller can narrow the allowlist', function () {
    $group = GroupTherapy::factory()->create();

    expect(GetModelWithModelNameAndIdAction::new()->execute('GroupTherapy', $group->id, [Therapy::class]))->toBeNull();
});

test('a group participant can now wait for the group\'s alerts (this always 422ed)', function () {
    $creator = User::factory()->create();
    $group = GroupTherapy::factory()->create(['addedby_type' => User::class, 'addedby_id' => $creator->id]);

    $this->actingAs($creator)->postJson(route('alert.wait'), ['alertableType' => 'GroupTherapy', 'alertableId' => $group->id])
        ->assertSuccessful();
});

test('group therapies stay out of the unauthorized likes and comments lists', function () {
    $group = GroupTherapy::factory()->create(['anonymous' => true, 'public' => false]);

    $likes = $this->getJson(route('api.likes', ['likeableType' => 'GroupTherapy', 'likeableId' => $group->id]));
    $comments = $this->getJson(route('api.comments', ['commentableType' => 'GroupTherapy', 'commentableId' => $group->id]));

    $likes->assertStatus(422);
    $comments->assertStatus(422);
});

test('a link must be for the kind of model its type acts on', function () {
    $owner = User::factory()->create();
    $group = GroupTherapy::factory()->create(['addedby_type' => User::class, 'addedby_id' => $owner->id]);

    // A THERAPY_COUNSELLOR link aimed at a group would otherwise act on the Therapy with the same id.
    $this->actingAs($owner)->postJson(route('api.links.create'), [
        'type' => 'THERAPY_COUNSELLOR', 'addedbyType' => 'User', 'addedbyId' => $owner->id,
        'forType' => 'GroupTherapy', 'forId' => $group->id,
    ])->assertStatus(422);

    expect(Link::query()->count())->toBe(0);
});
