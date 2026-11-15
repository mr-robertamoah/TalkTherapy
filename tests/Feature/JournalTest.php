<?php

use App\Actions\User\AnonymiseClosedAccountAction;
use App\Enums\AdministratorTypeEnum;
use App\Http\Controllers\JournalExportController;
use App\Models\AdminContentAccess;
use App\Models\Administrator;
use App\Models\Counsellor;
use App\Models\Guardianship;
use App\Models\JournalEntry;
use App\Models\Therapy;
use App\Models\User;
use App\Notifications\JournalExportedNotification;
use App\Support\TwoFactorCodes;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use PragmaRX\Google2FA\Google2FA;

// SCRUM-36 (TT-5.1): a private journal -- only its writer can ever read it.

function jnlUser(array $attributes = []): User
{
    return User::factory()->adult()->create($attributes);
}

function jnlEntry(User $user, array $attributes = []): JournalEntry
{
    return JournalEntry::factory()->create(['user_id' => $user->id, ...$attributes]);
}

test('a guest is sent to sign in', function () {
    $this->get(route('profile.journal'))->assertRedirect(route('login'));
    $this->postJson(route('profile.journal.store'), [])->assertUnauthorized();
});

test('the owner writes, sees, edits and deletes their entries, newest first', function () {
    $me = jnlUser();

    $id = $this->actingAs($me)->postJson(route('profile.journal.store'), ['title' => '  First ', 'content' => 'jnl today was hard', 'written_on' => now()->subDay()->toDateString()])
        ->assertCreated()->assertJsonPath('entry.title', 'First')->json('entry.id');
    $this->postJson(route('profile.journal.store'), ['content' => 'jnl a better day', 'written_on' => now()->toDateString()])->assertCreated()->assertJsonPath('entry.title', null);

    $this->get(route('profile.journal'))->assertInertia(fn ($page) => $page->component('Profile/Journal')->missing('entries'));
    $this->getJson(route('profile.journal.entries'))
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertJsonPath('data.0.content', 'jnl a better day')
        ->assertJsonPath('data.1.content', 'jnl today was hard');

    $this->patchJson(route('profile.journal.update', $id), ['content' => 'jnl edited', 'written_on' => now()->subDay()->toDateString()])->assertOk();
    expect(JournalEntry::find($id)->content)->toBe('jnl edited');

    $this->deleteJson(route('profile.journal.destroy', $id))->assertNoContent();
    expect(JournalEntry::find($id))->toBeNull();
});

test('entries are encrypted at rest', function () {
    $entry = jnlEntry(jnlUser(), ['title' => 'jnl secret title', 'content' => 'jnl secret words']);

    $row = DB::table('journal_entries')->where('id', $entry->id)->first();

    expect($row->content)->not->toContain('jnl secret words')->and($row->title)->not->toContain('jnl secret title')
        ->and($entry->fresh()->content)->toBe('jnl secret words');
});

test('the rules: text required and capped, a date not in the future', function () {
    $me = jnlUser();
    config(['limits.journal_entry_max_length' => 20]);

    $this->actingAs($me)->postJson(route('profile.journal.store'), ['content' => '', 'written_on' => now()->toDateString()])->assertJsonValidationErrors('content');
    $this->postJson(route('profile.journal.store'), ['content' => str_repeat('a', 21), 'written_on' => now()->toDateString()])->assertJsonValidationErrors('content');
    // Tomorrow is allowed (ahead of UTC it is already "today"); the day after isn't, nor a date before 1900.
    $this->postJson(route('profile.journal.store'), ['content' => 'ok', 'written_on' => now()->addDay()->toDateString()])->assertCreated();
    $this->postJson(route('profile.journal.store'), ['content' => 'ok', 'written_on' => now()->addDays(2)->toDateString()])->assertJsonValidationErrors(['written_on' => 'The date can\'t be in the future.']);
    $this->postJson(route('profile.journal.store'), ['content' => 'ok', 'written_on' => '0001-01-01'])->assertJsonValidationErrors('written_on');
    config(['limits.journal_title_max_length' => 5]);
    $this->postJson(route('profile.journal.store'), ['title' => 'too long', 'content' => 'ok', 'written_on' => now()->toDateString()])->assertJsonValidationErrors('title');
    $this->postJson(route('profile.journal.store'), ['content' => 'ok', 'written_on' => 'yesterday'])->assertJsonValidationErrors('written_on');
});

