<?php

use App\Enums\OrganizationAdminRoleEnum;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

// SCRUM-182/TT-10.4: first-ever coverage for organization logo upload, mirroring
// UpdateCounsellorImagesTest's pattern (TT-10.2) since this uses the exact same tagged
// fileables mechanism.

function anOrganizationWithAdminForLogoRoute(): array
{
    $organization = Organization::factory()->create(['is_provider' => true]);
    $admin = User::factory()->create();
    $organization->admins()->attach($admin->id, ['role' => OrganizationAdminRoleEnum::owner->value]);

    return [$organization, $admin];
}

test('an org admin can upload a logo for the first time', function () {
    Storage::fake('public');
    [$organization, $admin] = anOrganizationWithAdminForLogoRoute();
    $this->actingAs($admin);

    $response = $this->patch("/organizations/{$organization->id}", [
        'logo' => UploadedFile::fake()->image('logo.jpg'),
    ]);

    $response->assertOk();

    $organization->refresh();
    expect($organization->logo)->not->toBeNull();
    Storage::disk('public')->assertExists($organization->logo->path.'/'.$organization->logo->name);
    $this->assertDatabaseHas('fileables', [
        'fileable_type' => Organization::class,
        'fileable_id' => $organization->id,
        'tag' => 'logo',
    ]);
});

test('uploading a new logo replaces the old one, deleting its file and pivot row', function () {
    Storage::fake('public');
    [$organization, $admin] = anOrganizationWithAdminForLogoRoute();
    $this->actingAs($admin);

    $this->patch("/organizations/{$organization->id}", [
        'logo' => UploadedFile::fake()->image('first.jpg'),
    ]);
    $organization->refresh();
    $oldLogo = $organization->logo;
    $oldPath = $oldLogo->path.'/'.$oldLogo->name;

    $this->patch("/organizations/{$organization->id}", [
        'logo' => UploadedFile::fake()->image('second.jpg'),
    ]);
    $organization->refresh();

    expect($organization->logo)->not->toBeNull();
    expect($organization->logo->id)->not->toBe($oldLogo->id);
    Storage::disk('public')->assertMissing($oldPath);
    $this->assertDatabaseMissing('files', ['id' => $oldLogo->id]);
    expect(
        DB::table('fileables')
            ->where('fileable_type', Organization::class)
            ->where('fileable_id', $organization->id)
            ->where('tag', 'logo')
            ->count()
    )->toBe(1);
});

test('an org admin can delete the organization logo', function () {
    Storage::fake('public');
    [$organization, $admin] = anOrganizationWithAdminForLogoRoute();
    $this->actingAs($admin);

    $this->patch("/organizations/{$organization->id}", [
        'logo' => UploadedFile::fake()->image('logo.jpg'),
    ]);
    $organization->refresh();
    $logo = $organization->logo;
    $path = $logo->path.'/'.$logo->name;

    $response = $this->patch("/organizations/{$organization->id}", [
        'deleteLogo' => true,
    ]);

    $response->assertOk();
    $organization->refresh();
    expect($organization->logo)->toBeNull();
    Storage::disk('public')->assertMissing($path);
    $this->assertDatabaseMissing('files', ['id' => $logo->id]);
});

test('a non-admin cannot upload a logo for an organization they do not administer', function () {
    Storage::fake('public');
    [$organization] = anOrganizationWithAdminForLogoRoute();
    $outsider = User::factory()->create();
    $this->actingAs($outsider);

    $response = $this->patch("/organizations/{$organization->id}", [
        'logo' => UploadedFile::fake()->image('logo.jpg'),
    ]);

    $response->assertStatus(403);
    expect($organization->fresh()->logo)->toBeNull();
});
