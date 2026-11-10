<?php

use App\Http\Resources\MessageResource;
use App\Models\Counsellor;
use App\Models\File;
use App\Models\GroupTherapy;
use App\Models\Message;
use App\Models\Session;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

// SCRUM-336: chat attachments are reachable ONLY through this authorized route -- never a public
// URL -- and a confidential message's files only by its parties.

function aGroupChatWithAttachmentForAttachmentRoute(bool $confidential = false): array
{
    Storage::fake('message_attachments');

    $creator = User::factory()->adult()->create();
    $groupTherapy = GroupTherapy::factory()->create([
        'addedby_type' => User::class,
        'addedby_id' => $creator->id,
        'public' => false,
    ]);
    $counsellorUser = User::factory()->create();
    $counsellor = Counsellor::factory()->create(['user_id' => $counsellorUser->id]);
    $groupTherapy->counsellors()->attach($counsellor->id, ['state' => 'ACTIVE', 'role' => 'NORMAL']);
    $member = User::factory()->adult()->create();
    $groupTherapy->users()->attach($member->id, ['anonymous' => false]);
    $session = Session::factory()->create(['for_id' => $groupTherapy->id, 'for_type' => GroupTherapy::class, 'status' => 'IN_SESSION']);

    $message = Message::factory()->create([
        'for_id' => $session->id, 'for_type' => Session::class,
        'from_type' => User::class, 'from_id' => $member->id,
        'to_type' => Counsellor::class, 'to_id' => $counsellor->id,
        'confidential' => $confidential,
    ]);
    Storage::disk('message_attachments')->putFileAs('messages', UploadedFile::fake()->image('photo.png'), 'photo.png');
    $file = File::factory()->create(['name' => 'photo.png', 'path' => 'messages', 'storage' => 'message_attachments', 'mime' => 'text/html']);
    $message->files()->attach($file->id);

    return compact('creator', 'counsellorUser', 'member', 'session', 'message', 'file');
}

function attachmentUrlForAttachmentRoute(array $data): string
{
    return route('messages.attachments.show', ['message' => $data['message']->id, 'file' => $data['file']->id]);
}

test('every participant can view a normal message\'s attachment, served by its real type', function () {
    $data = aGroupChatWithAttachmentForAttachmentRoute();

    foreach ([$data['member'], $data['counsellorUser'], $data['creator']] as $viewer) {
        $this->actingAs($viewer)->get(attachmentUrlForAttachmentRoute($data))
            ->assertSuccessful()
            // The stored mime claims text/html; the server ignores it.
            ->assertHeader('Content-Type', 'image/png')
            ->assertHeader('X-Content-Type-Options', 'nosniff');
    }
});

test('an outsider gets a 404', function () {
    $data = aGroupChatWithAttachmentForAttachmentRoute();

    $this->actingAs(User::factory()->create())->getJson(attachmentUrlForAttachmentRoute($data))
        ->assertStatus(404)
        ->assertJsonPath('error', 'Attachment not found.');
});

test('a confidential message\'s attachment is only for its parties', function () {
    $data = aGroupChatWithAttachmentForAttachmentRoute(confidential: true);

    $this->actingAs($data['counsellorUser'])->get(attachmentUrlForAttachmentRoute($data))->assertSuccessful();
    $this->actingAs($data['member'])->get(attachmentUrlForAttachmentRoute($data))->assertSuccessful();
    $this->actingAs($data['creator'])->getJson(attachmentUrlForAttachmentRoute($data))->assertStatus(404);
});

test('a file that is not attached to the message, or unknown ids, get the same 404', function () {
    $data = aGroupChatWithAttachmentForAttachmentRoute();
    $other = File::factory()->create(['storage' => 'message_attachments', 'path' => 'messages', 'name' => 'x.png']);

    foreach ([
        ['message' => $data['message']->id, 'file' => $other->id],
        ['message' => 999999, 'file' => $data['file']->id],
        ['message' => $data['message']->id, 'file' => 999999],
    ] as $parameters) {
        $this->actingAs($data['member'])->getJson(route('messages.attachments.show', $parameters))
            ->assertStatus(404)
            ->assertExactJson(['status' => false, 'error' => 'Attachment not found.']);
    }
});

test('a guest cannot view an attachment', function () {
    $data = aGroupChatWithAttachmentForAttachmentRoute();

    $this->getJson(attachmentUrlForAttachmentRoute($data))->assertUnauthorized();
});

test('message responses and broadcasts point at the authorized route, never a public URL', function () {
    $data = aGroupChatWithAttachmentForAttachmentRoute();

    $files = MessageResource::present($data['message']->fresh(), $data['member'])['files'];
    $broadcastFiles = MessageResource::present($data['message']->fresh(), null)['files'];

    expect($broadcastFiles[0]['url'])->toBe(attachmentUrlForAttachmentRoute($data));

    expect($files[0]['url'])->toBe(attachmentUrlForAttachmentRoute($data))
        ->and($files[0]['url'])->not->toContain('/storage/')
        ->and(fn () => $data['file']->url)->toThrow(RuntimeException::class);
});

