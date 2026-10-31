<?php

use App\Actions\File\MovePublicFilesToPrivateDiskAction;
use App\Actions\Post\UpdatePostAction;
use App\Actions\Report\UpdateReportAction;
use App\DTOs\CreatePostDTO;
use App\DTOs\CreateReportDTO;
use App\Models\Administrator;
use App\Models\Counsellor;
use App\Models\File;
use App\Models\Post;
use App\Models\Report;
use App\Models\Therapy;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

// SCRUM-337: report evidence lives on a private disk, and reports and their evidence are for
// admins and the reporter only.

function aReportWithEvidenceFor337(): array
{
    Storage::fake('report_evidence');

    $reporter = User::factory()->create();
    $reported = User::factory()->create();
    $report = Report::create(['description' => 'They sent me abusive messages', 'data' => ['userId' => $reported->id]]);
    $report->addedby()->associate($reporter);
    $report->reportable()->associate($reported);
    $report->save();

    Storage::disk('report_evidence')->putFileAs('reports', UploadedFile::fake()->image('screenshot.png'), 'screenshot.png');
    $file = File::factory()->create(['name' => 'screenshot.png', 'path' => 'reports', 'storage' => 'report_evidence', 'mime' => 'text/html']);
    $report->files()->attach($file->id);

    return [$report->fresh(), $file, $reporter, $reported];
}

function evidenceUrlFor337(Report $report, File $file): string
{
    return route('reports.evidence.show', ['report' => $report->id, 'file' => $file->id]);
}

test('the reporter and admins can view evidence, served by its real type', function () {
    [$report, $file, $reporter] = aReportWithEvidenceFor337();
    $admin = User::factory()->has(Administrator::factory())->create();

    foreach ([$reporter, $admin] as $viewer) {
        $this->actingAs($viewer)->get(evidenceUrlFor337($report, $file))
            ->assertSuccessful()
            ->assertHeader('Content-Type', 'image/png')
            ->assertHeader('X-Content-Type-Options', 'nosniff');
    }
});

test('the reported user, an outsider, a wrong report and a missing id all get the same 404', function () {
    [$report, $file, , $reported] = aReportWithEvidenceFor337();
    $otherReport = Report::create(['description' => 'Other']);
    $otherReport->addedby()->associate($reported)->save();

    $urls = [
        [$reported, evidenceUrlFor337($report, $file)],
        [User::factory()->create(), evidenceUrlFor337($report, $file)],
        [$reported, evidenceUrlFor337($otherReport, $file)],
        [$reported, route('reports.evidence.show', ['report' => 999999, 'file' => $file->id])],
    ];

    foreach ($urls as [$viewer, $url]) {
        $this->actingAs($viewer)->getJson($url)->assertStatus(404)->assertJsonPath('error', 'Evidence not found.');
    }
});

test('evidence on a public disk is never streamed, and never gets a public url', function () {
    [$report, $file, $reporter] = aReportWithEvidenceFor337();
    $file->update(['storage' => 'public']);

    $this->actingAs($reporter)->getJson(evidenceUrlFor337($report, $file))->assertStatus(404);
});

test('evidence on the private disk never gets a public url', function () {
    [, $file] = aReportWithEvidenceFor337();

    expect(fn () => $file->url)->toThrow(RuntimeException::class);
});

test('new evidence is uploaded to the private disk and exposed only through the authorized route', function () {
    Storage::fake('report_evidence');
    Storage::fake('public');
    $reporter = User::factory()->create();
    $counsellor = Counsellor::factory()->create(['user_id' => User::factory()->create()->id]);
    $therapy = Therapy::factory()->create(['addedby_type' => User::class, 'addedby_id' => $reporter->id, 'counsellor_id' => $counsellor->id]);

    $response = $this->actingAs($reporter)->postJson(route('api.reports.create'), [
        'description' => 'Harassment',
        'reportableType' => 'therapy',
        'reportableId' => $therapy->id,
        'addedbyType' => 'user',
        'addedbyId' => $reporter->id,
        'data' => ['counsellorId' => $counsellor->id],
        'files' => [UploadedFile::fake()->image('proof.png')],
    ])->assertOk();

    $file = Report::find($response->json('report.id'))->files()->first();
    expect($file->storage)->toBe('report_evidence');
    Storage::disk('report_evidence')->assertExists($file->diskPath());
    expect(Storage::disk('public')->allFiles())->toBeEmpty();
    expect($response->json('report.files.0.url'))->toBe(evidenceUrlFor337(Report::find($response->json('report.id')), $file));
});

test('reports are readable only by admins and the reporter, and listing shows only your own', function () {
    [$report, , $reporter, $reported] = aReportWithEvidenceFor337();
    $admin = User::factory()->has(Administrator::factory())->create();

    $this->actingAs($reporter)->getJson(route('api.reports.get', ['reportId' => $report->id]))->assertOk();
    $this->actingAs($admin)->getJson(route('api.reports.get', ['reportId' => $report->id]))->assertOk();
    $refused = $this->actingAs($reported)->getJson(route('api.reports.get', ['reportId' => $report->id]))->assertStatus(404);
    $missing = $this->actingAs($reported)->getJson(route('api.reports.get', ['reportId' => 999999]))->assertStatus(404);
    expect($refused->json('message'))->toBe($missing->json('message'))
        ->and($refused->getContent())->not->toContain('abusive');

    // Asking for the reporter's reports by filter still returns nothing to someone else.
    $listed = $this->actingAs($reported)->getJson(route('api.reports', ['addedbyType' => 'user', 'addedbyId' => $reporter->id]))->assertOk();
    expect($listed->json('data'))->toBeEmpty();
    expect($this->actingAs($reporter)->getJson(route('api.reports'))->json('data.0.id'))->toBe($report->id);
    expect($this->actingAs($admin)->getJson(route('api.reports'))->json('data'))->toHaveCount(1);
});

