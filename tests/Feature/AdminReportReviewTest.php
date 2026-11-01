<?php

use App\Actions\Report\EnsureAdminCanReviewReportAction;
use App\Actions\Report\RecordReportedPartyAction;
use App\Enums\ReportStatusEnum;
use App\Http\Resources\AdminReportedMessageResource;
use App\Models\Administrator;
use App\Models\ConfidentialMessageAccess;
use App\Models\Counsellor;
use App\Models\File;
use App\Models\GroupTherapy;
use App\Models\Guardianship;
use App\Models\Message;
use App\Models\Post;
use App\Models\Report;
use App\Models\ReportStatusHistory;
use App\Models\Session;
use App\Models\Therapy;
use App\Models\User;
use App\Notifications\ReportClosedNotification;
use App\Services\ReportService;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

// SCRUM-340: admins review reports through a lifecycle, and may read a reported conversation's
// confidential messages -- only those the reported person sent or received, only while the report
// is open/under review, always with a reason, and every read recorded.

function arrAdmin(): User
{
    return User::factory()->has(Administrator::factory())->create();
}

// A group with a creator (the reporter), a counsellor, and two members. Confidential messages:
// member -> counsellor (the reported member's) and other member -> counsellor (not theirs).
function arrGroupReport(array $data = []): array
{
    $creator = User::factory()->adult()->create();
    $group = GroupTherapy::factory()->create(['addedby_type' => User::class, 'addedby_id' => $creator->id, 'anonymous' => true, 'public' => false]);
    $counsellor = Counsellor::factory()->create(['user_id' => User::factory()->create()->id]);
    $group->counsellors()->attach($counsellor->id, ['state' => 'ACTIVE', 'role' => 'NORMAL']);
    $member = User::factory()->adult()->create();
    $otherMember = User::factory()->adult()->create();
    $group->users()->attach($member->id, ['anonymous' => true]);
    $group->users()->attach($otherMember->id, ['anonymous' => true]);
    $session = Session::factory()->create(['for_id' => $group->id, 'for_type' => GroupTherapy::class, 'status' => 'IN_SESSION']);

    $message = fn (User|Counsellor $from, User|Counsellor|null $to, string $content, bool $confidential) => Message::factory()->create([
        'for_id' => $session->id, 'for_type' => Session::class,
        'from_type' => $from::class, 'from_id' => $from->id,
        'to_type' => $to ? $to::class : null, 'to_id' => $to?->id,
        'content' => $content, 'confidential' => $confidential, 'type' => 'NORMAL',
    ]);

    $public = $message($member, null, 'a message everyone saw', false);
    $reportedConfidential = $message($member, $counsellor, 'the reported member said this privately', true);
    $otherConfidential = $message($otherMember, $counsellor, 'someone else said this privately', true);

    $report = new Report(['description' => 'A member was abusive', 'data' => $data]);
    $report->addedby()->associate($creator);
    $report->reportable()->associate($group);
    $report->save();

    return compact('creator', 'group', 'counsellor', 'member', 'otherMember', 'session', 'public', 'reportedConfidential', 'otherConfidential', 'report');
}

function arrUnlock($test, User $admin, Report $report, Session $session, string $reason = 'Reviewing the abuse complaint')
{
    return $test->actingAs($admin)->postJson(route('admin.reports.conversation', ['report' => $report->id, 'session' => $session->id]), ['reason' => $reason]);
}

beforeEach(fn () => Notification::fake());

test('only admins reach the review pages and actions', function () {
    $g = arrGroupReport(['userId' => null]);
    $user = User::factory()->create();

    $this->actingAs($user)->get(route('administrator.reports'))->assertRedirect(route('home'));
    $this->actingAs($user)->get(route('administrator.reports.show', ['report' => $g['report']->id]))->assertRedirect(route('home'));
    $this->actingAs($user)->post(route('admin.reports.status', ['report' => $g['report']->id]), ['status' => 'under_review'])
        ->assertSessionHasErrors(['alert' => EnsureAdminCanReviewReportAction::NOT_FOUND]);
    arrUnlock($this, $user, $g['report'], $g['session'])->assertNotFound();

    expect($g['report']->fresh()->status)->toBe(ReportStatusEnum::open);
});

