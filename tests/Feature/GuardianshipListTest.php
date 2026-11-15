<?php

use App\Models\Guardianship;
use App\Models\User;
use Illuminate\Support\Facades\DB;

// SCRUM-439: the profile's guardianship list -- each side sees the other person, with a real "since" date, in a fixed
// number of queries.

function gdlAdult(array $attributes = []): User
{
    return User::factory()->adult()->create($attributes);
}

test('a guardian sees their wards, a ward their guardians -- with a since date', function () {
    $guardian = gdlAdult(['username' => 'gdl_guardian']);
    $ward = User::factory()->create(['dob' => now()->subYears(15), 'username' => 'gdl_ward']);
    Guardianship::query()->create(['guardian_id' => $guardian->id, 'ward_id' => $ward->id]);

    $this->actingAs($guardian)->getJson(route('api.users.guardianship'))->assertOk()
        ->assertJsonPath('data.0.isWard', true)->assertJsonPath('data.0.ward.username', 'gdl_ward')
        ->assertJsonPath('data.0.since', now()->toDateString())->assertJsonMissingPath('data.0.guardian');

    $this->actingAs($ward)->getJson(route('api.users.guardianship'))->assertOk()
        ->assertJsonPath('data.0.isWard', false)->assertJsonPath('data.0.guardian.username', 'gdl_guardian');
});

test('the list takes the same number of queries for one ward or many', function () {
    $count = function (int $wards) {
        $guardian = gdlAdult();
        foreach (range(1, $wards) as $i) {
            Guardianship::query()->create(['guardian_id' => $guardian->id, 'ward_id' => User::factory()->create(['dob' => now()->subYears(15)])->id]);
        }
        $this->actingAs($guardian)->getJson(route('api.users.guardianship'))->assertOk();
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($guardian)->getJson(route('api.users.guardianship'))->assertOk();
        $n = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $n;
    };

    expect($count(1))->toBe($count(6));
});
