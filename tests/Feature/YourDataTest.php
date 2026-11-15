<?php

use App\Actions\User\GetYourDataAction;
use App\Enums\AdministratorTypeEnum;
use App\Enums\ConstantsEnum;
use App\Enums\LicensingAuthorityTypeEnum;
use App\Enums\LicensingTypeEnum;
use App\Enums\MessageTypeEnum;
use App\Enums\RequestStatusEnum;
use App\Enums\RequestTypeEnum;
use App\Enums\TherapyStatusEnum;
use App\Enums\TransactionStatusEnum;
use App\Models\AdminContentAccess;
use App\Models\Counsellor;
use App\Models\CounsellorPayout;
use App\Models\Discussion;
use App\Models\File;
use App\Models\GroupTherapy;
use App\Models\Guardianship;
use App\Models\License;
use App\Models\LicensingAuthority;
use App\Models\Message;
use App\Models\MessageNote;
use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Models\Request;
use App\Models\Session;
use App\Models\SessionNote;
use App\Models\Therapy;
use App\Models\Transaction;
use App\Models\User;
use App\Services\LicenseService;
use Carbon\Carbon;
use Database\Seeders\YourDataSeeder;
use Illuminate\Support\Facades\DB;

// SCRUM-50 (TT-8.2): "What we hold about you" -- the signed-in person's own data, why, for how long and who sees it.

function ydtCounsellor(array $attributes = []): Counsellor
{
    return Counsellor::factory()->create(['user_id' => User::factory()->adult()->create($attributes)->id]);
}

function ydtTherapy(User $client, ?Counsellor $counsellor = null, array $attributes = []): Therapy
{
    $therapy = Therapy::factory()->make(['anonymous' => false, 'public' => false, ...$attributes]);
    $therapy->addedby()->associate($client);
    $therapy->counsellor_id = $counsellor?->id;
    $therapy->forceFill(array_intersect_key($attributes, ['is_help_chat' => true]))->save();

    return $therapy;
}

function ydtSession(Therapy|GroupTherapy $for): Session
{
    return Session::factory()->create(['for_type' => $for::class, 'for_id' => $for->id, 'start_time' => now()->addDay(), 'end_time' => now()->addDay()->addHour()]);
}

function ydtMessage(User|Counsellor $from, Session|Discussion $for): Message
{
    $message = Message::factory()->make(['type' => MessageTypeEnum::normal->value]);
    $message->from()->associate($from);
    $message->for()->associate($for);
    $message->save();

    return $message;
}

function ydtGroup(User $member, bool $anonymous = false): GroupTherapy
{
    $group = GroupTherapy::factory()->make(['anonymous' => false, 'public' => false]);
    $group->addedby()->associate(User::factory()->adult()->create());
    $group->save();
    DB::table('group_therapy_user')->insert(['group_therapy_id' => $group->id, 'user_id' => $member->id, 'anonymous' => $anonymous, 'created_at' => now(), 'updated_at' => now()]);

    return $group;
}

function ydtAccess(string $resource, string $subjectType, int $subjectId, ?int $adminId = null): AdminContentAccess
{
    return AdminContentAccess::create([
        'admin_user_id' => $adminId ?? User::factory()->adult()->create()->id,
        'resource' => $resource,
        'subject_type' => $subjectType,
        'subject_id' => $subjectId,
        'ip' => '203.0.113.77',
        'user_agent' => 'ydt-agent-string',
    ]);
}

function ydtData(User $user): array
{
    return GetYourDataAction::new()->execute($user->fresh());
}

function ydtQueries(callable $callback): int
{
    DB::flushQueryLog();
    DB::enableQueryLog();
    $callback();
    $count = count(DB::getQueryLog());
    DB::disableQueryLog();

    return $count;
}

function ydtLicense(Counsellor $counsellor, string $authorityName, string $number): License
{
    $authority = LicensingAuthority::factory()->create([
        'name' => $authorityName,
        'type' => LicensingAuthorityTypeEnum::govermental->value,
        'license_type' => LicensingTypeEnum::number->value,
    ]);
    $license = new License(['number' => $number]);
    $license->licensingAuthority()->associate($authority);
    $license->for()->associate($counsellor);
    $license->save();
    $license->files()->attach(File::factory()->create(['name' => "{$number}.jpg", 'path' => 'licenses', 'storage' => 'license_documents', 'mime' => 'image/jpeg'])->id);

    return $license;
}

