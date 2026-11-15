<?php

use App\Enums\MessageTypeEnum;
use App\Enums\TransactionStatusEnum;
use App\Models\AdminContentAccess;
use App\Models\Counsellor;
use App\Models\Discussion;
use App\Models\File;
use App\Models\GroupTherapy;
use App\Models\Guardianship;
use App\Models\JournalEntry;
use App\Models\Message;
use App\Models\MessageNote;
use App\Models\Organization;
use App\Models\Session;
use App\Models\SessionNote;
use App\Models\Therapy;
use App\Models\Transaction;
use App\Models\User;
use App\Notifications\DataCopyDownloadedNotification;
use App\Support\TwoFactorCodes;
use App\Support\YourDataWording;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use PragmaRX\Google2FA\Google2FA;

// SCRUM-432: download a copy of your data -- only the signed-in person's own, password (and two-factor code) first.

function ydeUser(array $attributes = []): User
{
    return User::factory()->adult()->create(['password' => 'yde-secret-pass', ...$attributes]);
}

function ydeCounsellor(array $attributes = []): Counsellor
{
    return Counsellor::factory()->create(['user_id' => ydeUser($attributes)->id]);
}

function ydeTherapy(User $client, ?Counsellor $counsellor = null, array $attributes = []): Therapy
{
    $therapy = Therapy::factory()->make(['anonymous' => false, 'public' => false, ...$attributes]);
    $therapy->addedby()->associate($client);
    $therapy->counsellor_id = $counsellor?->id;
    $therapy->forceFill(array_intersect_key($attributes, ['is_help_chat' => true]))->save();

    return $therapy;
}

function ydeSession(Therapy|GroupTherapy $for): Session
{
    return Session::factory()->create(['for_type' => $for::class, 'for_id' => $for->id, 'start_time' => now()->addDay(), 'end_time' => now()->addDay()->addHour()]);
}

function ydeMessage(User|Counsellor $from, Session|Discussion $for, string $content): Message
{
    $message = Message::factory()->make(['type' => MessageTypeEnum::normal->value, 'content' => $content]);
    $message->from()->associate($from);
    $message->for()->associate($for);
    $message->save();

    return $message;
}

function ydeDownload($test, User $user, array $body = ['current_password' => 'yde-secret-pass'])
{
    return $test->actingAs($user)->post(route('profile.your-data.download'), $body);
}

function ydeJson($response): array
{
    return json_decode($response->streamedContent(), true, flags: JSON_THROW_ON_ERROR);
}

test('a guest is sent to sign in', function () {
    $this->post(route('profile.your-data.download'), ['current_password' => 'x'])->assertRedirect(route('login'));
});

test('a wrong password downloads nothing and tells no one', function () {
    Notification::fake();
    $me = ydeUser();

    ydeDownload($this, $me, ['current_password' => 'wrong'])->assertSessionHasErrors('current_password');

    Notification::assertNothingSent();
});

test('the owner gets their own data: therapies, groups, sessions, the messages they sent, payments, team access', function () {
    Notification::fake();
    Log::spy();
    $me = ydeUser(['username' => 'yde_me']);
    $therapy = ydeTherapy($me, ydeCounsellor(), ['background_story' => 'yde my own story']);
    $session = ydeSession($therapy);
    ydeMessage($me, $session, 'yde a message I sent');
    $group = GroupTherapy::factory()->make(['anonymous' => false, 'public' => false, 'name' => 'yde group']);
    $group->addedby()->associate(ydeUser());
    $group->save();
    DB::table('group_therapy_user')->insert(['group_therapy_id' => $group->id, 'user_id' => $me->id, 'anonymous' => false, 'created_at' => now(), 'updated_at' => now()]);
    ydeMessage($me, ydeSession($group), 'yde a group message I sent');
    $deleted = ydeTherapy($me);
    $deleted->delete();
    Transaction::factory()->create(['user_id' => $me->id, 'for_type' => Therapy::class, 'for_id' => $therapy->id, 'status' => TransactionStatusEnum::success->value, 'currency' => 'GHS']);
    AdminContentAccess::create(['admin_user_id' => ydeUser()->id, 'resource' => AdminContentAccess::THERAPY, 'subject_type' => Therapy::class, 'subject_id' => $therapy->id, 'ip' => '203.0.113.9', 'user_agent' => 'yde-agent']);

    $response = ydeDownload($this, $me)->assertOk()->assertDownload('talktherapy-your-data-'.now()->toDateString().'.json');
    expect($response->headers->get('Cache-Control'))->toContain('no-store');
    $data = ydeJson($response);

    expect($data['about']['username'])->toBe('yde_me')
        ->and(collect($data['therapies'])->pluck('backgroundStory'))->toContain('yde my own story')
        ->and(collect($data['therapies'])->firstWhere('id', $deleted->id)['deletedAt'])->not->toBeNull()
        ->and(collect($data['groups'])->pluck('name')->all())->toBe(['yde group'])
        ->and($data['sessions'])->toHaveCount(2)
        ->and(collect($data['messages'])->pluck('content')->sort()->values()->all())->toBe(['yde a group message I sent', 'yde a message I sent'])
        ->and($data['payments']['transactions'][0])->toHaveKeys(['reference', 'amount', 'currency', 'status', 'at'])
        ->and($data['payments']['transactions'][0])->not->toHaveKey('breakdown')
        ->and($data['teamAccess'])->toBe([['at' => $data['teamAccess'][0]['at'], 'what' => AdminContentAccess::THERAPY]])
        ->and($data['otherRecords'])->toBe(YourDataWording::otherRecords())
        ->and($data['summary']['messages']['sent'])->toBe(2);

    Notification::assertSentTo($me, DataCopyDownloadedNotification::class);
    Log::shouldHaveReceived('notice')->with('Data copy downloaded', ['user_id' => $me->id]);
});