test('a newly sent attachment is stored on the private disk', function () {
    $data = aGroupChatWithAttachmentForAttachmentRoute();
    Storage::fake('public');

    $this->actingAs($data['member'])->withHeaders(['Accept' => 'application/json'])->post(route('api.messages.create'), [
        'content' => 'see attached',
        'type' => 'NORMAL',
        'files' => [UploadedFile::fake()->image('new.jpg')],
        'fromType' => 'User', 'fromId' => $data['member']->id,
        'forType' => 'Session', 'forId' => $data['session']->id,
    ])->assertSuccessful();

    $file = Message::latest('id')->first()->files->first();
    expect($file->storage)->toBe('message_attachments')
        ->and(Storage::disk('message_attachments')->exists($file->diskPath()))->toBeTrue()
        ->and(Storage::disk('public')->allFiles())->toBe([]);
});

// --- Code-review follow-ups (SCRUM-336) ---

test('a file stored on another private disk is never streamed through a message', function () {
    $data = aGroupChatWithAttachmentForAttachmentRoute();
    Storage::fake('identity_documents');
    Storage::disk('identity_documents')->put('messages/photo.png', 'id scan');
    $data['file']->update(['storage' => 'identity_documents']);

    $this->actingAs($data['member'])->getJson(attachmentUrlForAttachmentRoute($data))->assertStatus(404);
});

// SCRUM-341: the transitional disks are gone -- only the private disk is ever served.
test('a row left on a public or local disk is never served through the route', function () {
    $data = aGroupChatWithAttachmentForAttachmentRoute();

    foreach (['public', 'local'] as $disk) {
        Storage::fake($disk);
        Storage::disk($disk)->put('messages/photo.png', 'old copy');
        $data['file']->update(['storage' => $disk]);

        $this->actingAs($data['member'])->getJson(attachmentUrlForAttachmentRoute($data))->assertStatus(404);
    }
});

test('a scriptable upload is downloaded as an opaque file, an allowed video plays inline', function () {
    $data = aGroupChatWithAttachmentForAttachmentRoute();
    Storage::disk('message_attachments')->put('messages/photo.png', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');

    $svg = $this->actingAs($data['member'])->get(attachmentUrlForAttachmentRoute($data));
    $svg->assertSuccessful()->assertHeader('Content-Type', 'application/octet-stream');
    expect($svg->headers->get('Content-Disposition'))->toStartWith('attachment');

    // A minimal MP4 header (an ftyp box), enough for server-side type detection.
    Storage::disk('message_attachments')->put('messages/photo.png', "\x00\x00\x00\x18ftypmp42\x00\x00\x00\x00mp42isom".str_repeat("\x00", 64));

    $video = $this->actingAs($data['member'])->get(attachmentUrlForAttachmentRoute($data));
    $video->assertSuccessful()->assertHeader('Content-Type', 'video/mp4');
    expect($video->headers->get('Content-Disposition'))->toStartWith('inline');
});

test('range requests are honoured, so media can seek and play in Safari', function () {
    $data = aGroupChatWithAttachmentForAttachmentRoute();

    $this->actingAs($data['member'])
        ->get(attachmentUrlForAttachmentRoute($data), ['Range' => 'bytes=0-9'])
        ->assertStatus(206)
        ->assertHeader('Content-Range');
});

test('a file that is gone from disk gets the same 404, not an error', function () {
    $data = aGroupChatWithAttachmentForAttachmentRoute();
    Storage::disk('message_attachments')->delete('messages/photo.png');

    $this->actingAs($data['member'])->getJson(attachmentUrlForAttachmentRoute($data))
        ->assertStatus(404)
        ->assertExactJson(['status' => false, 'error' => 'Attachment not found.']);
});

test('a deleted message\'s attachment is gone', function () {
    $data = aGroupChatWithAttachmentForAttachmentRoute();
    $data['message']->delete();

    $this->actingAs($data['member'])->getJson(attachmentUrlForAttachmentRoute($data))
        ->assertStatus(404)
        ->assertExactJson(['status' => false, 'error' => 'Attachment not found.']);
});

test('an attachment added by editing a message is stored on the private disk', function () {
    $data = aGroupChatWithAttachmentForAttachmentRoute();

    $this->actingAs($data['member'])->withHeaders(['Accept' => 'application/json'])
        ->post(route('api.messages.update', $data['message']->id), [
            'content' => 'with another file',
            'files' => [UploadedFile::fake()->image('added.jpg')],
        ])->assertSuccessful();

    $added = $data['message']->fresh()->files->firstWhere('id', '!=', $data['file']->id);
    expect($added->storage)->toBe('message_attachments')
        ->and(Storage::disk('message_attachments')->exists($added->diskPath()))->toBeTrue();
});