// Everything a busy person (a counsellor who is also a client, guardian and member) can hold.
function ydtFill(User $user): void
{
    $counsellor = ydtCounsellor();
    $named = null;
    foreach ([['status' => TherapyStatusEnum::in_session->value], ['status' => TherapyStatusEnum::ended->value], ['anonymous' => true], ['is_help_chat' => true]] as $attributes) {
        $therapy = ydtTherapy($user, $counsellor, $attributes);
        $named ??= $therapy;
        $session = ydtSession($therapy);
        ydtMessage($user, $session);
        ydtAccess(AdminContentAccess::THERAPY, Therapy::class, $therapy->id);
    }
    $group = ydtGroup($user, anonymous: true);
    ydtMessage($user, ydtSession($group));
    Transaction::factory()->create(['user_id' => $user->id, 'for_type' => Therapy::class, 'for_id' => $named->id, 'status' => TransactionStatusEnum::success->value]);
    Transaction::factory()->create(['user_id' => $user->id, 'for_type' => GroupTherapy::class, 'for_id' => $group->id, 'currency' => 'USD']);
    Guardianship::query()->create(['guardian_id' => $user->id, 'ward_id' => User::factory()->create()->id]);
    OrganizationMember::factory()->create(['organization_id' => Organization::factory()->create()->id, 'user_id' => $user->id]);
    Request::factory()->for($user, 'from')->for($user, 'for')->create(['type' => RequestTypeEnum::ageVerification->value, 'data' => [], 'status' => RequestStatusEnum::pending->value])
        ->files()->attach(File::factory()->create(['name' => 'id.jpg', 'path' => '', 'storage' => 'identity_documents'])->id, ['tag' => 'front-id']);
    if ($own = $user->counsellor) {
        ydtLicense($own, 'Counselling Board', 'PRO-1');
        CounsellorPayout::factory()->create(['counsellor_id' => $own->id]);
        ydtTherapy(User::factory()->create(), $own);
    }
}

test('a guest is sent to sign in', function () {
    $this->get(route('profile.your-data'))->assertRedirect(route('login'));
});

test('the owner sees their own data on the page', function () {
    $user = User::factory()->adult()->create(['country' => 'Ghana', 'gender' => 'FEMALE']);
    $user->forceFill(['pending_email' => 'new.address@example.org', 'pending_email_requested_at' => now()])->save();
    ydtFill($user);

    $this->actingAs($user)->get(route('profile.your-data'))->assertOk()->assertInertia(fn ($page) => $page
        ->component('Profile/YourData')
        ->where('data.account.username', $user->username)
        ->where('data.account.email', $user->email)
        ->where('data.account.pendingEmail', 'n•••@e•••.org')
        ->where('data.account.country', 'Ghana')
        ->where('data.security.passwordSet', true)
        ->where('data.security.twoFactorEnabled', false)
        // Urgent help chats are never counted (the help chat, its session or its messages).
        ->where('data.therapies', ['active' => 1, 'ended' => 1, 'anonymousActive' => 1, 'anonymousEnded' => 0, 'deleted' => 0])
        ->where('data.sessions.count', 4)
        ->where('data.messages.sent', 4)
        ->where('data.messages.sentAsCounsellor', null)
        ->where('data.groups', ['current' => 1, 'anonymous' => 1, 'left' => 0, 'started' => 0])
        ->where('data.guardianship.wards', 1)
        ->where('data.payments.transactions', [
            ['currency' => 'GHS', 'count' => 1, 'paidAmount' => Transaction::where('user_id', $user->id)->where('currency', 'GHS')->value('amount')],
            ['currency' => 'USD', 'count' => 1, 'paidAmount' => 0],
        ])
        ->where('data.payments.payouts', null)
        ->has('data.documents.identity', 1)
        ->where('data.documents.identity.0.hasDocument', true)
        ->where('data.documents.identity.0.deletesAt', null) // still waiting for a check
        ->where('data.documents.licenses', null)
        ->has('data.organizations.memberships', 1)
        ->where('data.teamAccess.total', 3) // not the help chat's
        ->where('data.counsellor', null));
});

