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

    // TT-7.6a/SCRUM-225: confirms an account number/bank-or-momo-code pair resolves to a real
    // account before a Transfer Recipient is ever created for it, and returns the account holder
    // name Paystack has on file -- shown back to the counsellor as confirmation, never taken as
    // freeform input from them.
    public function resolveAccountNumber(string $accountNumber, string $bankCode): array
    {
        return $this->request()
            ->get('/bank/resolve', ['account_number' => $accountNumber, 'bank_code' => $bankCode])
            ->throw()
            ->json();
    }

    // Registers a payout destination with Paystack once and returns a recipient_code -- what
    // TT-7.6c's payout execution actually transfers against on every subsequent payout, rather
    // than re-resolving/re-registering the account each time.
    public function createTransferRecipient(array $data): array
    {
        return $this->request()
            ->post('/transferrecipient', $data)
            ->throw()
            ->json();
    }

    // TT-7.6c/SCRUM-227: the actual money-movement call -- always dispatched from a queued job
    // (ProcessCounsellorPayoutJob), never inline in a request/response cycle, mirroring TT-7.7d's
    // isolation-from-dispatcher precedent for the identical class of external, money-moving call.
    public function initiateTransfer(array $data): array
    {
        return $this->request()
            ->post('/transfer', $data)
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
