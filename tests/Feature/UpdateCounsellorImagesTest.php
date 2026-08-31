<?php

use App\Models\Counsellor;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

// SCRUM-182/TT-10.2: first-ever coverage for the counsellor avatar/cover upload flow, which
// has been live since before TT-10.1/10.2 with zero tests. Also confirms the migration from
// avatar_id/cover_id FK columns onto the tagged fileables pivot behaves identically from the
// route's perspective.

function aCounsellorWithUserForImagesRoute(): Counsellor
{
    return Counsellor::factory()->create(['user_id' => User::factory()]);
}

test('a counsellor can upload an avatar for the first time', function () {
    Storage::fake('public');
    $counsellor = aCounsellorWithUserForImagesRoute();
    $this->actingAs($counsellor->user);

    $response = $this->post("/counsellor/{$counsellor->id}", [
        'avatar' => UploadedFile::fake()->image('avatar.jpg'),
    ]);

    $response->assertRedirect();
    $response->assertSessionDoesntHaveErrors();

    $counsellor->refresh();
    expect($counsellor->avatar)->not->toBeNull();
    Storage::disk('public')->assertExists($counsellor->avatar->path.'/'.$counsellor->avatar->name);
    $this->assertDatabaseHas('fileables', [
        'fileable_type' => Counsellor::class,
        'fileable_id' => $counsellor->id,
        'tag' => 'avatar',
    ]);
});

test('a counsellor can upload a cover image for the first time', function () {
    Storage::fake('public');
    $counsellor = aCounsellorWithUserForImagesRoute();
    $this->actingAs($counsellor->user);

    $response = $this->post("/counsellor/{$counsellor->id}", [
        'cover' => UploadedFile::fake()->image('cover.jpg'),
    ]);

    $response->assertRedirect();
    $response->assertSessionDoesntHaveErrors();

    $counsellor->refresh();
    expect($counsellor->cover)->not->toBeNull();
    Storage::disk('public')->assertExists($counsellor->cover->path.'/'.$counsellor->cover->name);
    $this->assertDatabaseHas('fileables', [
        'fileable_type' => Counsellor::class,
        'fileable_id' => $counsellor->id,
        'tag' => 'cover',
    ]);
});

test('uploading a new avatar replaces the old one, deleting its file and pivot row', function () {
    Storage::fake('public');
    $counsellor = aCounsellorWithUserForImagesRoute();
    $this->actingAs($counsellor->user);

    $this->post("/counsellor/{$counsellor->id}", [
        'avatar' => UploadedFile::fake()->image('first.jpg'),
    ]);
    $counsellor->refresh();
    $oldAvatar = $counsellor->avatar;
    $oldPath = $oldAvatar->path.'/'.$oldAvatar->name;

    $this->post("/counsellor/{$counsellor->id}", [
        'avatar' => UploadedFile::fake()->image('second.jpg'),
    ]);
    $counsellor->refresh();

    expect($counsellor->avatar)->not->toBeNull();
    expect($counsellor->avatar->id)->not->toBe($oldAvatar->id);
    Storage::disk('public')->assertMissing($oldPath);
    $this->assertDatabaseMissing('files', ['id' => $oldAvatar->id]);
    expect(
        DB::table('fileables')
            ->where('fileable_type', Counsellor::class)
            ->where('fileable_id', $counsellor->id)
            ->where('tag', 'avatar')
            ->count()
    )->toBe(1);
});

test('a counsellor can delete their avatar', function () {
    Storage::fake('public');
    $counsellor = aCounsellorWithUserForImagesRoute();
    $this->actingAs($counsellor->user);

    $this->post("/counsellor/{$counsellor->id}", [
        'avatar' => UploadedFile::fake()->image('avatar.jpg'),
    ]);
    $counsellor->refresh();
    $avatar = $counsellor->avatar;
    $path = $avatar->path.'/'.$avatar->name;

    $response = $this->post("/counsellor/{$counsellor->id}", [
        'deleteAvatar' => true,
    ]);

    $response->assertRedirect();
    $counsellor->refresh();
    expect($counsellor->avatar)->toBeNull();
    Storage::disk('public')->assertMissing($path);
    $this->assertDatabaseMissing('files', ['id' => $avatar->id]);
});

test('uploading an avatar and a cover in the same request tags each correctly', function () {
    Storage::fake('public');
    $counsellor = aCounsellorWithUserForImagesRoute();
    $this->actingAs($counsellor->user);

    $this->post("/counsellor/{$counsellor->id}", [
        'avatar' => UploadedFile::fake()->image('avatar.jpg'),
        'cover' => UploadedFile::fake()->image('cover.jpg'),
    ]);

    $counsellor->refresh();
    expect($counsellor->avatar)->not->toBeNull();
    expect($counsellor->cover)->not->toBeNull();
    expect($counsellor->avatar->id)->not->toBe($counsellor->cover->id);
});
