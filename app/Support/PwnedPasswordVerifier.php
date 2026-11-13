<?php

namespace App\Support;

use Exception;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Stringable;
use Illuminate\Validation\NotPwnedVerifier;

// SCRUM-35 (security review): Laravel's breach check, but a failure is logged as a one-line warning -- never the
// exception. Laravel's report($e) writes the stack trace, whose frames carry the password being checked (wherever PHP
// keeps call arguments in traces). If Have I Been Pwned doesn't answer, the password is allowed, as before.
class PwnedPasswordVerifier extends NotPwnedVerifier
{
    protected function search($hashPrefix)
    {
        try {
            $response = $this->factory->withHeaders(['Add-Padding' => true])
                ->timeout($this->timeout)
                ->get('https://api.pwnedpasswords.com/range/'.$hashPrefix);
        } catch (Exception $exception) {
            Log::warning('The breached-password check is unavailable; the password was allowed.', ['error' => class_basename($exception)]);

            return new Collection;
        }

        $body = $response->successful() ? $response->body() : '';

        return (new Stringable($body))->trim()->explode("\n")->filter(fn ($line) => str_contains($line, ':'));
    }
}
