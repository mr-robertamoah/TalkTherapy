<?php

use App\Http\Controllers\MessageController;
use Illuminate\Http\Request;

function callReturnFailure(Request $request, Throwable $th)
{
    $reflection = new ReflectionMethod(MessageController::class, 'returnFailure');
    $reflection->setAccessible(true);

    return $reflection->invoke(new MessageController, $request, $th);
}

test('an uncoded exception (getCode() 0) shows the generic message, not its raw message (SCRUM-92)', function () {
    // Before the fix, $message was decided by comparing getCode() to the literal 500 --
    // an exception whose code is 0 (the common default) is neither 500 nor a valid HTTP
    // status, so it fell back to HTTP 500 while still leaking its raw getMessage().
    $request = Request::create('/', 'GET');
    $request->headers->set('Accept', 'application/json');

    $response = callReturnFailure($request, new Exception('some internal detail that should not leak'));

    expect($response->getStatusCode())->toBe(500);
    expect($response->getData(true)['message'])->toBe('Something unfortunate happened. Please try again shortly.');
});

test('an exception with a real HTTP status code still shows its own message', function () {
    $request = Request::create('/', 'GET');
    $request->headers->set('Accept', 'application/json');

    $response = callReturnFailure($request, new Exception('you are not allowed to do that', 422));

    expect($response->getStatusCode())->toBe(422);
    expect($response->getData(true)['message'])->toBe('you are not allowed to do that');
});
