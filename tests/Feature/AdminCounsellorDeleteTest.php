<?php

use App\Enums\AdministratorTypeEnum;
use App\Models\Administrator;
use App\Models\Counsellor;
use App\Models\User;

// SCRUM-134: admin-triggered counsellor deletion (e.g. revoking a counsellor found practicing
// without a valid license). Reuses CounsellorService::deleteCounsellor()'s same eligibility gate
// as self-service deletion -- requires isSuperAdmin(), mirroring UserService::deleteUserByAdmin().

test('a super admin can delete an eligible counsellor account', function () {
    $superAdmin = User::factory()->has(Administrator::factory())->create();
    $counsellor = Counsellor::factory()->create(['user_id' => User::factory()]);

    $this->actingAs($superAdmin)
        ->delete(route('admin.counsellors.delete', ['counsellorId' => $counsellor->id]))
        ->assertOk();

    expect($counsellor->fresh()->deleted_at)->not->toBeNull();
});

test('a non-super admin cannot delete a counsellor account', function () {
    $normalAdmin = User::factory()
        ->has(Administrator::factory()->state(['type' => AdministratorTypeEnum::normal->value]))
        ->create();
    $counsellor = Counsellor::factory()->create(['user_id' => User::factory()]);

    $this->actingAs($normalAdmin)
        ->delete(route('admin.counsellors.delete', ['counsellorId' => $counsellor->id]))
        ->assertStatus(422);

    expect($counsellor->fresh()->deleted_at)->toBeNull();
});

test('a regular user cannot delete a counsellor account via the admin route', function () {
    $user = User::factory()->create();
    $counsellor = Counsellor::factory()->create(['user_id' => User::factory()]);

    $this->actingAs($user)
        ->delete(route('admin.counsellors.delete', ['counsellorId' => $counsellor->id]))
        ->assertStatus(422);

    expect($counsellor->fresh()->deleted_at)->toBeNull();
});

test('an unauthenticated request to the admin route is rejected', function () {
    $counsellor = Counsellor::factory()->create(['user_id' => User::factory()]);

    $this->deleteJson(route('admin.counsellors.delete', ['counsellorId' => $counsellor->id]))
        ->assertStatus(401);
});
