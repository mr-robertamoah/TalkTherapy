<?php

use App\Traits\ResolvesExceptionResponse;

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

test('an exception with a valid HTTP status code resolves to that same status', function () {
    $subject = resolvesExceptionResponseInstance();

    expect($subject->status(new Exception('nope', 422)))->toBe(422);
});

test('an uncoded exception (getCode() 0) resolves to 500', function () {
    $subject = resolvesExceptionResponseInstance();

    expect($subject->status(new Exception('no code set')))->toBe(500);
});

test('a code outside the valid HTTP status range resolves to 500', function () {
    $subject = resolvesExceptionResponseInstance();

    expect($subject->status(new Exception('sqlstate-ish', 23000)))->toBe(500);
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