test('the retention periods are the privacy policy\'s own', function () {
    config(['help.transcript_retention_days' => 41, 'identity_verification.document_retention_days' => 17, 'notifications.retention_days' => 77]);
    $user = User::factory()->adult()->create();

    $this->get(route('privacy'))->assertInertia(fn ($page) => $page->where('retentionDays.helpChat', 41)->where('retentionDays.identityDocument', 17));
    $privacy = $this->get(route('privacy'))->viewData('page')['props']['retentionDays'];

    $this->actingAs($user)->get(route('profile.your-data'))->assertInertia(fn ($page) => $page
        ->where('retentionDays', $privacy)
        ->where('data.notifications.retentionDays', 77));

    $user->forceFill(['notification_retention_days' => 30])->save();
    $this->get(route('profile.your-data'))->assertInertia(fn ($page) => $page->where('data.notifications.retentionDays', 30));
});

test('a guardian\'s page doesn\'t count their ward\'s therapies', function () {
    $guardian = User::factory()->adult()->create();
    $ward = User::factory()->create(['dob' => now()->subYears(14)->toDateString()]);
    Guardianship::query()->create(['guardian_id' => $guardian->id, 'ward_id' => $ward->id]);
    $therapy = ydtTherapy($ward, ydtCounsellor());
    ydtMessage($ward, ydtSession($therapy));
    Transaction::factory()->create(['user_id' => $ward->id, 'for_type' => Therapy::class, 'for_id' => $therapy->id]);

    $data = ydtData($guardian);

    expect($data['therapies'])->toBe(['active' => 0, 'ended' => 0, 'anonymousActive' => 0, 'anonymousEnded' => 0, 'deleted' => 0])
        ->and($data['sessions']['count'])->toBe(0)
        ->and($data['messages']['sent'])->toBe(0)
        ->and($data['payments']['transactions'])->toBe([])
        ->and($data['guardianship'])->toBe(['guardians' => 0, 'wards' => 1]);
});

test('a minor\'s page doesn\'t show their guardian\'s data', function () {
    $guardian = User::factory()->adult()->create();
    $ward = User::factory()->create(['dob' => now()->subYears(14)->toDateString()]);
    Guardianship::query()->create(['guardian_id' => $guardian->id, 'ward_id' => $ward->id]);
    ydtFill($guardian);

    $response = $this->actingAs($ward)->get(route('profile.your-data'))->assertInertia(fn ($page) => $page
        ->where('data.therapies.active', 0)
        ->where('data.sessions.count', 0)
        ->where('data.messages.sent', 0)
        ->where('data.payments.transactions', [])
        ->where('data.organizations.memberships', [])
        ->where('data.documents.identity', [])
        ->where('data.teamAccess.total', 0)
        ->where('data.guardianship', ['guardians' => 1, 'wards' => 0]));

    expect($response->getContent())->not->toContain($guardian->username)->not->toContain(e($guardian->email));
});

test('a group member\'s message count is only their own', function () {
    $me = User::factory()->adult()->create();
    $other = User::factory()->adult()->create();
    $group = ydtGroup($me);
    DB::table('group_therapy_user')->insert(['group_therapy_id' => $group->id, 'user_id' => $other->id, 'anonymous' => false, 'created_at' => now(), 'updated_at' => now()]);
    $session = ydtSession($group);
    ydtMessage($me, $session);
    ydtMessage($other, $session);
    ydtMessage($other, $session);
    $mine = Message::where('from_id', $me->id)->value('id');
    $theirs = Message::where('from_id', $other->id)->value('id');
    ydtAccess(AdminContentAccess::MESSAGE, Message::class, $theirs);
    ydtAccess(AdminContentAccess::MESSAGE, Message::class, $mine);

    $data = ydtData($me);

    expect($data['messages']['sent'])->toBe(1)
        ->and($data['groups']['current'])->toBe(1)
        ->and($data['teamAccess']['total'])->toBe(1);
});

test('a counsellor\'s notes are never counted, and clients are a number', function () {
    $counsellor = ydtCounsellor();
    $client = User::factory()->adult()->create();
    $therapy = ydtTherapy($client, $counsellor);
    ydtTherapy($client, $counsellor, ['status' => TherapyStatusEnum::ended->value]);
    $session = ydtSession($therapy);
    $message = ydtMessage($counsellor, $session);
    SessionNote::factory()->create(['session_id' => $session->id, 'counsellor_id' => $counsellor->id]);
    MessageNote::factory()->create(['message_id' => $message->id, 'counsellor_id' => $counsellor->id]);

    $data = ydtData($counsellor->user);

    expect($data['messages'])->toBe(['sent' => 0, 'sentAsCounsellor' => 1, 'inCaseDiscussions' => 0])
        ->and($data['counsellor']['clients'])->toBe(1)
        ->and($data['counsellor']['verification'])->toBe('verified')
        ->and($data['therapies']['active'])->toBe(0)
        ->and(ydtData($client)['messages']['sent'])->toBe(0);
});

