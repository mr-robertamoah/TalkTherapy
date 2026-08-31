<?php

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

// SCRUM-182/TT-10.6: first-ever coverage for user avatar upload, mirroring
// UpdateCounsellorImagesTest (TT-10.2) and UpdateOrganizationLogoTest (TT-10.4) since this uses
// the exact same tagged fileables mechanism. Self-service only -- the route never takes a target
// user id, so "can't touch someone else's avatar" is proven structurally (there's no id to pass),
// not by a guard clause; the test below confirms that in practice, not just in theory.

test('a user can upload an avatar for the first time', function () {
    Storage::fake('public');
    $user = User::factory()->create();
    $this->actingAs($user);

    $response = $this->post('/profile/avatar', [
        'avatar' => UploadedFile::fake()->image('avatar.jpg'),
    ]);

    $response->assertRedirect();
    $response->assertSessionDoesntHaveErrors();

    $user->refresh();
    expect($user->avatar)->not->toBeNull();
    Storage::disk('public')->assertExists($user->avatar->path.'/'.$user->avatar->name);
    $this->assertDatabaseHas('fileables', [
        'fileable_type' => User::class,
        'fileable_id' => $user->id,
        'tag' => 'avatar',
    ]);
});

test('uploading a new avatar replaces the old one, deleting its file and pivot row', function () {
    Storage::fake('public');
    $user = User::factory()->create();
    $this->actingAs($user);

    $this->post('/profile/avatar', [
        'avatar' => UploadedFile::fake()->image('first.jpg'),
    ]);
    $user->refresh();
    $oldAvatar = $user->avatar;
    $oldPath = $oldAvatar->path.'/'.$oldAvatar->name;

    $this->post('/profile/avatar', [
        'avatar' => UploadedFile::fake()->image('second.jpg'),
    ]);
    $user->refresh();

    expect($user->avatar)->not->toBeNull();
    expect($user->avatar->id)->not->toBe($oldAvatar->id);
    Storage::disk('public')->assertMissing($oldPath);
    $this->assertDatabaseMissing('files', ['id' => $oldAvatar->id]);
    expect(
        DB::table('fileables')
            ->where('fileable_type', User::class)
            ->where('fileable_id', $user->id)
            ->where('tag', 'avatar')
            ->count()
    )->toBe(1);
});

test('a user can delete their own avatar', function () {
    Storage::fake('public');
    $user = User::factory()->create();
    $this->actingAs($user);

    $this->post('/profile/avatar', [
        'avatar' => UploadedFile::fake()->image('avatar.jpg'),
    ]);
    $user->refresh();
    $avatar = $user->avatar;
    $path = $avatar->path.'/'.$avatar->name;

    $response = $this->post('/profile/avatar', [
        'deleteAvatar' => true,
    ]);

    $response->assertRedirect();
    $user->refresh();
    expect($user->avatar)->toBeNull();
    Storage::disk('public')->assertMissing($path);
    $this->assertDatabaseMissing('files', ['id' => $avatar->id]);
});

test('uploading an avatar only ever affects the acting user, never another user', function () {
    Storage::fake('public');
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $this->actingAs($user);

    $this->post('/profile/avatar', [
        'avatar' => UploadedFile::fake()->image('avatar.jpg'),
    ]);

    expect($user->fresh()->avatar)->not->toBeNull();
    expect($otherUser->fresh()->avatar)->toBeNull();
});

test('an unauthenticated request cannot upload an avatar', function () {
    Storage::fake('public');

    $response = $this->post('/profile/avatar', [
        'avatar' => UploadedFile::fake()->image('avatar.jpg'),
    ]);

    $response->assertRedirect('/login');
});