test('no one else can read, change or delete an entry -- another user, a guardian, the counsellor, an administrator', function () {
    $writer = User::factory()->create(['dob' => now()->subYears(15)]);
    $entry = jnlEntry($writer, ['content' => 'jnl-PRIVATE words']);
    $guardian = jnlUser();
    Guardianship::query()->create(['guardian_id' => $guardian->id, 'ward_id' => $writer->id]);
    $counsellor = Counsellor::factory()->create(['user_id' => jnlUser()->id]);
    $therapy = Therapy::factory()->make(['anonymous' => false, 'public' => false]);
    $therapy->addedby()->associate($writer);
    $therapy->counsellor_id = $counsellor->id;
    $therapy->save();
    $super = User::factory()->adult()->has(Administrator::factory()->state(['type' => AdministratorTypeEnum::super->value]))->create();

    foreach ([jnlUser(), $guardian, $counsellor->user, $super] as $other) {
        $this->actingAs($other)->patchJson(route('profile.journal.update', $entry->id), ['content' => 'x', 'written_on' => now()->toDateString()])->assertNotFound();
        $this->actingAs($other)->deleteJson(route('profile.journal.destroy', $entry->id))->assertNotFound();
        $this->actingAs($other)->get(route('profile.journal'))->assertDontSee('jnl-PRIVATE');
        $this->actingAs($other)->getJson(route('profile.journal.entries'))->assertJsonPath('data', []);
    }

    // Never something the team can open in the app (TT-4.16).
    expect(collect(AdminContentAccess::RESOURCES)->filter(fn ($r) => str_contains($r, 'journal')))->toBeEmpty()
        ->and($entry->fresh()->content)->toBe('jnl-PRIVATE words');
});

test('writing about self-harm raises nothing and logs nothing', function () {
    Notification::fake();
    Log::spy();
    $me = jnlUser();

    $this->actingAs($me)->postJson(route('profile.journal.store'), ['content' => 'jnl I want to hurt myself', 'written_on' => now()->toDateString()])->assertCreated();

    Notification::assertNothingSent();
    Log::shouldNotHaveReceived('info');
    Log::shouldNotHaveReceived('warning');
    expect(DB::table('safety_flags')->count())->toBe(0);
});

test('show more pages through the entries', function () {
    $me = jnlUser();
    foreach (range(1, 17) as $day) {
        jnlEntry($me, ['written_on' => now()->subDays($day)->toDateString()]);
    }

    $first = $this->actingAs($me)->getJson(route('profile.journal.entries'))->assertOk();
    expect($first->json('data'))->toHaveCount(15)->and($first->json('nextCursor'))->not->toBeNull();

    $second = $this->getJson(route('profile.journal.entries', ['cursor' => $first->json('nextCursor')]));
    expect($second->json('data'))->toHaveCount(2)->and($second->json('nextCursor'))->toBeNull();
});

test('delete all removes every entry of theirs and no one else\'s', function () {
    $me = jnlUser();
    jnlEntry($me);
    jnlEntry($me);
    $theirs = jnlEntry(jnlUser());

    $this->actingAs($me)->deleteJson(route('profile.journal.destroy-all'))->assertNoContent();

    expect($me->journalEntries()->count())->toBe(0)->and($theirs->fresh())->not->toBeNull();
});

test('closing the account deletes the journal at once, and anonymising does too', function () {
    $me = jnlUser();
    jnlEntry($me);

    $this->actingAs($me)->delete(route('profile.destroy'), ['password' => 'password'])->assertSessionHasNoErrors();
    expect(JournalEntry::query()->where('user_id', $me->id)->count())->toBe(0);

    $other = jnlUser();
    jnlEntry($other);
    $other->delete();
    JournalEntry::factory()->create(['user_id' => $other->id]);
    AnonymiseClosedAccountAction::new()->execute($other->fresh());
    expect(JournalEntry::query()->where('user_id', $other->id)->count())->toBe(0);
});