test('an anonymous therapy shows nothing about the counsellor', function () {
    $counsellor = ydtCounsellor(['username' => 'ydt_counsellor_name', 'firstName' => 'Zebedee', 'lastName' => 'Quillfeather']);
    $client = User::factory()->adult()->create();
    ydtTherapy($client, $counsellor, ['anonymous' => true]);

    $response = $this->actingAs($client)->get(route('profile.your-data'))
        ->assertInertia(fn ($page) => $page->where('data.therapies.anonymousActive', 1)->where('data.therapies.active', 0));

    $data = json_encode($response->viewData('page')['props']['data']);
    expect($data)->not->toContain('ydt_counsellor_name')->not->toContain('Zebedee')->not->toContain('Quillfeather')
        ->not->toContain($counsellor->email)->not->toContain($counsellor->user->email);
});

test('team access lists only the person\'s own content: never safety, help or group rows, never the administrator', function () {
    $admin = User::factory()->adult()->create(['username' => 'ydt_admin_who_looked']);
    $me = User::factory()->adult()->create();
    $therapy = ydtTherapy($me, ydtCounsellor());
    $session = ydtSession($therapy);
    $helpChat = ydtTherapy($me, ydtCounsellor(), ['is_help_chat' => true]);
    $group = ydtGroup($me);
    $someoneElses = ydtTherapy(User::factory()->adult()->create());

    ydtAccess(AdminContentAccess::THERAPY, Therapy::class, $therapy->id, $admin->id);
    ydtAccess(AdminContentAccess::SESSION_MESSAGES, Session::class, $session->id, $admin->id);
    ydtAccess(AdminContentAccess::MESSAGE, Message::class, ydtMessage($me, $session)->id, $admin->id);
    // None of these:
    ydtAccess(AdminContentAccess::THERAPY, GroupTherapy::class, $group->id, $admin->id);
    ydtAccess(AdminContentAccess::THERAPY, Therapy::class, $helpChat->id, $admin->id);
    ydtAccess(AdminContentAccess::MESSAGE, Message::class, ydtMessage($me, ydtSession($helpChat))->id, $admin->id);
    ydtAccess(AdminContentAccess::THERAPY, Therapy::class, $someoneElses->id, $admin->id);
    ydtAccess(AdminContentAccess::HELP_REQUEST_IDENTITY, Therapy::class, $therapy->id, $admin->id);
    ydtAccess(AdminContentAccess::SAFETY_FLAG_IDENTITY, Therapy::class, $therapy->id, $admin->id);
    ydtAccess(AdminContentAccess::SAFETY_FLAG_REASON, Therapy::class, $therapy->id, $admin->id);
    ydtAccess(AdminContentAccess::DISCUSSION_MESSAGES, Session::class, $session->id, $admin->id);

    $response = $this->actingAs($me)->get(route('profile.your-data'))->assertInertia(fn ($page) => $page
        ->where('data.teamAccess.total', 3)
        ->has('data.teamAccess.latest', 3)
        ->where('data.teamAccess.latest.0.resource', AdminContentAccess::MESSAGE)
        ->where('data.teamAccess.latest.2.resource', AdminContentAccess::THERAPY));

    $latest = $response->viewData('page')['props']['data']['teamAccess']['latest'];
    expect(collect($latest)->every(fn ($row) => array_keys($row) === ['at', 'resource']))->toBeTrue();
    expect($response->getContent())->not->toContain('ydt_admin_who_looked')->not->toContain('203.0.113.77')->not->toContain('ydt-agent-string');
});

