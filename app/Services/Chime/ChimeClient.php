<?php

namespace App\Services\Chime;

use App\Services\Service;
use Aws\ChimeSDKMeetings\ChimeSDKMeetingsClient;

// Thin wrapper around the AWS Chime SDK Meetings API, mirroring DailyClient/PaystackClient's own
// established shape in this codebase -- deliberately dumb, returns each AWS Result cast to a
// plain array so callers never depend on the AWS SDK's own Result object shape directly (keeps
// ChimeVideoProvider testable via a mocked ChimeClient, not a mocked AWS SDK client).
class ChimeClient extends Service
{
    private ChimeSDKMeetingsClient $client;

    public function __construct()
    {
        $this->client = new ChimeSDKMeetingsClient([
            'version' => 'latest',
            'region' => config('services.chime.region'),
            'credentials' => [
                'key' => config('services.chime.key'),
                'secret' => config('services.chime.secret'),
            ],
        ]);
    }

    public function createMeeting(array $args): array
    {
        return $this->client->createMeeting($args)->toArray();
    }

    public function createAttendee(array $args): array
    {
        return $this->client->createAttendee($args)->toArray();
    }

    public function deleteMeeting(string $meetingId): void
    {
        $this->client->deleteMeeting(['MeetingId' => $meetingId]);
    }

    // TT-3.2b/SCRUM-309: Chime SDK Meetings has no "eject by external id" call -- ejection
    // requires the AWS-generated AttendeeId, which ChimeVideoProvider looks up here (matched
    // against the ExternalUserId we set at CreateAttendee time) rather than persisting a
    // provider-specific id ourselves.
    public function listAttendees(string $meetingId): array
    {
        return $this->client->listAttendees(['MeetingId' => $meetingId])->toArray();
    }

    public function deleteAttendee(string $meetingId, string $attendeeId): void
    {
        $this->client->deleteAttendee(['MeetingId' => $meetingId, 'AttendeeId' => $attendeeId]);
    }
}
