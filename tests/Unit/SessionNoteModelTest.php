<?php

use App\Models\Counsellor;
use App\Models\Session;
use App\Models\SessionNote;
use App\Models\User;
use Illuminate\Database\QueryException;

// SCRUM-196/TT-2.2a: confirms the session_notes migration/model shape ahead of TT-2.2b's
// authorization layer -- relations resolve, content is required, and soft delete behaves like
// every other soft-deletable model in this codebase.

test('a session note belongs to a session and a counsellor', function () {
    $session = Session::factory()->create();
    $counsellor = Counsellor::factory()->create(['user_id' => User::factory()]);

    $note = SessionNote::factory()->create([
        'session_id' => $session->id,
        'counsellor_id' => $counsellor->id,
        'content' => 'Client presented as withdrawn today.',
    ]);

    expect($note->session->id)->toBe($session->id);
    expect($note->counsellor->id)->toBe($counsellor->id);
    expect($note->content)->toBe('Client presented as withdrawn today.');
});

test('content is required', function () {
    expect(fn () => SessionNote::factory()->create(['content' => null]))
        ->toThrow(QueryException::class);
});

test('soft-deleting a note excludes it from default queries but keeps the row', function () {
    $note = SessionNote::factory()->create();

    $note->delete();

    expect(SessionNote::find($note->id))->toBeNull();
    expect(SessionNote::withTrashed()->find($note->id))->not->toBeNull();
});

test('force-deleting the authoring counsellor preserves the note but nulls its author reference', function () {
    $counsellor = Counsellor::factory()->create(['user_id' => User::factory()]);
    $note = SessionNote::factory()->create(['counsellor_id' => $counsellor->id]);

    $counsellor->forceDelete();

    expect(SessionNote::find($note->id)->counsellor_id)->toBeNull();
});

test('force-deleting the owning session preserves the note but nulls its session reference', function () {
    $session = Session::factory()->create();
    $note = SessionNote::factory()->create(['session_id' => $session->id]);

    $session->forceDelete();

    expect(SessionNote::find($note->id)->session_id)->toBeNull();
});

test('a session can have multiple notes from the same counsellor', function () {
    $session = Session::factory()->create();
    $counsellor = Counsellor::factory()->create(['user_id' => User::factory()]);

    SessionNote::factory()->count(3)->create([
        'session_id' => $session->id,
        'counsellor_id' => $counsellor->id,
    ]);

    expect(SessionNote::where('session_id', $session->id)->count())->toBe(3);
});
