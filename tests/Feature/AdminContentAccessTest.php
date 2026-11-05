<?php

use App\Enums\LicensingAuthorityTypeEnum;
use App\Enums\LicensingTypeEnum;
use App\Models\AdminContentAccess;
use App\Models\Administrator;
use App\Models\Counsellor;
use App\Models\File;
use App\Models\License;
use App\Models\LicensingAuthority;
use App\Models\Message;
use App\Models\Session;
use App\Models\Therapy;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

// SCRUM-360: an administrator reading content they could only reach as an administrator is
// recorded -- one row per fetch, append-only. Participants, public therapies and an admin's own
// documents are not.

function acaAdmin(string $type = 'SUPER'): User
{
    return User::factory()->adult()->has(Administrator::factory()->state(['type' => $type]))->create();
}

function acaTherapy(bool $public = false): array
{
    $client = User::factory()->adult()->create();
    $counsellor = Counsellor::factory()->create(['user_id' => User::factory()->adult()->create()->id]);
    $therapy = Therapy::factory()->create(['addedby_type' => User::class, 'addedby_id' => $client->id, 'counsellor_id' => $counsellor->id, 'public' => $public]);
    $session = Session::factory()->create(['for_id' => $therapy->id, 'for_type' => Therapy::class, 'status' => 'HELD']);
    $message = Message::factory()->create([
        'for_id' => $session->id, 'for_type' => Session::class,
        'from_type' => User::class, 'from_id' => $client->id,
        'content' => 'hello', 'confidential' => false, 'type' => 'NORMAL',
    ]);

    return compact('client', 'counsellor', 'therapy', 'session', 'message');
}

test('an admin reading a private therapy\'s messages is recorded once per fetch; its participants are not', function () {
    $t = acaTherapy();
    $admin = acaAdmin();

    $this->actingAs($admin)->getJson(route('api.session.messages.get', ['sessionId' => $t['session']->id]))->assertOk();
    $this->actingAs($admin)->getJson(route('api.messages.show', ['messageId' => $t['message']->id]))->assertOk();
    $this->actingAs($admin)->getJson(route('api.message.replies.get', ['messageId' => $t['message']->id]))->assertOk();
    $this->actingAs($t['client'])->getJson(route('api.session.messages.get', ['sessionId' => $t['session']->id]))->assertOk();

    $rows = AdminContentAccess::orderBy('id')->get();
    expect($rows->pluck('resource')->all())->toBe(['session_messages', 'message', 'message_replies'])
        ->and($rows->pluck('admin_user_id')->unique()->all())->toBe([$admin->id])
        ->and($rows->first()->subject_type)->toBe(Session::class)
        ->and($rows->first()->subject_id)->toBe($t['session']->id)
        ->and($rows->first()->page)->toBe(1);
});

test('an admin reading a public therapy, or one they take part in, is not recorded', function () {
    $public = acaTherapy(public: true);
    $admin = acaAdmin();
    $this->actingAs($admin)->getJson(route('api.session.messages.get', ['sessionId' => $public['session']->id]))->assertOk();

    $own = acaTherapy();
    Administrator::factory()->create(['user_id' => $own['client']->id]);
    $this->actingAs($own['client']->fresh())->getJson(route('api.session.messages.get', ['sessionId' => $own['session']->id]))->assertOk();

    expect(AdminContentAccess::count())->toBe(0);
});

test('an admin opening someone else\'s licence document is recorded once in ten minutes; their own is not', function () {
    Storage::fake('license_documents');
    $counsellor = Counsellor::factory()->create(['user_id' => User::factory()->create()->id]);
    $license = License::factory()->create([
        'number' => 'GHA-1',
        'licensing_authority_id' => LicensingAuthority::factory()->create(['name' => 'Board', 'type' => LicensingAuthorityTypeEnum::values()[0], 'license_type' => LicensingTypeEnum::values()[0]])->id,
        'for_type' => Counsellor::class, 'for_id' => $counsellor->id,
        'addedby_type' => Counsellor::class, 'addedby_id' => $counsellor->id,
    ]);
    Storage::disk('license_documents')->putFileAs('licenses', UploadedFile::fake()->image('id.jpg'), 'id.jpg');
    $file = File::factory()->create(['name' => 'id.jpg', 'path' => 'licenses', 'storage' => 'license_documents', 'mime' => 'image/jpeg']);
    $license->files()->attach($file->id);
    $admin = acaAdmin();
    $url = route('licenses.documents.show', ['license' => $license->id, 'file' => $file->id]);

    $this->actingAs($admin)->get($url)->assertSuccessful();
    $this->actingAs($admin)->get($url)->assertSuccessful();
    $this->actingAs($counsellor->user)->get($url)->assertSuccessful();

    expect(AdminContentAccess::count())->toBe(1)
        ->and(AdminContentAccess::first()->resource)->toBe('license_document')
        ->and(AdminContentAccess::first()->file_id)->toBe($file->id);
});

