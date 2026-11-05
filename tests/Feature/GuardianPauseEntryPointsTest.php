<?php

use App\Contracts\VideoProviderInterface;
use App\Enums\RequestStatusEnum;
use App\Enums\RequestTypeEnum;
use App\Enums\SessionStatusEnum;
use App\Enums\TherapyStatusEnum;
use App\Http\Controllers\CommentController;
use App\Http\Controllers\DiscussionController;
use App\Http\Controllers\GroupTherapyController;
use App\Http\Controllers\LinkController;
use App\Http\Controllers\MessageController;
use App\Http\Controllers\MessageNoteController;
use App\Http\Controllers\RequestController;
use App\Http\Controllers\SessionController;
use App\Http\Controllers\SessionNoteController;
use App\Http\Controllers\SessionScheduleProposalController;
use App\Http\Controllers\TherapyController;
use App\Http\Controllers\TherapyTopicController;
use App\Http\Controllers\TransactionController;
use App\Http\Controllers\VideoConsentController;
use App\Http\Controllers\VideoSessionController;
use App\Models\Counsellor;
use App\Models\GroupTherapy;
use App\Models\Guardianship;
use App\Models\Link;
use App\Models\Message;
use App\Models\Request;
use App\Models\Session;
use App\Models\Therapy;
use App\Models\TherapyTopic;
use App\Models\User;
use App\Models\VideoConsent;
use App\Models\VideoSession;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

// SCRUM-288: every write entry point of a therapy refuses a minor with no active guardian (and the
// counsellor of their paused 1:1) with guardian_required, and lets the same minor through once they
// have a guardian. The inventory test below fails when a new write route on these controllers is
// in neither the dataset nor the allowlist. Helpers are file-local and gpep-prefixed (--parallel).

function gpepFakeVideoProvider(): VideoProviderInterface
{
    return new class implements VideoProviderInterface
    {
        public function createRoom(VideoSession $videoSession): array
        {
            return ['room_id' => 'room-'.$videoSession->id, 'meta' => []];
        }

        public function createParticipantCredentials(VideoSession $videoSession, User $user, string $displayName, bool $receiveOnly = false, bool $canSendVideo = true): array
        {
            return ['token' => 'fake'];
        }

        public function endRoom(VideoSession $videoSession): void {}

        public function removeParticipant(VideoSession $videoSession, User $user): void {}

        public function updateParticipantCapabilities(VideoSession $videoSession, User $user, bool $canSendAudio, bool $canSendVideo): void {}
    };
}

function gpepMinor(bool $guarded): User
{
    $minor = User::factory()->create(['dob' => now()->subYears(15)->toDateString()]);

    if ($guarded) {
        Guardianship::query()->create(['guardian_id' => User::factory()->adult()->create()->id, 'ward_id' => $minor->id, 'ward_was_minor_at_creation' => true]);
    }

    return $minor;
}

function gpepTherapy(User $client, ?Counsellor $counsellor, array $attributes = []): Therapy
{
    return Therapy::factory()->create(array_merge([
        'addedby_type' => User::class,
        'addedby_id' => $client->id,
        'counsellor_id' => $counsellor?->id,
        'status' => TherapyStatusEnum::in_session->value,
        'anonymous' => false,
        // FREE therapies must be public (EnsureTherapyDataIsValidAction).
        'public' => true,
        'client_was_minor_at_creation' => true,
    ], $attributes));
}

function gpepSession(Therapy|GroupTherapy $for, Counsellor $counsellor, string $status, int $startsInMinutes, int $minutes = 60): Session
{
    return Session::factory()->create([
        'for_type' => $for::class,
        'for_id' => $for->id,
        'addedby_type' => Counsellor::class,
        'addedby_id' => $counsellor->id,
        'status' => $status,
        'type' => 'ONLINE',
        'start_time' => now()->addMinutes($startsInMinutes),
        'end_time' => now()->addMinutes($startsInMinutes + $minutes),
    ]);
}