test('nothing about anyone else gets in: replies, notes, urgent help, discussions, a ward, the counsellor or the administrator', function () {
    Notification::fake();
    $me = ydeUser();
    $counsellor = ydeCounsellor(['username' => 'yde_the_counsellor', 'firstName' => 'Yde-Counsellor-Name']);
    $therapy = ydeTherapy($me, $counsellor);
    $session = ydeSession($therapy);
    $mine = ydeMessage($me, $session, 'yde mine');
    $reply = ydeMessage($counsellor, $session, 'yde-LEAK the counsellor replied');
    $reply->forceFill(['message_id' => $mine->id])->save();
    $myReply = ydeMessage($me, $session, 'yde my reply');
    $myReply->forceFill(['message_id' => $reply->id])->save();
    SessionNote::factory()->create(['session_id' => $session->id, 'counsellor_id' => $counsellor->id, 'content' => 'yde-LEAK session note']);
    MessageNote::factory()->create(['message_id' => $mine->id, 'counsellor_id' => $counsellor->id, 'content' => 'yde-LEAK message note']);
    $helpChat = ydeTherapy($me, $counsellor, ['is_help_chat' => true]);
    ydeMessage($me, ydeSession($helpChat), 'yde-LEAK my urgent help message');
    $discussion = Discussion::factory()->create(['for_type' => Therapy::class, 'for_id' => $therapy->id]);
    ydeMessage($counsellor, $discussion, 'yde-LEAK case discussion');
    $ward = ydeUser();
    Guardianship::query()->create(['guardian_id' => $me->id, 'ward_id' => $ward->id]);
    $wardTherapy = ydeTherapy($ward, null, ['background_story' => 'yde-LEAK the ward\'s story']);
    ydeMessage($ward, ydeSession($wardTherapy), 'yde-LEAK the ward wrote');
    $admin = ydeUser(['username' => 'yde_admin_who_looked']);
    AdminContentAccess::create(['admin_user_id' => $admin->id, 'resource' => AdminContentAccess::THERAPY, 'subject_type' => Therapy::class, 'subject_id' => $therapy->id, 'ip' => '203.0.113.77', 'user_agent' => 'yde-agent-string']);
    AdminContentAccess::create(['admin_user_id' => $admin->id, 'resource' => AdminContentAccess::SAFETY_FLAG_REASON, 'subject_type' => Therapy::class, 'subject_id' => $therapy->id, 'ip' => '203.0.113.77', 'user_agent' => 'yde-agent-string']);

    $content = ydeDownload($this, $me)->assertOk()->streamedContent();
    $data = json_decode($content, true);

    expect($content)->not->toContain('yde-LEAK')
        ->not->toContain('yde_the_counsellor')->not->toContain('Yde-Counsellor-Name')
        ->not->toContain('yde_admin_who_looked')->not->toContain('203.0.113.77')->not->toContain('yde-agent-string')
        ->not->toContain(AdminContentAccess::SAFETY_FLAG_REASON)
        ->and(collect($data['messages'])->pluck('content')->sort()->values()->all())->toBe(['yde mine', 'yde my reply'])
        ->and(collect($data['messages'])->firstWhere('content', 'yde my reply')['replyToId'])->toBe($reply->id)
        ->and($data['therapies'])->toHaveCount(1)
        ->and($data['teamAccess'])->toHaveCount(1);
});

