<?php

use App\Enums\TherapyStatusEnum;
use App\Models\Counsellor;
use App\Models\Therapy;
use App\Models\User;

// The Home page's "Public Therapies" row (GET /api/therapies/random) must only ever list public
// therapies -- someone else's private therapy (its name, background story and counsellor) must never
// reach another person's Home page. Helpers are file-local and rtp-prefixed (--parallel).

function rtpTherapy(User $client, bool $public, array $attributes = []): Therapy
{
    return Therapy::factory()->create([
        'addedby_type' => User::class, 'addedby_id' => $client->id, 'public' => $public,
        'status' => TherapyStatusEnum::pending->value, ...$attributes,
    ]);
}

function rtpListedIds($response): array
{
    return collect($response->assertOk()->json('data'))->pluck('id')->all();
}

test('a signed-in client sees only other people\'s public therapies, never a private one', function () {
    $viewer = User::factory()->adult()->create();
    $other = User::factory()->adult()->create();
    $public = rtpTherapy($other, true);
    $private = rtpTherapy($other, false);
    $ownPublic = rtpTherapy($viewer, true);
    $ownPrivate = rtpTherapy($viewer, false);

    $ids = rtpListedIds($this->actingAs($viewer)->getJson('/api/therapies/random'));

    expect($ids)->toContain($public->id)
        ->not->toContain($private->id)
        ->not->toContain($ownPrivate->id)
        ->not->toContain($ownPublic->id);
});

test('a guest and a counsellor see only public therapies too', function () {
    $other = User::factory()->adult()->create();
    $public = rtpTherapy($other, true);
    $private = rtpTherapy($other, false);
    $counsellor = Counsellor::factory()->create(['user_id' => User::factory()->adult()->create()->id]);
    $theirs = rtpTherapy($other, true, ['counsellor_id' => $counsellor->id]);

    expect(rtpListedIds($this->getJson('/api/therapies/random')))->toContain($public->id)->not->toContain($private->id);

    $ids = rtpListedIds($this->actingAs($counsellor->user)->getJson('/api/therapies/random'));
    expect($ids)->toContain($public->id)
        ->not->toContain($private->id)
        ->not->toContain($theirs->id);
});