test('an admin sees the queue and a report page', function () {
    $g = arrGroupReport();
    $admin = arrAdmin();

    $this->actingAs($admin)->get(route('administrator.reports'))->assertOk()
        ->assertInertia(fn ($page) => $page->component('Admin/Reports')->where('reports.data.0.id', $g['report']->id));

    $this->actingAs($admin)->get(route('administrator.reports.show', ['report' => $g['report']->id]))->assertOk()
        ->assertInertia(fn ($page) => $page->component('Admin/ReportShow')
            ->where('report.status', 'open')
            ->where('report.unlock.available', false)
            ->has('report.participants', 3));
});

test('resolving needs an outcome and a note, records history, and tells both people without naming the admin', function () {
    $g = arrGroupReport(['userId' => null]);
    $admin = arrAdmin();
    $this->actingAs($admin)->post(route('admin.reports.party', ['report' => $g['report']->id]), ['type' => 'user', 'id' => $g['member']->id])->assertSessionHasNoErrors();

    $this->actingAs($admin)->post(route('admin.reports.status', ['report' => $g['report']->id]), ['status' => 'resolved', 'note' => 'warned'])
        ->assertSessionHasErrors('alert');
    $this->actingAs($admin)->post(route('admin.reports.status', ['report' => $g['report']->id]), ['status' => 'resolved', 'outcome' => 'warning', 'note' => 'Warned the member'])
        ->assertSessionHasNoErrors();

    $report = $g['report']->fresh();
    expect($report->status)->toBe(ReportStatusEnum::resolved)
        ->and($report->outcome->value)->toBe('warning')
        ->and($report->resolved_by)->toBe($admin->id)
        ->and($report->resolved_at)->not->toBeNull()
        ->and(ReportStatusHistory::where('report_id', $report->id)->orderBy('id')->get(['event', 'to_status'])->map(fn ($h) => $h->event.':'.$h->to_status)->all())
        ->toBe(['party_recorded:', 'status_change:under_review', 'status_change:resolved']);

    Notification::assertSentTo($g['creator'], ReportClosedNotification::class);
    Notification::assertSentTo($g['member'], ReportClosedNotification::class, function ($n, $channels, $notifiable) use ($admin) {
        $mail = implode(' ', $n->toMail($notifiable)->introLines);

        return ! str_contains($mail, $admin->name) && ! str_contains($mail, 'confidential');
    });
});

test('a closed report can only be reopened, and dismissing needs a note', function () {
    $g = arrGroupReport();
    $admin = arrAdmin();
    $post = fn (array $body) => $this->actingAs($admin)->post(route('admin.reports.status', ['report' => $g['report']->id]), $body);

    $post(['status' => 'dismissed'])->assertSessionHasErrors('alert');
    $post(['status' => 'dismissed', 'note' => 'Not a policy breach'])->assertSessionHasNoErrors();
    $post(['status' => 'resolved', 'outcome' => 'warning', 'note' => 'x'])->assertSessionHasErrors('alert');
    $post(['status' => 'under_review'])->assertSessionHasNoErrors();

    $report = $g['report']->fresh();
    expect($report->status)->toBe(ReportStatusEnum::under_review)
        ->and($report->resolved_at)->toBeNull()
        ->and($report->resolution_note)->toBeNull();
});

test('an admin who is part of the report cannot review it', function () {
    $g = arrGroupReport(['counsellorId' => null]);
    Administrator::factory()->create(['user_id' => $g['counsellor']->user->id]);

    $involved = $g['counsellor']->user;

    $this->actingAs($involved)->post(route('admin.reports.status', ['report' => $g['report']->id]), ['status' => 'under_review'])
        ->assertSessionHasErrors(['alert' => EnsureAdminCanReviewReportAction::INVOLVED]);
    arrUnlock($this, $involved, $g['report'], $g['session'])->assertForbidden();

    // Not even the page, the evidence, or the details in the queue: they name the reporter.
    $this->actingAs($involved)->get(route('administrator.reports.show', ['report' => $g['report']->id]))
        ->assertRedirect(route('administrator.reports'));
    expect($g['report']->fresh()->isViewableBy($involved))->toBeFalse();
    $this->actingAs($involved)->get(route('administrator.reports'))->assertOk()
        ->assertInertia(fn ($page) => $page->where('reports.data.0.involved', true)->missing('reports.data.0.reporter')->missing('reports.data.0.description'));
});