// A minor's 1:1 therapy with a live session, and a group they belong to with a live session.
function gpepContext(bool $guarded): array
{
    $minor = gpepMinor($guarded);
    $counsellorUser = User::factory()->adult()->create();
    $counsellor = Counsellor::factory()->create(['user_id' => $counsellorUser->id]);
    $therapy = gpepTherapy($minor, $counsellor);
    $liveSession = gpepSession($therapy, $counsellor, SessionStatusEnum::in_session->value, -5);

    $group = GroupTherapy::factory()->create(['addedby_type' => Counsellor::class, 'addedby_id' => $counsellor->id, 'anonymous' => false, 'public' => true]);
    $group->counsellors()->attach($counsellor->id, ['state' => 'ACTIVE', 'role' => 'NORMAL']);
    $adultMember = User::factory()->adult()->create();
    $group->users()->attach($minor->id, ['anonymous' => false, 'was_minor_at_join' => true]);
    $group->users()->attach($adultMember->id, ['anonymous' => false, 'was_minor_at_join' => false]);
    $groupSession = gpepSession($group, $counsellor, SessionStatusEnum::in_session->value, -5);

    return compact('minor', 'counsellorUser', 'counsellor', 'therapy', 'liveSession', 'group', 'adultMember', 'groupSession');
}

// A 1:1 of the same pair with no active session (proposing and creating sessions need that).
function gpepIdleTherapy(array $c): Therapy
{
    return gpepTherapy($c['minor'], $c['counsellor']);
}

function gpepProposal(Therapy $therapy, $from, $to): Request
{
    return Request::factory()->create([
        'from_type' => $from::class, 'from_id' => $from->id,
        'to_type' => $to::class, 'to_id' => $to->id,
        'for_type' => Therapy::class, 'for_id' => $therapy->id,
        'type' => RequestTypeEnum::sessionScheduleProposal->value,
        'status' => RequestStatusEnum::pending->value,
        'round' => 1,
        'data' => [
            'startTime' => now()->addDays(3)->toDateTimeString(),
            'endTime' => now()->addDays(3)->addHour()->toDateTimeString(),
            'name' => 'Weekly check-in', 'about' => 'Following up.', 'type' => 'ONLINE', 'paymentType' => 'FREE',
        ],
    ]);
}

function gpepTopic(Therapy $therapy, Counsellor $counsellor): TherapyTopic
{
    return $therapy->topics()->create(['name' => 'Sleep', 'description' => 'About sleep', 'counsellor_id' => $counsellor->id]);
}

function gpepSessionPayload(): array
{
    return [
        'name' => 'Check-in', 'about' => 'A check-in.', 'paymentType' => 'FREE', 'type' => 'ONLINE',
        'startTime' => now()->addDays(2)->toDateTimeString(), 'endTime' => now()->addDays(2)->addHour()->toDateTimeString(),
    ];
}

function gpepPaid(string $per): array
{
    return ['payment_type' => 'PAID', 'payment_data' => ['per' => $per, 'amount' => 150, 'currency' => 'GHS', 'inPersonAmount' => 150]];
}

function gpepUrl(string $uri, array $params): string
{
    return '/'.strtr($uri, collect($params)->mapWithKeys(fn ($value, $key) => ['{'.$key.'}' => $value])->all());
}

