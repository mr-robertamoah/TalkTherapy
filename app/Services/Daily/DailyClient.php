<?php

namespace App\Services\Daily;

use App\Services\Service;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

// Thin wrapper around Daily.co's REST API, mirroring PaystackClient's own established shape in
// this codebase exactly -- deliberately dumb: throws Laravel's own RequestException on a non-2xx
// response and lets DailyVideoProvider translate that, rather than coupling this class to an
// App\Exceptions\* type.
class DailyClient extends Service
{
    public function createRoom(array $data): array
    {
        return $this->request()
            ->post('/rooms', $data)
            ->throw()
            ->json();
    }

    public function createMeetingToken(array $properties): array
    {
        return $this->request()
            ->post('/meeting-tokens', ['properties' => $properties])
            ->throw()
            ->json();
    }

    public function deleteRoom(string $roomName): void
    {
        $this->request()->delete("/rooms/{$roomName}")->throw();
    }

    // TT-3.2b/SCRUM-309: Daily's own REST API for ejecting one or more currently-connected
    // participants without ending the room (https://docs.daily.co/reference/rest-api/rooms/eject).
    // Accepts `user_ids` (matching the `user_id` we already send when minting each participant's
    // own meeting token), so no separate Daily-generated participant id needs to be tracked.
    public function ejectParticipants(string $roomName, array $userIds): array
    {
        return $this->request()
            ->post("/rooms/{$roomName}/eject", ['user_ids' => $userIds])
            ->throw()
            ->json();
    }

    // TT-3.2f-e/SCRUM-322: Daily's own REST API for changing an already-connected participant's
    // permissions live, without a reconnect (https://docs.daily.co/reference/rest-api/rooms/update-permissions),
    // confirmed via the SCRUM-320 research spike. $data is keyed by participant id (mirroring
    // ejectParticipants()'s own `user_ids`, matching the `user_id` sent at token-mint time) ->
    // an array of permission fields (canSend, hasPresence, canAdmin, canReceive) to overwrite.
    public function updateRoomPermissions(string $roomName, array $data): array
    {
        return $this->request()
            ->post("/rooms/{$roomName}/update-permissions", ['data' => $data])
            ->throw()
            ->json();
    }

    private function request(): PendingRequest
    {
        return Http::baseUrl(config('services.daily.base_url'))
            ->withToken(config('services.daily.api_key'))
            ->acceptJson();
    }
}