test('the read log can not be changed or removed, by the model or in the database', function () {
    $t = acaTherapy();
    $this->actingAs(acaAdmin())->getJson(route('api.session.messages.get', ['sessionId' => $t['session']->id]))->assertOk();
    $row = AdminContentAccess::first();

    expect(fn () => $row->update(['resource' => 'x']))->toThrow(LogicException::class)
        ->and(fn () => $row->delete())->toThrow(LogicException::class)
        ->and(fn () => DB::table('admin_content_accesses')->update(['resource' => 'x']))->toThrow(QueryException::class, 'append-only')
        ->and(fn () => DB::table('admin_content_accesses')->delete())->toThrow(QueryException::class, 'append-only');
})->group('mysql-triggers');

test('only super admins see the read log', function () {
    $t = acaTherapy();
    $super = acaAdmin();
    $this->actingAs($super)->getJson(route('api.session.messages.get', ['sessionId' => $t['session']->id]));

    $this->actingAs(acaAdmin('NORMAL'))->get(route('administrator.content-accesses'))->assertRedirect(route('home'));
    $this->actingAs($super)->get(route('administrator.content-accesses', ['resource' => 'session_messages']))->assertOk()
        ->assertInertia(fn ($page) => $page->component('Admin/ContentAccesses')
            ->where('accesses.data.0.resource', 'session_messages')
            ->where('accesses.data.0.admin.id', $super->id));
});

// SCRUM-385: what was read is named, not "Session #12"; a deleted subject says so.
test('the read log names what was read, and the file, instead of showing ids', function () {
    $t = acaTherapy();
    $super = acaAdmin();
    $t['therapy']->update(['name' => 'Coping with change']);
    $t['session']->update(['name' => 'First session']);
    $file = File::factory()->create(['name' => 'scan.pdf']);
    $gone = acaTherapy();
    $gone['therapy']->delete();

    $row = fn ($subject, array $extra = []) => AdminContentAccess::create(array_merge([
        'admin_user_id' => $super->id, 'resource' => 'therapy', 'subject_type' => $subject::class, 'subject_id' => $subject->id,
    ], $extra));
    $row($t['therapy']);
    $row($t['session'], ['resource' => 'session']);
    $row($t['message'], ['resource' => 'message_attachment', 'file_id' => $file->id]);
    $row($gone['therapy']);

    $this->actingAs($super)->get(route('administrator.content-accesses'))->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('accesses.data.0.subject', 'a deleted therapy')
            ->where('accesses.data.1.subject', 'A message in First session')
            ->where('accesses.data.1.fileName', 'scan.pdf')
            ->where('accesses.data.2.subject', 'Session: First session')
            ->where('accesses.data.3.subject', 'Therapy: Coping with change')
            ->where('filters.subjectLabel', null));

    $this->actingAs($super)->get(route('administrator.content-accesses', ['subject' => 'session', 'subject_id' => $t['session']->id]))->assertOk()
        ->assertInertia(fn ($page) => $page->where('filters.subjectLabel', 'Session: First session'));
});

test('an admin opening a private therapy\'s page is recorded; a public one is not', function () {
    $private = acaTherapy();
    $public = acaTherapy(public: true);
    $admin = acaAdmin();

    $this->actingAs($admin)->getJson(route('api.therapies.get', ['therapyId' => $private['therapy']->id]))->assertOk();
    $this->actingAs($admin)->getJson(route('api.therapies.get', ['therapyId' => $public['therapy']->id]))->assertOk();

    expect(AdminContentAccess::pluck('resource')->all())->toBe(['therapy'])
        ->and(AdminContentAccess::first()->subject_id)->toBe($private['therapy']->id);
});

test('an admin opening a message attachment is recorded once in ten minutes', function () {
    $t = acaTherapy();
    Storage::fake('message_attachments');
    Storage::disk('message_attachments')->put('attachments/note.pdf', 'pdf');
    $file = File::factory()->create(['name' => 'note.pdf', 'path' => 'attachments', 'storage' => 'message_attachments', 'mime' => 'application/pdf']);
    $t['message']->files()->attach($file->id);
    $admin = acaAdmin();
    $url = route('messages.attachments.show', ['message' => $t['message']->id, 'file' => $file->id]);

    $this->actingAs($admin)->get($url)->assertSuccessful();
    $this->actingAs($admin)->get($url)->assertSuccessful();

    expect(AdminContentAccess::where('resource', 'message_attachment')->count())->toBe(1)
        ->and(AdminContentAccess::first()->file_id)->toBe($file->id);
});

test('searching messages never matches text the searcher can not see', function () {
    $t = acaTherapy();
    $member = User::factory()->adult()->create();
    Message::factory()->create([
        'for_id' => $t['session']->id, 'for_type' => Session::class,
        'from_type' => User::class, 'from_id' => $t['client']->id,
        'to_type' => Counsellor::class, 'to_id' => $t['counsellor']->id,
        'content' => 'a secret only they share', 'confidential' => true, 'type' => 'NORMAL',
    ]);
    $search = fn (User $viewer) => $this->actingAs($viewer)->getJson(route('api.session.messages.get', ['sessionId' => $t['session']->id, 'like' => 'secret']))->json('data') ?? [];

    expect($search(acaAdmin()))->toBe([])
        ->and($search($t['client']))->toHaveCount(1)
        ->and($search($t['counsellor']->user))->toHaveCount(1);
});
