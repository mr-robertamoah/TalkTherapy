<?php

use App\Models\Session;
use App\Models\User;
use App\Models\VideoSession;
use App\Services\Chime\ChimeClient;
use App\Services\Chime\ChimeVideoProvider;

// TT-3.1a/SCRUM-274: ChimeClient is mocked throughout -- this file pins down ChimeVideoProvider's
// own request-shaping/response-mapping logic, independent of the real AWS Chime SDK Meetings API.

test('createRoom names the meeting deterministically from the VideoSession id and stores the full Meeting object', function () {
    $session = Session::factory()->create();
    $videoSession = VideoSession::factory()->create(['session_id' => $session->id, 'provider' => 'chime']);
    $meeting = ['MeetingId' => 'aws-meeting-id', 'MediaPlacement' => ['AudioHostUrl' => 'wss://example']];

    $client = Mockery::mock(ChimeClient::class);
    $client->shouldReceive('createMeeting')
        ->once()
        ->with(Mockery::on(function ($args) use ($videoSession) {
            return $args['ExternalMeetingId'] === "session-{$videoSession->session_id}-{$videoSession->id}"
                && ! empty($args['ClientRequestToken']);
        }))
        ->andReturn(['Meeting' => $meeting]);

    $result = (new ChimeVideoProvider($client))->createRoom($videoSession);

    expect($result['room_id'])->toBe('aws-meeting-id')
        ->and($result['meta'])->toBe(['meeting' => $meeting]);
});

test('createParticipantCredentials creates an attendee against the stored meeting id and returns both the meeting and attendee', function () {
    $user = User::factory()->create();
    $meeting = ['MeetingId' => 'aws-meeting-id'];
    $videoSession = VideoSession::factory()->create([
        'provider_room_id' => 'aws-meeting-id',
        'provider_meta' => ['meeting' => $meeting],
    ]);
    $attendee = ['AttendeeId' => 'attendee-1', 'JoinToken' => 'join-token-value'];

    $client = Mockery::mock(ChimeClient::class);
    $client->shouldReceive('createAttendee')
        ->once()
        ->with(['MeetingId' => 'aws-meeting-id', 'ExternalUserId' => (string) $user->id])
        ->andReturn(['Attendee' => $attendee]);

    // $displayName is intentionally passed but unused -- Chime's CreateAttendee API has no
    // display-name field at all (see ChimeVideoProvider's own comment).
    $result = (new ChimeVideoProvider($client))->createParticipantCredentials($videoSession, $user, 'Some Display Name');

    expect($result)->toBe(['meeting' => $meeting, 'attendee' => $attendee]);
});

test('endRoom deletes the provider meeting by its stored provider_room_id', function () {
    $videoSession = VideoSession::factory()->create(['provider_room_id' => 'aws-meeting-id']);

    $client = Mockery::mock(ChimeClient::class);
    $client->shouldReceive('deleteMeeting')->once()->with('aws-meeting-id');

    (new ChimeVideoProvider($client))->endRoom($videoSession);
});

test('endRoom is a no-op when the meeting was never actually created', function () {
    $videoSession = VideoSession::factory()->create(['provider_room_id' => null]);

    $client = Mockery::mock(ChimeClient::class);
    $client->shouldNotReceive('deleteMeeting');

    (new ChimeVideoProvider($client))->endRoom($videoSession);
});