test('an admin who wrote a reported post cannot review the report about it', function () {
    $author = arrAdmin();
    $post = new Post(['content' => 'my post']);
    $post->addedby()->associate($author);
    $post->save();
    $report = new Report(['description' => 'offensive post']);
    $report->addedby()->associate(User::factory()->create());
    $report->reportable()->associate($post);
    $report->save();

    $this->actingAs($author)->post(route('admin.reports.status', ['report' => $report->id]), ['status' => 'dismissed', 'note' => 'nothing wrong'])
        ->assertSessionHasErrors(['alert' => EnsureAdminCanReviewReportAction::INVOLVED]);
    expect($report->fresh()->status)->toBe(ReportStatusEnum::open);
});

test('recording the reported person starts the review, so the reporter can no longer change it', function () {
    $g = arrGroupReport();
    $this->actingAs(arrAdmin())->post(route('admin.reports.party', ['report' => $g['report']->id]), ['type' => 'user', 'id' => $g['member']->id]);

    expect($g['report']->fresh()->status)->toBe(ReportStatusEnum::under_review);
    $this->actingAs($g['creator'])->postJson(route('api.reports.update', ['reportId' => $g['report']->id]), ['description' => 'changed', 'reportableType' => 'grouptherapy', 'reportableId' => $g['group']->id])
        ->assertStatus(422);
    $this->actingAs($g['creator'])->deleteJson(route('api.reports.delete', ['reportId' => $g['report']->id]))->assertStatus(422);
    expect($g['report']->fresh()->data)->toBe(['userId' => $g['member']->id]);
});

test('a report that also names its own reporter (made before SCRUM-347) is about the other person only', function () {
    $client = User::factory()->create();
    $counsellor = Counsellor::factory()->create(['user_id' => User::factory()->create()->id]);
    $therapy = Therapy::factory()->create(['addedby_type' => User::class, 'addedby_id' => $client->id, 'counsellor_id' => $counsellor->id]);
    $session = Session::factory()->create(['for_type' => Therapy::class, 'for_id' => $therapy->id]);
    $own = Message::factory()->create(['for_id' => $session->id, 'for_type' => Session::class, 'from_type' => User::class, 'from_id' => $client->id, 'to_type' => Counsellor::class, 'to_id' => $counsellor->id, 'confidential' => true, 'type' => 'NORMAL', 'content' => 'from the reporter']);
    $report = new Report(['description' => 'old style', 'data' => ['userId' => $client->id, 'counsellorId' => $counsellor->id]]);
    $report->addedby()->associate($client);
    $report->reportable()->associate($therapy);
    $report->save();
    $admin = arrAdmin();

    expect($report->reportedParty()->is($counsellor))->toBeTrue();

    $message = collect(arrUnlock($this, $admin, $report, $session)->assertOk()->json('messages'))->firstWhere('id', $own->id);
    expect($message['sender'])->toBe('Reporter')->and($message['recipient'])->toBe('Reported person');

    $this->actingAs($admin)->post(route('admin.reports.status', ['report' => $report->id]), ['status' => 'dismissed', 'note' => 'No breach found']);
    Notification::assertSentTo($counsellor->user, ReportClosedNotification::class);
    Notification::assertSentToTimes($client, ReportClosedNotification::class, 1);
});