test('team access lists a counsellor\'s licence and an identity document, and caps the list', function () {
    $counsellor = ydtCounsellor();
    $license = ydtLicense($counsellor, 'Counselling Board', 'PRO-9');
    $check = Request::factory()->for($counsellor->user, 'from')->for($counsellor->user, 'for')->create(['type' => RequestTypeEnum::ageVerification->value, 'data' => []]);
    $otherLicense = ydtLicense(ydtCounsellor(), 'Counselling Board', 'PRO-10');

    ydtAccess(AdminContentAccess::LICENSE_DOCUMENT, License::class, $license->id);
    ydtAccess(AdminContentAccess::IDENTITY_DOCUMENT, Request::class, $check->id);
    ydtAccess(AdminContentAccess::LICENSE_DOCUMENT, License::class, $otherLicense->id);
    foreach (range(1, GetYourDataAction::TEAM_ACCESS_LATEST) as $i) {
        ydtAccess(AdminContentAccess::LICENSE_DOCUMENT, License::class, $license->id);
    }

    $access = ydtData($counsellor->user)['teamAccess'];

    expect($access['total'])->toBe(GetYourDataAction::TEAM_ACCESS_LATEST + 2)
        ->and($access['latest'])->toHaveCount(GetYourDataAction::TEAM_ACCESS_LATEST);
});

test('document deletion dates are the deletion jobs\' own', function () {
    config(['identity_verification.document_retention_days' => 30, 'counsellor.license_rejected_retention_days' => 20, 'counsellor.national_id_retention_days' => 10]);
    $counsellor = ydtCounsellor();
    $user = $counsellor->user;

    $check = Request::factory()->for($user, 'from')->for($user, 'for')->create(['type' => RequestTypeEnum::ageVerification->value, 'data' => [], 'status' => RequestStatusEnum::pending->value]);
    $check->files()->attach(File::factory()->create(['name' => 'id.jpg', 'path' => '', 'storage' => 'identity_documents'])->id, ['tag' => 'front-id']);
    $check->update(['status' => RequestStatusEnum::accepted->value]);
    Request::query()->whereKey($check->id)->update(['responded_at' => now()->subDays(5)]);

    $nationalId = ydtLicense($counsellor, ConstantsEnum::nationalId->value, 'NID-1');
    $professional = ydtLicense($counsellor, 'Counselling Board', 'PRO-1');
    $verification = Request::factory()->for($counsellor, 'from')->for($counsellor, 'for')->create([
        'type' => RequestTypeEnum::counsellor->value,
        'data' => ['nationalIdLicense' => $nationalId->id, 'otherLicense' => $professional->id],
        'status' => RequestStatusEnum::pending->value,
    ]);
    $verification->update(['status' => RequestStatusEnum::accepted->value]);
    Request::query()->whereKey($verification->id)->update(['responded_at' => now()->subDays(3)]);

    $documents = ydtData($user)['documents'];
    $licenses = collect($documents['licenses'])->keyBy('isNationalId');

    expect(substr($documents['identity'][0]['deletesAt'], 0, 10))->toBe(now()->addDays(25)->toDateString())
        // An accepted professional licence is kept; the national ID goes nationalId days after.
        ->and($licenses[false]['deletesAt'])->toBeNull()
        ->and(substr($licenses[true]['deletesAt'], 0, 10))->toBe(now()->addDays(7)->toDateString());

    // The job agrees: nothing yet, then the national ID once that date has passed.
    expect(LicenseService::new()->applyDocumentRetention(dryRun: true))->toHaveCount(0);
    $this->travel(8)->days();
    expect(LicenseService::new()->applyDocumentRetention(dryRun: true)->pluck('license')->all())->toBe([$nationalId->id]);
});

test('the number of queries doesn\'t grow with what the person holds', function () {
    $empty = User::factory()->adult()->create();
    $busy = User::factory()->adult()->create();
    ydtFill($busy);
    ydtFill($busy);

    // Both counsellors have a licence (with none, the issuers' eager load is skipped: one fewer query).
    $quietCounsellor = ydtCounsellor();
    ydtLicense($quietCounsellor, 'Counselling Board', 'PRO-Q');
    $busyCounsellor = ydtCounsellor()->user;
    ydtFill($busyCounsellor);
    ydtFill($busyCounsellor);

    [$a, $b, $c, $d] = array_map(fn (User $user) => ydtQueries(fn () => GetYourDataAction::new()->execute($user)), [$empty->fresh(), $busy->fresh(), $quietCounsellor->user->fresh(), $busyCounsellor->fresh()]);

    expect($a)->toBe($b)
        ->and($c)->toBe($d)
        ->and($d)->toBeLessThanOrEqual(14);
});

