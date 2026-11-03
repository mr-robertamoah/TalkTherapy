<?php

use App\Exceptions\AppException;
use App\Exceptions\TherapyException;
use App\Traits\ResolvesExceptionResponse;
use GuzzleHttp\Psr7\Response as Psr7Response;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;

function resolvesExceptionResponseInstance()
{
    return new class
    {
        use ResolvesExceptionResponse;

        public function status(Throwable $th): int
        {
            return $this->statusFor($th);
        }

        public function message(Throwable $th, int $status): string
        {
            return $this->messageFor($th, $status);
        }
    };
}

test('an app exception with a valid HTTP status code resolves to that same status', function () {
    $subject = resolvesExceptionResponseInstance();

    expect($subject->status(new TherapyException('nope', 422)))->toBe(422);
});

// SCRUM-95: only the app's own refusals are trusted, whatever another exception's code says.
test('a non-app exception with an HTTP-looking code resolves to 500', function () {
    $subject = resolvesExceptionResponseInstance();

    expect($subject->status(new RuntimeException('upstream said no', 404)))->toBe(500);
});

test('an HTTP client error carrying an upstream body resolves to 500 with the generic message', function () {
    $subject = resolvesExceptionResponseInstance();
    $error = new RequestException(new Response(new Psr7Response(400, [], '{"error":"authorization-header-error"}')));

    $status = $subject->status($error);

    expect($error->getCode())->toBe(400)
        ->and($status)->toBe(500)
        ->and($subject->message($error, $status))->not->toContain('authorization');
});

arch('every exception the app throws for people to read extends AppException')
    ->expect('App\\Exceptions')
    ->classes()
    ->toExtend(AppException::class)
    ->ignoring([AppException::class]);

test('an uncoded exception (getCode() 0) resolves to 500', function () {
    $subject = resolvesExceptionResponseInstance();

    expect($subject->status(new TherapyException('no code set')))->toBe(500);
});

test('a code outside the valid HTTP status range resolves to 500', function () {
    $subject = resolvesExceptionResponseInstance();

    expect($subject->status(new TherapyException('sqlstate-ish', 23000)))->toBe(500);
});

test('status 500 always shows the generic message, regardless of the exception text', function () {
    $subject = resolvesExceptionResponseInstance();

    expect($subject->message(new Exception('some internal detail'), 500))
        ->toBe('Something unfortunate happened. Please try again shortly.');
});

test('a non-500 status shows the exception\'s own message', function () {
    $subject = resolvesExceptionResponseInstance();

    expect($subject->message(new Exception('you are not allowed to do that'), 422))
        ->toBe('you are not allowed to do that');
});