test('a non-confidential message deleted for everyone stays hidden unless the reported person sent or received it', function () {
    $g = arrGroupReport(['userId' => null]);
    $admin = arrAdmin();
    $this->actingAs($admin)->post(route('admin.reports.party', ['report' => $g['report']->id]), ['type' => 'user', 'id' => $g['member']->id]);
    $others = Message::factory()->create(['for_id' => $g['session']->id, 'for_type' => Session::class, 'from_type' => User::class, 'from_id' => $g['otherMember']->id, 'confidential' => false, 'type' => 'NORMAL', 'content' => 'retracted by someone else']);
    $others->delete();
    $g['public']->delete();

    $messages = collect(arrUnlock($this, $admin, $g['report'], $g['session'])->json('messages'))->keyBy('id');

    expect($messages[$others->id]['hidden'])->toBeTrue()
        ->and($messages[$others->id]['content'])->toBeNull()
        ->and($messages[$g['public']->id]['content'])->toBe('a message everyone saw')
        ->and(ConfidentialMessageAccess::whereNotNull('message_id')->pluck('message_id')->all())->toContain($g['public']->id);
});

test('the admin message presenter carries exactly its documented keys and no ids', function () {
    $g = arrGroupReport(['userId' => null]);
    $admin = arrAdmin();
    $this->actingAs($admin)->post(route('admin.reports.party', ['report' => $g['report']->id]), ['type' => 'user', 'id' => $g['member']->id]);

    foreach (arrUnlock($this, $admin, $g['report'], $g['session'])->json('messages') as $message) {
        expect(array_keys($message))->toEqualCanonicalizing(AdminReportedMessageResource::KEYS);
    }
});

test('recording the reported person only accepts a participant who is not the reporter, while the report is active', function () {
    $g = arrGroupReport();
    $admin = arrAdmin();
    $record = fn (string $type, int $id) => $this->actingAs($admin)->post(route('admin.reports.party', ['report' => $g['report']->id]), ['type' => $type, 'id' => $id]);

    $record('user', User::factory()->create()->id)->assertSessionHasErrors('alert');
    $record('user', $g['creator']->id)->assertSessionHasErrors('alert');
    $record('counsellor', $g['counsellor']->id)->assertSessionHasNoErrors();
    expect($g['report']->fresh()->data)->toBe(['counsellorId' => $g['counsellor']->id]);

    $this->actingAs($admin)->post(route('admin.reports.status', ['report' => $g['report']->id]), ['status' => 'dismissed', 'note' => 'No breach found']);
    $record('user', $g['member']->id)->assertSessionHasErrors('alert');
});

test('unlocking shows only the reported person\'s confidential messages, records each read, and starts the review', function () {
    $g = arrGroupReport(['userId' => null]);
    $admin = arrAdmin();
    $this->actingAs($admin)->post(route('admin.reports.party', ['report' => $g['report']->id]), ['type' => 'user', 'id' => $g['member']->id]);

    $response = arrUnlock($this, $admin, $g['report'], $g['session'])->assertOk();

    expect($response->headers->get('Cache-Control'))->toContain('no-store');
    $messages = collect($response->json('messages'))->keyBy('id');
    expect($messages[$g['reportedConfidential']->id]['content'])->toBe('the reported member said this privately')
        ->and($messages[$g['reportedConfidential']->id]['sender'])->toBe('Reported person')
        ->and($messages[$g['otherConfidential']->id]['hidden'])->toBeTrue()
        ->and($messages[$g['otherConfidential']->id]['content'])->toBeNull()
        ->and($messages[$g['public']->id]['content'])->toBe('a message everyone saw')
        ->and($response->getContent())->not->toContain((string) $g['member']->email);

    expect(ConfidentialMessageAccess::where('report_id', $g['report']->id)->whereNull('message_id')->count())->toBe(1)
        ->and(ConfidentialMessageAccess::where('report_id', $g['report']->id)->pluck('message_id')->filter()->values()->all())->toBe([$g['reportedConfidential']->id])
        ->and(ConfidentialMessageAccess::first()->reason)->toBe('Reviewing the abuse complaint')
        ->and($g['report']->fresh()->status)->toBe(ReportStatusEnum::under_review);
});

test('a message deleted for everyone is shown to the reviewing admin, labelled', function () {
    $g = arrGroupReport(['userId' => null]);
    $admin = arrAdmin();
    $this->actingAs($admin)->post(route('admin.reports.party', ['report' => $g['report']->id]), ['type' => 'user', 'id' => $g['member']->id]);
    $g['reportedConfidential']->delete();

    $message = collect(arrUnlock($this, $admin, $g['report'], $g['session'])->json('messages'))->firstWhere('id', $g['reportedConfidential']->id);

    expect($message['deletedForEveryone'])->toBeTrue()
        ->and($message['content'])->toBe('the reported member said this privately');
});