test('the demo accounts seed once, with something in every category', function () {
    $admin = User::factory()->adult()->create();
    $admin->administrator()->create(['verified_at' => now(), 'type' => AdministratorTypeEnum::super->value]);

    $this->seed(YourDataSeeder::class);
    $this->seed(YourDataSeeder::class);

    $client = User::where('username', 'your_data_demo')->sole();
    $data = ydtData($client);

    expect($data['therapies'])->toBe(['active' => 1, 'ended' => 0, 'anonymousActive' => 0, 'anonymousEnded' => 1, 'deleted' => 1])
        ->and($data['sessions']['count'])->toBe(2)
        ->and($data['messages']['sent'])->toBe(3)
        ->and($data['groups']['anonymous'])->toBe(1)
        ->and($data['guardianship']['wards'])->toBe(1)
        ->and($data['payments']['transactions'])->toHaveCount(1)
        ->and($data['documents']['identity'][0]['deletesAt'])->not->toBeNull()
        ->and($data['notifications']['count'])->toBe(3)
        // Their therapy, session and message -- not the group's page, the help chat or the identity reveal.
        ->and($data['teamAccess']['total'])->toBe(3);

    $counsellor = ydtData(User::where('username', 'your_data_demo_counsellor')->sole());
    expect($counsellor['counsellor']['clients'])->toBe(1)
        ->and($counsellor['payments']['payouts'][0]['paidAmount'])->toBe(12000)
        ->and(collect($counsellor['documents']['licenses'])->pluck('deletesAt')->filter())->toHaveCount(1)
        ->and($counsellor['teamAccess']['total'])->toBe(1);
});

test('the profile page and the privacy policy link to it', function () {
    $vue = fn (string $path) => file_get_contents(resource_path("js/{$path}"));

    expect($vue('Pages/Profile/Show.vue'))->toContain("route('profile.your-data')")
        ->and($vue('Pages/Privacy.vue'))->toContain("route('profile.your-data')")
        ->and($vue('Pages/Profile/YourData.vue'))->toContain('awaiting legal review');
});

test('deleted therapies are still held, so they are counted', function () {
    $client = User::factory()->adult()->create();
    ydtTherapy($client, ydtCounsellor());
    $deleted = ydtTherapy($client, ydtCounsellor(), ['status' => TherapyStatusEnum::ended->value]);
    ydtSession($deleted);
    ydtAccess(AdminContentAccess::THERAPY, Therapy::class, $deleted->id);
    $deleted->delete();
    ydtTherapy($client, ydtCounsellor(), ['is_help_chat' => true])->delete();

    $data = ydtData($client);

    expect($data['therapies'])->toBe(['active' => 1, 'ended' => 0, 'anonymousActive' => 0, 'anonymousEnded' => 0, 'deleted' => 1])
        ->and($data['sessions']['count'])->toBe(1)
        ->and($data['teamAccess']['total'])->toBe(1);
});

test('urgent help is never counted: not the chat, its session or its messages', function () {
    $client = User::factory()->adult()->create();
    $counsellor = ydtCounsellor();
    $session = ydtSession(ydtTherapy($client, $counsellor, ['is_help_chat' => true]));
    ydtMessage($client, $session);
    ydtMessage($counsellor, $session);

    $data = ydtData($client);

    expect($data['therapies'])->not->toHaveKey('helpChats')
        ->and(array_sum($data['therapies']))->toBe(0)
        ->and($data['sessions']['count'])->toBe(0)
        ->and($data['messages']['sent'])->toBe(0)
        ->and(ydtData($counsellor->user)['messages']['sentAsCounsellor'])->toBe(0);
});

test('team access never lists a counsellor\'s case-discussion messages', function () {
    $counsellor = ydtCounsellor();
    $therapy = ydtTherapy(User::factory()->adult()->create(), $counsellor);
    $discussion = Discussion::factory()->create(['for_type' => Therapy::class, 'for_id' => $therapy->id]);
    $inDiscussion = ydtMessage($counsellor, $discussion);
    $inSession = ydtMessage($counsellor, ydtSession($therapy));

    ydtAccess(AdminContentAccess::MESSAGE, Message::class, $inDiscussion->id);
    ydtAccess(AdminContentAccess::MESSAGE_ATTACHMENT, Message::class, $inDiscussion->id);
    ydtAccess(AdminContentAccess::MESSAGE, Message::class, $inSession->id);

    $data = ydtData($counsellor->user);

    expect($data['teamAccess']['total'])->toBe(1)
        // Held, so still counted -- on their own line (SCRUM-436), apart from the sessions.
        ->and($data['messages']['sentAsCounsellor'])->toBe(1)
        ->and($data['messages']['inCaseDiscussions'])->toBe(1);
});