test('a counsellor\'s messages to clients are counted, never given', function () {
    Notification::fake();
    $counsellor = ydeCounsellor();
    $client = ydeUser();
    $session = ydeSession(ydeTherapy($client, $counsellor));
    ydeMessage($counsellor, $session, 'yde-LEAK about my client');

    $data = ydeJson(ydeDownload($this, $counsellor->user)->assertOk());

    expect(json_encode($data))->not->toContain('yde-LEAK')
        ->and($data['messages'])->toBe([])
        ->and($data['summary']['messages']['sentAsCounsellor'])->toBe(1)
        ->and($data['summary']['counsellor']['clients'])->toBe(1);
});

test('with two-factor on, the code is needed too -- and must be right', function () {
    Notification::fake();
    $secret = TwoFactorCodes::newSecret();
    $me = ydeUser(['two_factor_secret' => $secret, 'two_factor_confirmed_at' => now()]);

    ydeDownload($this, $me)->assertSessionHasErrors('code');
    // One answer for a wrong code and a wrong password -- the code step doesn't confirm the password.
    $wrongCode = ydeDownload($this, $me, ['current_password' => 'yde-secret-pass', 'code' => '000000'])->assertSessionHasErrors('form');
    $wrongPassword = ydeDownload($this, $me, ['current_password' => 'wrong', 'code' => '000000'])->assertSessionHasErrors('form');
    expect(session('errors')->first('form'))->toBe($wrongCode->getSession()->get('errors')->first('form'));
    Notification::assertNothingSent();
    RateLimiter::clear('profile.your-data.download'.sha1($me->id));

    $google2fa = new Google2FA;
    ydeDownload($this, $me, ['current_password' => 'yde-secret-pass', 'code' => $google2fa->oathTotp($secret, $google2fa->getTimestamp())])->assertOk();
    Notification::assertSentTo($me, DataCopyDownloadedNotification::class);
});

test('not while a new email address is waiting', function () {
    Notification::fake();
    $me = ydeUser(['email_verified_at' => now()]);
    $me->forceFill(['pending_email' => 'yde-new@example.org', 'pending_email_requested_at' => now()])->save();

    ydeDownload($this, $me)->assertSessionHasErrors('form');

    Notification::assertNothingSent();
});

test('three tries an hour', function () {
    Notification::fake();
    $me = ydeUser();

    foreach (range(1, 3) as $try) {
        ydeDownload($this, $me, ['current_password' => 'wrong'])->assertSessionHasErrors('current_password');
    }

    ydeDownload($this, $me)->assertTooManyRequests();
});

test('the alert goes by email only to a verified address, and never carries the data or a link to it', function () {
    $me = ydeUser(['email_verified_at' => now()]);
    $unverified = ydeUser(['email_verified_at' => null]);
    $notification = new DataCopyDownloadedNotification('Sun, Nov 15, 2026 7:00 AM (UTC)');

    expect($notification->via($me))->toContain('mail')
        ->and($notification->via($unverified))->not->toContain('mail')
        ->and((string) $notification->toMail($me)->render())->not->toContain('your-data/download');
});

test('the page and the copy give the same sentence about safety records', function () {
    $me = ydeUser();

    $this->actingAs($me)->get(route('profile.your-data'))
        ->assertInertia(fn ($page) => $page->where('otherRecords', YourDataWording::otherRecords()));
});

test('a deleted message is still held: in the list and in the count, which agree', function () {
    Notification::fake();
    $me = ydeUser();
    $session = ydeSession(ydeTherapy($me));
    ydeMessage($me, $session, 'yde kept');
    ydeMessage($me, $session, 'yde deleted')->delete();

    $data = ydeJson(ydeDownload($this, $me)->assertOk());

    expect($data['messages'])->toHaveCount(2)
        ->and(collect($data['messages'])->firstWhere('content', 'yde deleted')['deleted'])->toBeTrue()
        ->and($data['summary']['messages']['sent'])->toBe(2);
});

test('attachments are listed by name, type and size -- never where the file is kept', function () {
    Notification::fake();
    $me = ydeUser();
    $message = ydeMessage($me, ydeSession(ydeTherapy($me)), 'yde with a file');
    $message->files()->attach(File::factory()->create(['name' => 'yde-photo.jpg', 'mime' => 'image/jpeg', 'size' => 2048, 'path' => 'yde-secret-path/abc.jpg', 'storage' => 'yde-secret-disk'])->id);

    $content = ydeDownload($this, $me)->assertOk()->streamedContent();

    expect(json_decode($content, true)['messages'][0]['attachments'])->toBe([['name' => 'yde-photo.jpg', 'type' => 'image/jpeg', 'size' => 2048]])
        ->and($content)->not->toContain('yde-secret-path')->not->toContain('yde-secret-disk');
});