test('nothing unlocks without a reported person, after closing, outside the reported therapy, without a reason, or for a post', function () {
    $g = arrGroupReport(['userId' => null]);
    $admin = arrAdmin();

    arrUnlock($this, $admin, $g['report'], $g['session'])->assertNotFound()->assertJsonPath('error', ReportService::CONVERSATION_NOT_AVAILABLE);

    $this->actingAs($admin)->post(route('admin.reports.party', ['report' => $g['report']->id]), ['type' => 'user', 'id' => $g['member']->id]);
    arrUnlock($this, $admin, $g['report'], $g['session'], 'short')->assertStatus(422);
    arrUnlock($this, $admin, $g['report'], Session::factory()->create())->assertNotFound()->assertJsonPath('error', ReportService::CONVERSATION_NOT_AVAILABLE);

    $this->actingAs($admin)->post(route('admin.reports.status', ['report' => $g['report']->id]), ['status' => 'dismissed', 'note' => 'No breach found']);
    arrUnlock($this, $admin, $g['report'], $g['session'])->assertNotFound();

    $poster = User::factory()->create();
    $postReport = new Report(['description' => 'spam', 'data' => ['userId' => $poster->id]]);
    $postReport->addedby()->associate(User::factory()->create());
    $post = new Post(['content' => 'buy now']);
    $post->addedby()->associate($poster);
    $post->save();
    $postReport->reportable()->associate($post);
    $postReport->save();
    arrUnlock($this, $admin, $postReport, $g['session'])->assertNotFound();

    expect(ConfidentialMessageAccess::count())->toBe(0);
});

test('an individual therapy session report unlocks that session only', function () {
    $client = User::factory()->create();
    $counsellor = Counsellor::factory()->create(['user_id' => User::factory()->create()->id]);
    $therapy = Therapy::factory()->create(['addedby_type' => User::class, 'addedby_id' => $client->id, 'counsellor_id' => $counsellor->id]);
    [$reported, $other] = Session::factory()->count(2)->create(['for_type' => Therapy::class, 'for_id' => $therapy->id]);
    $report = new Report(['description' => 'inappropriate', 'data' => ['counsellorId' => $counsellor->id]]);
    $report->addedby()->associate($client);
    $report->reportable()->associate($reported);
    $report->save();
    $admin = arrAdmin();

    arrUnlock($this, $admin, $report, $reported)->assertOk();
    arrUnlock($this, $admin, $report, $other)->assertNotFound();
});

test('attachments open only for messages the admin unlocked, and each read is recorded', function () {
    Storage::fake('message_attachments');
    $g = arrGroupReport(['userId' => null]);
    $admin = arrAdmin();
    $this->actingAs($admin)->post(route('admin.reports.party', ['report' => $g['report']->id]), ['type' => 'user', 'id' => $g['member']->id]);

    $attach = function (Message $message, string $name) {
        Storage::disk('message_attachments')->putFileAs('attachments', UploadedFile::fake()->image($name), $name);
        $file = File::factory()->create(['name' => $name, 'path' => 'attachments', 'storage' => 'message_attachments', 'mime' => 'image/jpeg']);
        $message->files()->attach($file->id);

        return $file;
    };
    $mine = $attach($g['reportedConfidential'], 'mine.jpg');
    $theirs = $attach($g['otherConfidential'], 'theirs.jpg');
    $url = fn (Message $m, File $f) => route('admin.reports.attachments.show', ['report' => $g['report']->id, 'message' => $m->id, 'file' => $f->id]);

    $this->actingAs($admin)->get($url($g['reportedConfidential'], $mine))->assertNotFound();

    arrUnlock($this, $admin, $g['report'], $g['session'])->assertOk();

    $this->actingAs($admin)->get($url($g['reportedConfidential'], $mine))->assertOk();
    $this->actingAs($admin)->get($url($g['otherConfidential'], $theirs))->assertNotFound();
    $this->actingAs($admin)->get($url($g['reportedConfidential'], $theirs))->assertNotFound();
    $this->actingAs(arrAdmin())->get($url($g['reportedConfidential'], $mine))->assertNotFound();

    $plain = $attach($g['public'], 'plain.jpg');
    $this->actingAs($admin)->get($url($g['public'], $plain))->assertOk();

    expect(ConfidentialMessageAccess::whereNotNull('file_id')->pluck('file_id')->all())->toBe([$mine->id]);
});