test('a group they left counts only the sessions from before they left', function () {
    $me = User::factory()->adult()->create();
    $left = ydtGroup($me);
    Session::factory()->create(['for_type' => GroupTherapy::class, 'for_id' => $left->id, 'start_time' => now()->subDays(10), 'end_time' => now()->subDays(10)->addHour()]);
    Session::factory()->create(['for_type' => GroupTherapy::class, 'for_id' => $left->id, 'start_time' => now()->subDays(2), 'end_time' => now()->subDays(2)->addHour()]);
    DB::table('group_therapy_user')->where('group_therapy_id', $left->id)->where('user_id', $me->id)->update(['created_at' => now()->subDays(20), 'left_at' => now()->subDays(5)]);
    ydtSession(ydtGroup($me));

    $data = ydtData($me);

    expect($data['sessions']['count'])->toBe(2)
        ->and($data['groups'])->toMatchArray(['current' => 1, 'left' => 1]);
});

test('an abandoned upload\'s deletion date is the job\'s own', function () {
    config(['counsellor.license_abandoned_retention_days' => 14]);
    $counsellor = ydtCounsellor();
    $license = ydtLicense($counsellor, 'Counselling Board', 'PRO-A');
    License::query()->whereKey($license->id)->update(['created_at' => now()->subDays(4)]);

    $deletesAt = ydtData($counsellor->user)['documents']['licenses'][0]['deletesAt'];

    expect(substr($deletesAt, 0, 10))->toBe(now()->addDays(10)->toDateString())
        ->and(LicenseService::new()->applyDocumentRetention(dryRun: true))->toHaveCount(0);
    $this->travelTo(Carbon::parse($deletesAt)->subMinute());
    expect(LicenseService::new()->applyDocumentRetention(dryRun: true))->toHaveCount(0);
    $this->travelTo(Carbon::parse($deletesAt)->addMinute());
    expect(LicenseService::new()->applyDocumentRetention(dryRun: true)->pluck('license')->all())->toBe([$license->id]);
});

test('a turned-down verification\'s deletion date is the job\'s own', function () {
    config(['counsellor.license_rejected_retention_days' => 20]);
    $counsellor = ydtCounsellor();
    $license = ydtLicense($counsellor, 'Counselling Board', 'PRO-R');
    $verification = Request::factory()->for($counsellor, 'from')->for($counsellor, 'for')->create([
        'type' => RequestTypeEnum::counsellor->value,
        'data' => ['otherLicense' => $license->id],
        'status' => RequestStatusEnum::pending->value,
    ]);
    $verification->update(['status' => RequestStatusEnum::rejected->value]);
    Request::query()->whereKey($verification->id)->update(['responded_at' => now()->subDays(6)]);

    $deletesAt = ydtData($counsellor->user)['documents']['licenses'][0]['deletesAt'];

    expect(substr($deletesAt, 0, 10))->toBe(now()->addDays(14)->toDateString())
        ->and(LicenseService::new()->applyDocumentRetention(dryRun: true))->toHaveCount(0);
    $this->travelTo(Carbon::parse($deletesAt)->subMinute());
    expect(LicenseService::new()->applyDocumentRetention(dryRun: true))->toHaveCount(0);
    $this->travelTo(Carbon::parse($deletesAt)->addMinute());
    expect(LicenseService::new()->applyDocumentRetention(dryRun: true)->pluck('license')->all())->toBe([$license->id]);
});

// SCRUM-432 security review: a group's sessions from before they joined aren't theirs.
test('a group they joined later counts only the sessions from after they joined', function () {
    $me = User::factory()->adult()->create();
    $group = ydtGroup($me);
    Session::factory()->create(['for_type' => GroupTherapy::class, 'for_id' => $group->id, 'start_time' => now()->subDays(10), 'end_time' => now()->subDays(10)->addHour()]);
    Session::factory()->create(['for_type' => GroupTherapy::class, 'for_id' => $group->id, 'start_time' => now()->addDay(), 'end_time' => now()->addDay()->addHour()]);

    expect(ydtData($me)['sessions']['count'])->toBe(1);
});