// SCRUM-38 (TT-5.3): the journal as a PDF.
function jnlExport($test, User $user, array $body = [])
{
    return $test->actingAs($user)->post(route('profile.journal.export'), ['current_password' => 'password', ...$body]);
}

test('the owner exports their journal as a PDF, and is told', function () {
    Notification::fake();
    Log::spy();
    $me = jnlUser();
    jnlEntry($me, ['title' => 'jnl first', 'content' => 'jnl words', 'written_on' => now()->subDays(3)->toDateString()]);
    jnlEntry(jnlUser(), ['content' => 'jnl-SOMEONE-ELSE']);

    $response = jnlExport($this, $me)->assertOk()->assertDownload('talktherapy-journal-'.now()->toDateString().'.pdf');

    expect($response->headers->get('Content-Type'))->toBe('application/pdf')
        ->and($response->headers->get('Cache-Control'))->toContain('no-store')
        ->and(substr($response->getContent(), 0, 5))->toBe('%PDF-');
    Notification::assertSentTo($me, JournalExportedNotification::class);
    Log::shouldHaveReceived('notice')->with('Journal exported', ['user_id' => $me->id, 'entries' => 1]);
});

test('the PDF shows an entry\'s text as written and fetches nothing', function () {
    Notification::fake();
    $me = jnlUser();
    jnlEntry($me, ['content' => '<img src="http://203.0.113.5/x.png"><script>alert(1)</script> jnl-literal']);
    Http::fake();

    $html = view('pdf.journal', [
        'name' => 'Me', 'exportedOn' => 'today', 'from' => null, 'to' => null,
        'entries' => collect([['title' => '<b>t</b>', 'content' => $me->journalEntries()->first()->content, 'writtenOn' => 'x']]),
    ])->render();

    expect($html)->toContain('&lt;img src=&quot;http://203.0.113.5/x.png&quot;&gt;')->not->toContain('<img')->not->toContain('<script>')
        ->and($html)->toContain('&lt;b&gt;t&lt;/b&gt;');
    jnlExport($this, $me)->assertOk();
    Http::assertNothingSent();
    expect(config('dompdf.options.enable_remote'))->toBeFalse()
        ->and(config('dompdf.options.enable_php'))->toBeFalse()
        ->and(config('dompdf.options.enable_javascript'))->toBeFalse()
        ->and(array_keys(config('dompdf.options.allowed_protocols')))->toBe(['file://'])
        ->and(config('dompdf.options.chroot'))->toEndWith('views/pdf');
});

test('a date range exports only those entries; none in range, or none at all, gives a message, not a file', function () {
    Notification::fake();
    $me = jnlUser();

    jnlExport($this, $me)->assertSessionHasErrors('form');
    jnlEntry($me, ['written_on' => now()->subDays(10)->toDateString()]);
    RateLimiter::clear('profile.journal.export'.sha1($me->id));
    jnlExport($this, $me, ['from' => now()->subDays(5)->toDateString()])->assertSessionHasErrors('form');
    jnlExport($this, $me, ['from' => now()->subDays(11)->toDateString(), 'to' => now()->subDays(9)->toDateString()])->assertOk();
    Notification::assertSentToTimes($me, JournalExportedNotification::class, 1);
});

test('the export asks what "Download a copy" asks: wrong password, two-factor, a waiting email', function () {
    Notification::fake();
    $me = jnlUser();
    jnlEntry($me);

    jnlExport($this, $me, ['current_password' => 'wrong'])->assertSessionHasErrors('current_password');
    $me->forceFill(['pending_email' => 'jnl-new@example.org', 'pending_email_requested_at' => now()])->save();
    jnlExport($this, $me)->assertSessionHasErrors('form');
    jnlExport($this, $me)->assertSessionHasErrors('form');
    jnlExport($this, $me)->assertTooManyRequests();
    Notification::assertNothingSent();
});

test('with two-factor on, the export needs the code too -- a wrong one gets the same answer as a wrong password', function () {
    Notification::fake();
    $twoFactor = jnlUser(['two_factor_secret' => TwoFactorCodes::newSecret(), 'two_factor_confirmed_at' => now()]);
    jnlEntry($twoFactor);
    jnlExport($this, $twoFactor)->assertSessionHasErrors('code');
    jnlExport($this, $twoFactor, ['code' => '000000'])->assertSessionHasErrors('form');

    Notification::assertNothingSent();
});

