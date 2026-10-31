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