test('closing a report whose confidential messages were read tells both people so', function () {
    $g = arrGroupReport(['userId' => null]);
    $admin = arrAdmin();
    $this->actingAs($admin)->post(route('admin.reports.party', ['report' => $g['report']->id]), ['type' => 'user', 'id' => $g['member']->id]);
    arrUnlock($this, $admin, $g['report'], $g['session'])->assertOk();

    $this->actingAs($admin)->post(route('admin.reports.status', ['report' => $g['report']->id]), ['status' => 'dismissed', 'note' => 'No breach found']);

    foreach ([$g['creator'], $g['member']] as $person) {
        Notification::assertSentTo($person, ReportClosedNotification::class, fn ($n, $channels, $notifiable) => str_contains(implode(' ', $n->toMail($notifiable)->introLines), 'read confidential messages'));
    }
});

test('the reporter can edit an open report but not one under review', function () {
    $client = User::factory()->create();
    $counsellor = Counsellor::factory()->create(['user_id' => User::factory()->create()->id]);
    $therapy = Therapy::factory()->create(['addedby_type' => User::class, 'addedby_id' => $client->id, 'counsellor_id' => $counsellor->id, 'anonymous' => false]);
    $report = new Report(['description' => 'first', 'data' => ['counsellorId' => $counsellor->id]]);
    $report->addedby()->associate($client);
    $report->reportable()->associate($therapy);
    $report->save();

    $this->actingAs($client)->postJson(route('api.reports.update', ['reportId' => $report->id]), ['description' => 'second'])->assertOk();

    $this->actingAs(arrAdmin())->post(route('admin.reports.status', ['report' => $report->id]), ['status' => 'under_review']);

    $this->actingAs($client)->postJson(route('api.reports.update', ['reportId' => $report->id]), ['description' => 'third'])->assertStatus(422);
    $this->actingAs($client)->deleteJson(route('api.reports.delete', ['reportId' => $report->id]))->assertStatus(422);
    expect($report->fresh()->description)->toBe('second');
});

test('access records are append-only', function () {
    $access = ConfidentialMessageAccess::create(['report_id' => 1, 'admin_user_id' => 1, 'session_id' => 1, 'reason' => 'test reason']);

    expect(fn () => $access->update(['reason' => 'changed']))->toThrow(LogicException::class)
        ->and(fn () => $access->delete())->toThrow(LogicException::class);
});

arch('the admin message presenter is only used by the review service')
    ->expect('App\Http\Resources\AdminReportedMessageResource')
    ->toOnlyBeUsedIn(['App\Services\ReportService']);

arch('the unlock scope resolver stays inside the report review code')
    ->expect('App\Actions\Report\ResolveReportUnlockScopeAction')
    ->toOnlyBeUsedIn(['App\Services\ReportService', 'App\Http\Resources\AdminReportResource']);

// SCRUM-362 ------------------------------------------------------------------------------------

function arrNormalAdmin(): User
{
    return User::factory()->has(Administrator::factory()->state(['type' => 'NORMAL']))->create();
}

test('the JSON report list shows an admin a report they are part of only as existing', function () {
    $g = arrGroupReport(['userId' => null]);
    $involved = arrAdmin();
    $g['group']->users()->attach($involved->id, ['anonymous' => false]);
    $uninvolved = arrAdmin();

    $this->actingAs($involved)->getJson(route('api.reports'))->assertOk()
        ->assertJsonPath('data.0', ['id' => $g['report']->id, 'status' => 'open', 'involved' => true, 'createdAt' => $g['report']->created_at->toJSON()])
        ->assertJsonMissingPath('data.0.description');
    $this->actingAs($uninvolved)->getJson(route('api.reports'))->assertOk()
        ->assertJsonPath('data.0.description', 'A member was abusive');
});

