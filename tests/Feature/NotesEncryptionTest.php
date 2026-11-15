<?php

use App\Models\MessageNote;
use App\Models\SessionNote;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

// SCRUM-437: counsellors' notes encrypted at rest -- and the migration that encrypts the ones already there.

function nenMigration(): object
{
    return require database_path('migrations/2026_12_05_100000_encrypt_session_and_message_notes_content.php');
}

test('existing notes are encrypted, soft-deleted ones too; running it again changes nothing; down() gives them back', function () {
    $note = SessionNote::factory()->create();
    $gone = MessageNote::factory()->create();
    $gone->delete();
    // As they were before: plain text in the column.
    DB::table('session_notes')->where('id', $note->id)->update(['content' => 'nen plain session note ✓']);
    DB::table('message_notes')->where('id', $gone->id)->update(['content' => 'nen plain message note']);

    nenMigration()->up();
    $first = DB::table('session_notes')->where('id', $note->id)->value('content');
    nenMigration()->up();

    expect($first)->not->toContain('nen plain')
        ->and(DB::table('session_notes')->where('id', $note->id)->value('content'))->toBe($first)
        ->and(Crypt::decryptString($first))->toBe('nen plain session note ✓')
        ->and(MessageNote::withTrashed()->find($gone->id)->content)->toBe('nen plain message note');

    nenMigration()->down();
    expect(DB::table('session_notes')->where('id', $note->id)->value('content'))->toBe('nen plain session note ✓')
        ->and(DB::table('message_notes')->where('id', $gone->id)->value('content'))->toBe('nen plain message note');
    nenMigration()->up();
});

test('a long note in a multi-byte script is kept whole', function () {
    $text = str_repeat('😊', 5000);
    $note = SessionNote::factory()->create(['content' => $text]);

    expect($note->fresh()->content)->toBe($text)
        ->and(DB::table('session_notes')->where('id', $note->id)->value('content'))->not->toContain('😊');
});

test('rolling back refuses when a note doesn\'t decrypt (another key) -- it never leaves ciphertext for the old code', function () {
    $note = SessionNote::factory()->create();
    DB::table('session_notes')->where('id', $note->id)->update(['content' => 'not-encrypted-with-this-key']);

    expect(fn () => nenMigration()->down())->toThrow(RuntimeException::class);
});
