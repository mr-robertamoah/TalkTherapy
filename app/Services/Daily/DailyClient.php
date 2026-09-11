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

    private function request(): PendingRequest
    {
        return Http::baseUrl(config('services.daily.base_url'))
            ->withToken(config('services.daily.api_key'))
            ->acceptJson();
    }
}
