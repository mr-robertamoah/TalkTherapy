<?php

use App\Actions\File\MovePublicFilesToPrivateDiskAction;
use App\Models\File;
use App\Models\Message;
use Illuminate\Support\Facades\Storage;

// SCRUM-336: the generic move, for chat attachments (licenses are covered by
// MoveLicenseDocumentsToPrivateDiskActionTest, through the same action).

beforeEach(function () {
    Storage::fake('public');
    Storage::fake('message_attachments');
});

test('message attachments move to the private disk and leave nothing behind publicly', function () {
    Storage::disk('public')->put('messages/a.jpg', 'a');
    $file = File::factory()->create(['name' => 'a.jpg', 'path' => 'messages', 'storage' => 'public']);
    Message::factory()->create()->files()->attach($file->id);
    Storage::disk('public')->put('messages/orphan.jpg', 'o');
    Storage::disk('public')->put('avatars/me.jpg', 'me');

    $moved = MovePublicFilesToPrivateDiskAction::new()->execute('messages', 'messages', 'message_attachments');

    expect($moved)->toBe(2)
        ->and($file->fresh()->storage)->toBe('message_attachments')
        ->and(Storage::disk('message_attachments')->get('messages/a.jpg'))->toBe('a')
        ->and(Storage::disk('message_attachments')->get('messages/orphan.jpg'))->toBe('o')
        ->and(Storage::disk('public')->allFiles('messages'))->toBe([])
        // Another category on the same public disk is untouched.
        ->and(Storage::disk('public')->exists('avatars/me.jpg'))->toBeTrue();
});

// SCRUM-341: host-run installs uploaded to the default 'local' disk instead of 'public'.
test('attachments on the local disk move to the private disk too', function () {
    Storage::fake('local');
    Storage::disk('local')->put('messages/b.jpg', 'b');
    $file = File::factory()->create(['name' => 'b.jpg', 'path' => 'messages', 'storage' => 'local']);
    Message::factory()->create()->files()->attach($file->id);

    $moved = MovePublicFilesToPrivateDiskAction::new()->execute('messages', 'messages', 'message_attachments', 'local');

    expect($moved)->toBe(1)
        ->and($file->fresh()->storage)->toBe('message_attachments')
        ->and(Storage::disk('message_attachments')->get('messages/b.jpg'))->toBe('b')
        ->and(Storage::disk('local')->allFiles('messages'))->toBe([]);
});

// Security review (SCRUM-342): this can run with more privilege than the web user who controls the
// uploads directory -- a planted symlink must never pull another file into private storage.
test('a symlinked upload is never copied into private storage', function () {
    Storage::disk('public')->put('secret-outside.txt', 'not an attachment');
    Storage::disk('public')->makeDirectory('messages');
    symlink(Storage::disk('public')->path('secret-outside.txt'), Storage::disk('public')->path('messages/evil.jpg'));
    $file = File::factory()->create(['name' => 'evil.jpg', 'path' => 'messages', 'storage' => 'public']);
    Message::factory()->create()->files()->attach($file->id);

    MovePublicFilesToPrivateDiskAction::new()->execute('messages', 'messages', 'message_attachments');

    expect(Storage::disk('message_attachments')->exists('messages/evil.jpg'))->toBeFalse()
        ->and(Storage::disk('public')->get('secret-outside.txt'))->toBe('not an attachment');
});