// [method, uri, actor ('minor' | 'counsellor'), fn (context) => [params, payload], refusal status]
function gpepEntryPoints(): array
{
    $sessionCreate = fn (array $c) => [['therapyId' => gpepIdleTherapy($c)->id], gpepSessionPayload()];
    $sessionUpdate = function (array $c) {
        $therapy = gpepIdleTherapy($c);
        $session = gpepSession($therapy, $c['counsellor'], SessionStatusEnum::pending->value, 60 * 48);

        return [['therapyId' => $therapy->id, 'sessionId' => $session->id], ['name' => 'Renamed']];
    };
    $inSession = fn (array $c) => [['sessionId' => gpepSession(gpepIdleTherapy($c), $c['counsellor'], SessionStatusEnum::pending->value, -1)->id], []];
    $topicSet = fn (array $c) => [['sessionId' => $c['liveSession']->id], ['topicId' => gpepTopic($c['therapy'], $c['counsellor'])->id]];
    $topicUnset = function (array $c) {
        $topic = gpepTopic($c['therapy'], $c['counsellor']);
        $c['liveSession']->topics()->attach($topic->id);

        return [['sessionId' => $c['liveSession']->id], ['topicId' => $topic->id]];
    };
    $therapyUpdate = fn (array $c) => [['therapyId' => $c['therapy']->id], ['name' => 'Renamed therapy']];
    $groupUpdate = function (array $c) {
        $group = GroupTherapy::factory()->create(['addedby_type' => User::class, 'addedby_id' => $c['minor']->id, 'client_was_minor_at_creation' => true]);

        return [['groupTherapyId' => $group->id], ['name' => 'Renamed group']];
    };

    return [
        'send a message (1:1)' => ['POST', 'api/messages', 'minor', fn (array $c) => [[], [
            'content' => 'Hello', 'type' => 'NORMAL', 'fromId' => $c['minor']->id, 'fromType' => 'User', 'forId' => $c['liveSession']->id, 'forType' => 'Session',
        ]], 403],
        'send a message (counsellor of a paused 1:1)' => ['POST', 'api/messages', 'counsellor', fn (array $c) => [[], [
            'content' => 'Hello', 'type' => 'NORMAL', 'fromId' => $c['counsellor']->id, 'fromType' => 'Counsellor', 'forId' => $c['liveSession']->id, 'forType' => 'Session',
        ]], 403],
        'send a message (group)' => ['POST', 'api/messages', 'minor', fn (array $c) => [[], [
            'content' => 'Hello', 'type' => 'NORMAL', 'fromId' => $c['minor']->id, 'fromType' => 'User', 'forId' => $c['groupSession']->id, 'forType' => 'Session',
        ]], 403],
        'edit a message' => ['POST', 'api/messages/{messageId}', 'minor', function (array $c) {
            $message = Message::factory()->create([
                'from_type' => User::class, 'from_id' => $c['minor']->id, 'to_type' => Counsellor::class, 'to_id' => $c['counsellor']->id,
                'for_type' => Session::class, 'for_id' => $c['liveSession']->id, 'content' => 'Hi',
            ]);

            return [['messageId' => $message->id], ['content' => 'Edited']];
        }, 403],
        'create a session (api)' => ['POST', 'api/therapies/{therapyId}/sessions', 'counsellor', $sessionCreate, 403],
        'create a session (web)' => ['POST', 'therapies/{therapyId}/sessions', 'counsellor', $sessionCreate, 403],
        'update a session (api)' => ['PATCH', 'api/sessions/{sessionId}', 'counsellor', $sessionUpdate, 403],
        'update a session (web)' => ['PATCH', 'therapies/{therapyId}/sessions/{sessionId}', 'counsellor', $sessionUpdate, 403],
        'start a session (api)' => ['POST', 'api/sessions/{sessionId}/in_session', 'minor', $inSession, 403],
        'start a session (web)' => ['POST', 'sessions/{sessionId}/in_session', 'counsellor', $inSession, 403],
        'set the session topic' => ['POST', 'api/sessions/{sessionId}/topics/set', 'counsellor', $topicSet, 403],
        'unset the session topic' => ['POST', 'api/sessions/{sessionId}/topics/unset', 'counsellor', $topicUnset, 403],
        'create a topic' => ['POST', 'api/therapies/{therapyId}/topics', 'counsellor', fn (array $c) => [['therapyId' => $c['therapy']->id], ['name' => 'Sleep', 'description' => 'About sleep']], 403],
        'update a topic' => ['PATCH', 'api/topics/{topicId}', 'counsellor', fn (array $c) => [['topicId' => gpepTopic($c['therapy'], $c['counsellor'])->id], ['name' => 'Rest']], 403],
        'delete a topic' => ['DELETE', 'api/topics/{topicId}', 'counsellor', fn (array $c) => [['topicId' => gpepTopic($c['therapy'], $c['counsellor'])->id], []], 403],
        'propose a session time' => ['POST', 'api/therapies/{therapyId}/schedule-proposals', 'minor', fn (array $c) => [['therapyId' => gpepIdleTherapy($c)->id], [
            'name' => 'Check-in', 'about' => 'A check-in.', 'startTime' => now()->addDays(3)->toDateTimeString(), 'endTime' => now()->addDays(3)->addHour()->toDateTimeString(),
        ]], 403],
        'counter a proposed time' => ['POST', 'api/requests/{requestId}/schedule-counter-offer', 'minor', fn (array $c) => [
            ['requestId' => gpepProposal(gpepIdleTherapy($c), $c['counsellor'], $c['minor'])->id],
            ['startTime' => now()->addDays(4)->toDateTimeString(), 'endTime' => now()->addDays(4)->addHour()->toDateTimeString()],
        ], 403],
        'accept a proposed time' => ['POST', 'api/requests/{requestId}', 'minor', fn (array $c) => [
            ['requestId' => gpepProposal(gpepIdleTherapy($c), $c['counsellor'], $c['minor'])->id], ['response' => 'accepted'],
        ], 403],
        'accept a proposed time (counsellor of a paused 1:1)' => ['POST', 'api/requests/{requestId}', 'counsellor', fn (array $c) => [
            ['requestId' => gpepProposal(gpepIdleTherapy($c), $c['minor'], $c['counsellor'])->id], ['response' => 'accepted'],
        ], 403],
        // SCRUM-382 review: told neutrally (GuardianRequiredException::requestUnavailable()), so no code (null).
        'accept a group membership request from the minor' => ['POST', 'api/requests/{requestId}', 'counsellor', function (array $c) {
            $group = GroupTherapy::factory()->create(['addedby_type' => Counsellor::class, 'addedby_id' => $c['counsellor']->id, 'allow_anyone' => false]);
            $group->counsellors()->attach($c['counsellor']->id, ['state' => 'ACTIVE', 'role' => 'NORMAL']);
            $request = Request::factory()->create([
                'from_type' => User::class, 'from_id' => $c['minor']->id, 'to_type' => Counsellor::class, 'to_id' => $c['counsellor']->id,
                'for_type' => GroupTherapy::class, 'for_id' => $group->id,
                'type' => RequestTypeEnum::groupTherapyMembership->value, 'status' => RequestStatusEnum::pending->value, 'data' => [],
            ]);

            return [['requestId' => $request->id], ['response' => 'accepted']];
        }, 403, null],
        'take on the minor\'s therapy' => ['POST', 'api/requests/{requestId}', 'counsellor', function (array $c) {
            $therapy = gpepTherapy($c['minor'], null, ['status' => TherapyStatusEnum::pending->value]);
            $request = Request::factory()->create([
                'from_type' => User::class, 'from_id' => $c['minor']->id, 'to_type' => Counsellor::class, 'to_id' => $c['counsellor']->id,
                'for_type' => Therapy::class, 'for_id' => $therapy->id,
                'type' => RequestTypeEnum::therapy->value, 'status' => RequestStatusEnum::pending->value, 'data' => [],
            ]);

            return [['requestId' => $request->id], ['response' => 'accepted']];
        }, 403],
        'update the therapy (api)' => ['PATCH', 'api/therapies/{therapyId}', 'minor', $therapyUpdate, 403],
        'update the therapy (web)' => ['PATCH', 'therapies/{therapyId}', 'minor', $therapyUpdate, 403],
        'set the strict payment gate' => ['PATCH', 'therapies/{therapyId}/strict-payment-gate', 'counsellor', fn (array $c) => [['therapyId' => $c['therapy']->id], ['strictPaymentGate' => true]], 403],
        'ask a counsellor to assist' => ['POST', 'api/therapies/{therapyId}/assist', 'minor', fn (array $c) => [
            ['therapyId' => gpepTherapy($c['minor'], null, ['status' => TherapyStatusEnum::pending->value])->id], ['counsellorIds' => [$c['counsellor']->id]],
        ], 403],
        'start a therapy' => ['POST', 'api/therapies', 'minor', fn (array $c) => [[], [
            'name' => 'New therapy', 'backgroundStory' => 'A story.', 'anonymous' => false, 'allowInPerson' => false, 'public' => true, 'sessionType' => 'ONCE', 'paymentType' => 'FREE', 'maxSessions' => 1,
        ]], 422],
        'start a group' => ['POST', 'api/group-therapies', 'minor', fn (array $c) => [[], [
            'name' => 'New group', 'about' => 'About it.', 'anonymous' => false, 'allowInPerson' => false, 'allowAnyone' => true, 'public' => true, 'sessionType' => 'ONCE', 'paymentType' => 'FREE',
        ]], 422],
        'join a group' => ['POST', 'api/group-therapies/{groupTherapyId}/join', 'minor', function (array $c) {
            $group = GroupTherapy::factory()->create(['addedby_type' => Counsellor::class, 'addedby_id' => $c['counsellor']->id, 'allow_anyone' => true, 'max_users' => 10]);

            return [['groupTherapyId' => $group->id], []];
        }, 422],
        // SCRUM-383: confirming a member invitation link -- the same gate as the join button.
        'join a group by link' => ['POST', 'api/links/{uuid}/join', 'minor', function (array $c) {
            $creator = User::factory()->adult()->create();
            $group = GroupTherapy::factory()->create(['addedby_type' => User::class, 'addedby_id' => $creator->id, 'allow_anyone' => false, 'max_users' => 10]);
            $link = Link::query()->forceCreate(['type' => 'GROUP_THERAPY_PARTICIPANT', 'state' => 'ACTIVE', 'uuid' => (string) Str::uuid(),
                'addedby_type' => User::class, 'addedby_id' => $creator->id, 'for_type' => GroupTherapy::class, 'for_id' => $group->id]);

            return [['uuid' => $link->uuid], ['anonymous' => true]];
        }, 422],
        'update their group (api)' => ['PATCH', 'api/group-therapies/{groupTherapyId}', 'minor', $groupUpdate, 403],
        'update their group (web)' => ['PATCH', 'group-therapies/{groupTherapyId}', 'minor', $groupUpdate, 403],
        'join a 1:1 video call (counsellor of a paused 1:1)' => ['POST', 'sessions/{sessionId}/video/join', 'counsellor', fn (array $c) => [['sessionId' => $c['liveSession']->id], []], 403],
        // (A minor joining group video isn't here: since the user's decision of 2026-11-05 it is
        // refused even with a guardian, until group video consent exists -- SCRUM-313.)
        // Told the therapy is paused, not that video needs consent (the guarded case has the consent).
        'join a 1:1 video call (the minor)' => ['POST', 'sessions/{sessionId}/video/join', 'minor', function (array $c) {
            if ($guardianId = $c['minor']->guardians()->value('guardian_id')) {
                VideoConsent::factory()->create(['ward_id' => $c['minor']->id, 'guardian_id' => $guardianId, 'consentable_type' => Therapy::class, 'consentable_id' => $c['therapy']->id]);
            }

            return [['sessionId' => $c['liveSession']->id], []];
        }, 403],
        'invite a counsellor by link' => ['POST', 'api/links', 'minor', fn (array $c) => [[], [
            'type' => 'THERAPY_COUNSELLOR', 'addedbyType' => 'User', 'addedbyId' => $c['minor']->id, 'forType' => 'Therapy',
            'forId' => gpepTherapy($c['minor'], null, ['status' => TherapyStatusEnum::pending->value])->id,
        ]], 403],
        'comment on their own therapy' => ['POST', 'api/comments', 'minor', fn (array $c) => [[], [
            'content' => 'A comment', 'commentableType' => 'Therapy', 'commentableId' => $c['therapy']->id,
        ]], 403],
        'pay for the therapy' => ['POST', 'therapies/{therapyId}/transactions', 'minor', fn (array $c) => [
            ['therapyId' => gpepTherapy($c['minor'], $c['counsellor'], gpepPaid('PER_THERAPY'))->id], [],
        ], 403],
        'pay for a session' => ['POST', 'sessions/{sessionId}/transactions', 'minor', function (array $c) {
            $therapy = gpepTherapy($c['minor'], $c['counsellor'], gpepPaid('PER_SESSION'));
            $session = gpepSession($therapy, $c['counsellor'], SessionStatusEnum::pending->value, 60 * 48);
            $session->forceFill(['payment_type' => 'PAID'])->save();

            return [['sessionId' => $session->id], []];
        }, 403],
        'pay for a group' => ['POST', 'group-therapies/{groupTherapyId}/transactions', 'minor', function (array $c) {
            $group = GroupTherapy::factory()->create(array_merge(['addedby_type' => Counsellor::class, 'addedby_id' => $c['counsellor']->id], gpepPaid('PER_THERAPY')));
            $group->users()->attach($c['minor']->id, ['anonymous' => false, 'was_minor_at_join' => true]);

            return [['groupTherapyId' => $group->id], []];
        }, 403],
    ];
}