test('more than the cap asks for a date range', function () {
    Notification::fake();
    $me = jnlUser();
    JournalEntry::factory()->count((int) config('limits.journal_export_max_entries') + 1)->create(['user_id' => $me->id]);

    jnlExport($this, $me)->assertSessionHasErrors('form');
    Notification::assertNothingSent();
});

test('entries on the same day page in order, with none lost or repeated', function () {
    $me = jnlUser();
    $ids = JournalEntry::factory()->count(17)->create(['user_id' => $me->id, 'written_on' => now()->toDateString()])->pluck('id')->sortDesc()->values()->all();

    $first = $this->actingAs($me)->getJson(route('profile.journal.entries'));
    $second = $this->getJson(route('profile.journal.entries', ['cursor' => $first->json('nextCursor')]));

    expect([...collect($first->json('data'))->pluck('id'), ...collect($second->json('data'))->pluck('id')])->toBe($ids);
});

test('a full journal takes no more entries', function () {
    $me = jnlUser();
    config(['limits.journal_max_entries' => 2]);
    JournalEntry::factory()->count(2)->create(['user_id' => $me->id]);

    $this->actingAs($me)->postJson(route('profile.journal.store'), ['content' => 'ok', 'written_on' => now()->toDateString()])->assertJsonValidationErrors('form');
});

test('a range takes the entries on both of its days', function () {
    Notification::fake();
    $me = jnlUser();
    jnlEntry($me, ['content' => 'jnl first day', 'written_on' => '2026-11-01']);
    jnlEntry($me, ['content' => 'jnl last day', 'written_on' => '2026-11-03']);
    jnlEntry($me, ['content' => 'jnl outside', 'written_on' => '2026-11-04']);

    jnlExport($this, $me, ['from' => '2026-11-01', 'to' => '2026-11-03'])->assertOk();
    Log::spy();
    RateLimiter::clear('profile.journal.export'.sha1($me->id));
    jnlExport($this, $me, ['from' => '2026-11-01', 'to' => '2026-11-03'])->assertOk();
    Log::shouldHaveReceived('notice')->with('Journal exported', ['user_id' => $me->id, 'entries' => 2]);
});

test('too many characters for one PDF asks for a shorter range, and tells no one', function () {
    Notification::fake();
    $me = jnlUser();
    config(['limits.journal_export_max_characters' => 50]);
    jnlEntry($me, ['title' => null, 'content' => str_repeat('a', 51)]);

    jnlExport($this, $me)->assertSessionHasErrors('form');
    Notification::assertNothingSent();
});

test('with two-factor on and the right code, the PDF downloads; a recently replaced address is told too', function () {
    Notification::fake();
    $secret = TwoFactorCodes::newSecret();
    $me = jnlUser(['email' => 'jnl-old@example.org', 'email_verified_at' => now(), 'two_factor_secret' => $secret, 'two_factor_confirmed_at' => now()]);
    $me->forceFill(['email' => 'jnl-new@example.org'])->save();
    jnlEntry($me);
    $google2fa = new Google2FA;

    jnlExport($this, $me->fresh(), ['code' => $google2fa->oathTotp($secret, $google2fa->getTimestamp())])->assertOk();

    Notification::assertSentTo($me, JournalExportedNotification::class);
    Notification::assertSentOnDemand(JournalExportedNotification::class, fn ($n, $channels, $notifiable) => $notifiable->routes['mail'] === 'jnl-old@example.org');
});

test('line breaks count heavily towards the export\'s size (they cost dompdf the most)', function () {
    Notification::fake();
    $me = jnlUser();
    jnlEntry($me, ['title' => null, 'content' => str_repeat("a\n", 800)]);

    expect(JournalExportController::weight($me->journalEntries()->first()))->toBeGreaterThan(80000);
    config(['limits.journal_export_max_characters' => 50000]);
    jnlExport($this, $me)->assertSessionHasErrors('form');
    Notification::assertNothingSent();
});