test('a bad character in a message can\'t cut the file short', function () {
    Notification::fake();
    $me = ydeUser();
    $message = ydeMessage($me, ydeSession(ydeTherapy($me)), 'placeholder');
    DB::table('messages')->where('id', $message->id)->update(['content' => "yde bad \xB1 byte"]);

    $data = ydeJson(ydeDownload($this, $me)->assertOk());

    expect($data['messages'][0]['content'])->toStartWith('yde bad');
});

test('a charge an organisation paid is listed without its amount, and isn\'t in the person\'s totals', function () {
    Notification::fake();
    $me = ydeUser();
    $therapy = ydeTherapy($me);
    Transaction::factory()->create(['user_id' => $me->id, 'for_type' => Therapy::class, 'for_id' => $therapy->id, 'organization_id' => Organization::factory()->create()->id, 'amount' => 987654, 'currency' => 'GHS', 'status' => TransactionStatusEnum::success->value]);

    $content = ydeDownload($this, $me)->assertOk()->streamedContent();
    $data = json_decode($content, true);

    expect($content)->not->toContain('987654')
        ->and($data['payments']['transactions'][0])->toMatchArray(['paidBy' => 'your organisation'])->not->toHaveKey('amount')
        ->and($data['summary']['payments']['transactions'])->toBe([]);
});

test('after a recent email change, the replaced address is told of a download too -- not an old one', function () {
    Notification::fake();
    $me = ydeUser(['email' => 'yde-old@example.org', 'email_verified_at' => now()]);
    $me->forceFill(['email' => 'yde-new@example.org'])->save();

    ydeDownload($this, $me)->assertOk();
    Notification::assertSentOnDemand(DataCopyDownloadedNotification::class, fn ($n, $channels, $notifiable) => $notifiable->routes['mail'] === 'yde-old@example.org');

    Notification::fake();
    $this->travel((int) config('auth.email_stop_restore_days') + 1)->days();
    RateLimiter::clear('profile.your-data.download'.sha1($me->id));
    ydeDownload($this, $me->fresh())->assertOk();
    Notification::assertNotSentTo(new AnonymousNotifiable, DataCopyDownloadedNotification::class);
    Notification::assertSentTo($me, DataCopyDownloadedNotification::class);
});

// SCRUM-436: the counts follow the same rule as the list and team access.
test('a counsellor\'s case-discussion messages are counted apart from their session messages', function () {
    Notification::fake();
    $counsellor = ydeCounsellor();
    $therapy = ydeTherapy(ydeUser(), $counsellor);
    ydeMessage($counsellor, ydeSession($therapy), 'yde in a session');
    ydeMessage($counsellor, Discussion::factory()->create(['for_type' => Therapy::class, 'for_id' => $therapy->id]), 'yde in a discussion');

    $data = ydeJson(ydeDownload($this, $counsellor->user)->assertOk());

    expect($data['summary']['messages']['sentAsCounsellor'])->toBe(1)
        ->and($data['summary']['messages']['inCaseDiscussions'])->toBe(1)
        ->and(json_encode($data))->not->toContain('yde in a discussion');
});

test('a deleted session is still held: listed, marked, and counted', function () {
    Notification::fake();
    $me = ydeUser();
    $therapy = ydeTherapy($me);
    ydeSession($therapy);
    ydeSession($therapy)->delete();

    $data = ydeJson(ydeDownload($this, $me)->assertOk());

    expect($data['sessions'])->toHaveCount(2)
        ->and(collect($data['sessions'])->where('deleted', true))->toHaveCount(1)
        ->and($data['summary']['sessions']['count'])->toBe(2);
});

// SCRUM-36: the journal is the person's own -- counted on the page, given in full in their copy, never anyone else's.
test('the journal is counted on the page and given in full in the copy -- only the person\'s own', function () {
    Notification::fake();
    $me = ydeUser();
    JournalEntry::factory()->create(['user_id' => $me->id, 'title' => 'yde mine', 'content' => 'yde my words', 'written_on' => now()->subDays(4)->toDateString()]);
    JournalEntry::factory()->create(['user_id' => ydeUser()->id, 'content' => 'yde-LEAK someone else']);

    $this->actingAs($me)->get(route('profile.your-data'))->assertInertia(fn ($page) => $page
        ->where('data.journal.entries', 1)
        ->where('data.journal.since', now()->subDays(4)->toDateString()));

    $content = ydeDownload($this, $me)->assertOk()->streamedContent();
    $data = json_decode($content, true);

    expect($data['journal'])->toHaveCount(1)
        ->and($data['journal'][0])->toMatchArray(['title' => 'yde mine', 'content' => 'yde my words', 'writtenOn' => now()->subDays(4)->toDateString()])
        ->and($content)->not->toContain('yde-LEAK');
});