// Writes deliberately left open while paused, with why.
const GPEP_ALLOWED = [
    // Stopping or withdrawing never needs a guardian.
    'DELETE api/messages/{messageId}', 'DELETE api/messages/{messageId}/me',
    'DELETE api/sessions/{sessionId}', 'DELETE therapies/{therapyId}/sessions/{sessionId}',
    'POST api/sessions/{sessionId}/end', 'POST sessions/{sessionId}/end',
    'POST api/sessions/{sessionId}/abandon', 'POST sessions/{sessionId}/abandon',
    'POST api/sessions/{sessionId}/fail', 'POST sessions/{sessionId}/fail',
    'POST api/therapies/{therapyId}', 'POST therapies/{therapyId}', 'DELETE api/therapies/{therapyId}', 'DELETE therapies/{therapyId}',
    'POST api/group-therapies/{groupTherapyId}', 'POST group-therapies/{groupTherapyId}',
    'DELETE api/group-therapies/{groupTherapyId}', 'DELETE group-therapies/{groupTherapyId}',
    // SCRUM-73: leaving a group is a withdrawal too -- allowed while paused (and while suspended).
    'POST api/group-therapies/{groupTherapyId}/leave',
    'POST sessions/{sessionId}/video/leave', 'POST sessions/{sessionId}/video/end', 'POST sessions/{sessionId}/video/hand/lower',
    'POST sessions/{sessionId}/video/participants/{participantKey}/revoke-speaking', 'POST sessions/{sessionId}/video/participants/{participantKey}/remove',
    // Need a live call row, which a paused minor never has (removed on pause, and the join is refused).
    'POST sessions/{sessionId}/video/hand/raise', 'POST sessions/{sessionId}/video/participants/{participantKey}/grant-speaking', 'POST sessions/{sessionId}/video/speaking-activity',
    // A group's counsellors only -- they are never paused in a group (only the minor is).
    'POST group-therapies/{groupTherapyId}/sessions', 'PATCH group-therapies/{groupTherapyId}/sessions/{sessionId}', 'DELETE group-therapies/{groupTherapyId}/sessions/{sessionId}',
    'PATCH group-therapies/{groupTherapyId}/payment-gate',
    // Getting money back, and Paystack's own server calling.
    'POST transactions/{transactionId}/refund-request', 'POST api/paystack/webhook',
    // Counsellor-only private notes and case discussions: they never reach the client, so a paused
    // 1:1's counsellor (Q5: no contact with the client) keeps them (coordinator's decision).
    'POST api/sessions/{sessionId}/notes', 'PATCH api/sessions/notes/{noteId}', 'DELETE api/sessions/notes/{noteId}',
    'POST api/messages/{messageId}/notes', 'PATCH api/messages/notes/{noteId}', 'DELETE api/messages/notes/{noteId}',
    'POST api/discussions', 'POST api/discussions/{discussionId}', 'DELETE api/discussions/{discussionId}',
    'POST api/discussions/{discussionId}/abandon', 'POST api/discussions/{discussionId}/end', 'POST api/discussions/{discussionId}/in_session',
    'POST api/discussions/{discussionId}/removecounsellor', 'POST api/discussions/{discussionId}/request',
    // A guardian's own actions (never the paused minor's); a consent only counts while its guardian does.
    'POST therapies/{therapyId}/video-consent', 'DELETE therapies/{therapyId}/video-consent', 'PATCH therapies/{therapyId}/video-consent-mode',
    // Managing existing links: using a therapy-counsellor link is refused while paused
    // (PerformTherapyCounsellorLinkAction), whatever its status.
    'POST api/links/delete/multiple', 'POST api/links/status/multiple', 'POST api/links/{linkId}/delete', 'POST api/links/{linkId}/status',
    // Deleting a comment.
    'DELETE api/comments/{commentId}',
];

