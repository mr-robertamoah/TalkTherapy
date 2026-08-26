<?php

namespace App\Services\Paystack;

use App\Services\Service;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

// Thin wrapper around Paystack's REST API -- this app's first outbound third-party API call, so
// this class alone owns the base URL/auth header, rather than every caller reimplementing it.
// Deliberately dumb: throws Laravel's own RequestException on a non-2xx response and lets the
// calling Action translate that into a TransactionException, rather than coupling this class to
// an App\Exceptions\* type.
class PaystackClient extends Service
{
    public function initializeTransaction(array $data): array
    {
        return $this->request()
            ->post('/transaction/initialize', $data)
            ->throw()
            ->json();
    }

    public function verifyTransaction(string $reference): array
    {
        return $this->request()
            ->get("/transaction/verify/{$reference}")
            ->throw()
            ->json();
    }

    private function request(): PendingRequest
    {
        return Http::baseUrl(config('services.paystack.base_url'))
            ->withToken(config('services.paystack.secret_key'))
            ->acceptJson();
    }
}