test('a reporting counsellor\'s user is the reporter', function () {
    $counsellor = Counsellor::factory()->create(['user_id' => User::factory()->create()->id]);
    $report = Report::create(['description' => 'x']);
    $report->addedby()->associate($counsellor)->save();

    expect($report->fresh()->isViewableBy($counsellor->user))->toBeTrue()
        ->and($report->fresh()->isViewableBy(User::factory()->create()))->toBeFalse();
});

test('updating a report can only delete that report\'s own evidence', function () {
    [$report, , $reporter] = aReportWithEvidenceFor337();
    Storage::fake('public');
    Storage::disk('public')->put('others/post.png', 'post image');
    $someoneElses = File::factory()->create(['name' => 'post.png', 'path' => 'others', 'storage' => 'public']);

    // The HTTP route doesn't pass deletedFiles today; the action is where the guard must live.
    UpdateReportAction::new()->execute(CreateReportDTO::new()->fromArray([
        'user' => $reporter, 'report' => $report, 'deletedFiles' => [$someoneElses->id],
    ]));

    expect(File::find($someoneElses->id))->not->toBeNull();
    Storage::disk('public')->assertExists('others/post.png');
});

test('the migration move takes only report evidence out of others/, leaving post images public', function () {
    Storage::fake('public');
    Storage::fake('report_evidence');
    Storage::disk('public')->put('others/evidence.png', 'evidence');
    Storage::disk('public')->put('others/post.png', 'post image');
    $report = Report::create(['description' => 'x']);
    $evidence = File::factory()->create(['name' => 'evidence.png', 'path' => 'others', 'storage' => 'public']);
    $report->files()->attach($evidence->id);
    File::factory()->create(['name' => 'post.png', 'path' => 'others', 'storage' => 'public']);

    MovePublicFilesToPrivateDiskAction::new()->execute('reports', 'others', 'report_evidence', sweepDirectory: false);

    expect($evidence->fresh()->storage)->toBe('report_evidence');
    Storage::disk('report_evidence')->assertExists('others/evidence.png');
    Storage::disk('public')->assertMissing('others/evidence.png');
    Storage::disk('public')->assertExists('others/post.png');
});

test('reporting someone on a therapy they are not in never names the therapy', function () {
    $reporter = User::factory()->create();
    $counsellor = Counsellor::factory()->create(['user_id' => User::factory()->create()->id]);
    $therapy = Therapy::factory()->create(['addedby_type' => User::class, 'addedby_id' => User::factory()->create()->id, 'counsellor_id' => $counsellor->id, 'name' => 'Secret therapy name']);

    $response = $this->actingAs($reporter)->postJson(route('api.reports.create'), [
        'description' => 'x',
        'reportableType' => 'therapy',
        'reportableId' => $therapy->id,
        'addedbyType' => 'user',
        'addedbyId' => $reporter->id,
        'data' => ['userId' => $reporter->id, 'counsellorId' => $counsellor->id],
    ])->assertStatus(422);

    expect($response->getContent())->not->toContain('Secret therapy name');
});

test('a reporting counsellor can list, update and delete their report; anyone else gets the not-found 404', function () {
    $counsellor = Counsellor::factory()->create(['user_id' => User::factory()->create()->id]);
    $report = Report::create(['description' => 'Original']);
    $report->addedby()->associate($counsellor)->save();
    Report::create(['description' => 'Someone else\'s']);

    expect($this->actingAs($counsellor->user)->getJson(route('api.reports'))->json('data.*.id'))->toBe([$report->id]);

    $stranger = User::factory()->create();
    $this->actingAs($stranger)->postJson(route('api.reports.update', ['reportId' => $report->id]), ['description' => 'Hijacked'])
        ->assertStatus(404)->assertJsonPath('message', 'Report not found.');
    $this->actingAs($stranger)->deleteJson(route('api.reports.delete', ['reportId' => $report->id]))
        ->assertStatus(404)->assertJsonPath('message', 'Report not found.');

    $this->actingAs($counsellor->user)->postJson(route('api.reports.update', ['reportId' => $report->id]), ['description' => 'Updated'])->assertOk();
    expect($report->fresh()->description)->toBe('Updated');
    $this->actingAs($counsellor->user)->deleteJson(route('api.reports.delete', ['reportId' => $report->id]))->assertOk();
    expect(Report::find($report->id))->toBeNull();
});

test('updating a post can only delete that post\'s own files, never report evidence', function () {
    [, $evidence] = aReportWithEvidenceFor337();
    $author = User::factory()->create();
    $post = Post::query()->forceCreate(['content' => 'Hello', 'addedby_type' => User::class, 'addedby_id' => $author->id]);

    UpdatePostAction::new()->execute(CreatePostDTO::new()->fromArray([
        'user' => $author, 'post' => $post, 'deletedFiles' => [$evidence->id],
    ]));

    expect(File::find($evidence->id))->not->toBeNull();
    Storage::disk('report_evidence')->assertExists($evidence->diskPath());
});