function gpepRun($test, string $method, string $uri, string $actor, Closure $build, bool $guarded): array
{
    Notification::fake();
    app()->instance(VideoProviderInterface::class, gpepFakeVideoProvider());
    Http::fake(['*/transaction/initialize' => Http::response(['status' => true, 'data' => ['authorization_url' => 'https://checkout.paystack.com/gpep', 'reference' => 'gpep_'.uniqid()]], 200)]);

    $c = gpepContext($guarded);
    [$params, $payload] = $build($c);
    $user = $actor === 'minor' ? $c['minor'] : $c['counsellorUser'];

    return [$test->actingAs($user)->json($method, gpepUrl($uri, $params), $payload), $c];
}

// $code: null for a refusal that is told neutrally (no code at all).
test('a write is refused with guardian_required while the minor has no active guardian', function (string $method, string $uri, string $actor, Closure $build, int $status, ?string $code = 'guardian_required') {
    [$response] = gpepRun($this, $method, $uri, $actor, $build, guarded: false);

    $response->assertStatus($status);
    $code ? $response->assertJsonPath('code', $code) : $response->assertJsonMissingPath('code');
})->with(gpepEntryPoints());

test('the same write goes through once the minor has an active guardian', function (string $method, string $uri, string $actor, Closure $build, int $status = 0, ?string $code = null) {
    [$response] = gpepRun($this, $method, $uri, $actor, $build, guarded: true);

    expect($response->status())->toBeLessThan(400, $response->getContent())
        ->and($response->json('code'))->not->toBe('guardian_required');
})->with(gpepEntryPoints());

test('every write route of the therapy controllers is either gated (in the dataset) or explicitly allowed', function () {
    $controllers = [
        TherapyController::class, GroupTherapyController::class, SessionController::class, MessageController::class,
        TherapyTopicController::class, SessionScheduleProposalController::class, VideoSessionController::class, RequestController::class,
        TransactionController::class, SessionNoteController::class, MessageNoteController::class, CommentController::class,
        VideoConsentController::class, LinkController::class, DiscussionController::class,
    ];
    $gated = collect(gpepEntryPoints())->map(fn (array $row) => "{$row[0]} {$row[1]}")->unique();

    $unaccounted = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($route) => in_array($route->getControllerClass(), $controllers, true))
        ->flatMap(fn ($route) => collect($route->methods())->reject(fn ($m) => in_array($m, ['GET', 'HEAD'], true))->map(fn ($m) => "{$m} {$route->uri()}"))
        ->reject(fn (string $key) => $gated->contains($key) || in_array($key, GPEP_ALLOWED, true))
        ->values();

    expect($unaccounted->all())->toBe([]);
});