test('a guardian of the reporter, the reported person or the therapy client is part of the report', function () {
    $g = arrGroupReport(['userId' => null]);
    $g['report']->forceFill(['data' => ['userId' => $g['member']->id]])->save();
    $report = fn () => Report::find($g['report']->id);

    foreach ([$g['creator'], $g['member']] as $ward) {
        $guardian = arrAdmin();
        Guardianship::create(['guardian_id' => $guardian->id, 'ward_id' => $ward->id]);

        expect($report()->involves($guardian))->toBeTrue()
            ->and($report()->isViewableBy($guardian))->toBeFalse();
        $this->actingAs($guardian)->get(route('administrator.reports.show', ['report' => $g['report']->id]))->assertRedirect(route('administrator.reports'));
    }

    $unrelatedGuardian = arrAdmin();
    Guardianship::create(['guardian_id' => $unrelatedGuardian->id, 'ward_id' => User::factory()->create()->id]);
    expect($report()->involves($unrelatedGuardian))->toBeFalse();
});

test('a group member is still part of the report after the group moves on (membership rows are never removed)', function () {
    $g = arrGroupReport(['userId' => null]);
    $admin = arrAdmin();
    $g['group']->users()->attach($admin->id, ['anonymous' => false]);
    $g['group']->users()->updateExistingPivot($admin->id, ['anonymous' => true]);

    expect(Report::find($g['report']->id)->involves($admin))->toBeTrue();
});

test('after a confidential read only a super admin, with a reason, can change who the report is about', function () {
    $g = arrGroupReport(['userId' => null]);
    $super = arrAdmin();
    $normal = arrNormalAdmin();
    $party = route('admin.reports.party', ['report' => $g['report']->id]);

    $this->actingAs($normal)->post($party, ['type' => 'user', 'id' => $g['member']->id])->assertSessionHasNoErrors();
    arrUnlock($this, $normal, $g['report'], $g['session'])->assertOk();

    $this->actingAs($normal)->post($party, ['type' => 'user', 'id' => $g['otherMember']->id])
        ->assertSessionHasErrors(['alert' => RecordReportedPartyAction::LOCKED_AFTER_READ]);
    $this->actingAs($super)->post($party, ['type' => 'user', 'id' => $g['otherMember']->id])->assertSessionHasErrors('alert');
    expect(Report::find($g['report']->id)->reportedUser()->is($g['member']))->toBeTrue();

    // Re-recording the same person is not a change.
    $this->actingAs($normal)->post($party, ['type' => 'user', 'id' => $g['member']->id])->assertSessionHasNoErrors();

    $this->actingAs($super)->post($party, ['type' => 'user', 'id' => $g['otherMember']->id, 'reason' => 'The complaint was about the other member'])
        ->assertSessionHasNoErrors();
    expect(Report::find($g['report']->id)->reportedUser()->is($g['otherMember']))->toBeTrue()
        ->and(ReportStatusHistory::where('report_id', $g['report']->id)->where('event', 'party_recorded')->latest('id')->first()->note)
        ->toBe('The complaint was about the other member');

    $this->actingAs($normal)->get(route('administrator.reports.show', ['report' => $g['report']->id]))
        ->assertInertia(fn ($page) => $page->where('report.partyLocked', true)->where('report.canChangeLockedParty', false));
});

test('the database itself refuses to change or remove an access record', function () {
    $g = arrGroupReport(['userId' => null]);
    ConfidentialMessageAccess::record($g['report'], arrAdmin(), $g['session']->id, 'Reviewing the abuse complaint');

    expect(fn () => DB::table('confidential_message_accesses')->update(['reason' => 'rewritten']))
        ->toThrow(QueryException::class, 'append-only')
        ->and(fn () => DB::table('confidential_message_accesses')->delete())
        ->toThrow(QueryException::class, 'append-only')
        ->and(ConfidentialMessageAccess::query()->first()->reason)->toBe('Reviewing the abuse complaint');
});
