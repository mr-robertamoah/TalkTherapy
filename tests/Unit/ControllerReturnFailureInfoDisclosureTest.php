<?php

use App\Http\Controllers\AboutController;
use App\Http\Controllers\AdministratorController;
use App\Http\Controllers\CommentController;
use App\Http\Controllers\ContactController;
use App\Http\Controllers\DiscussionController;
use App\Http\Controllers\GroupTherapyController;
use App\Http\Controllers\HowToController;
use App\Http\Controllers\LikeController;
use App\Http\Controllers\LinkController;
use App\Http\Controllers\PostController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\SessionController;
use App\Http\Controllers\TestimonialController;
use App\Http\Controllers\TherapyController;
use App\Http\Controllers\TherapyTopicController;
use App\Http\Controllers\UserController;
use Illuminate\Http\Request;

// SCRUM-94: before this fix, every one of these controllers' shared returnFailure() re-threw
// a plain Exception on the JSON path instead of returning a response. With APP_DEBUG=true that
// leaks the raw message/stack trace via Laravel's default exception renderer; with APP_DEBUG=false
// it instead swallows the real status/message and always shows a generic 500, even for safe,
// intentionally-non-500 business errors. Both are fixed by returning the response directly here.
function callControllerReturnFailure(string $controllerClass, Request $request, Throwable $th)
{
    $reflection = new ReflectionMethod($controllerClass, 'returnFailure');
    $reflection->setAccessible(true);

    return $reflection->invoke(new $controllerClass, $request, $th);
}

$controllers = [
    AboutController::class,
    AdministratorController::class,
    CommentController::class,
    ContactController::class,
    DiscussionController::class,
    GroupTherapyController::class,
    HowToController::class,
    LikeController::class,
    LinkController::class,
    PostController::class,
    ReportController::class,
    SessionController::class,
    TestimonialController::class,
    TherapyController::class,
    TherapyTopicController::class,
    UserController::class,
];

test('an uncoded exception on the JSON path returns a generic 500 JSON response instead of throwing', function (string $controllerClass) {
    $request = Request::create('/', 'GET');
    $request->headers->set('Accept', 'application/json');

    $response = callControllerReturnFailure($controllerClass, $request, new Exception('some internal detail that should not leak'));

    expect($response->getStatusCode())->toBe(500);
    expect($response->getData(true)['message'])->toBe('Something unfortunate happened. Please try again shortly.');
})->with($controllers);

test('an exception with a real HTTP status code returns that status and its own message on the JSON path', function (string $controllerClass) {
    $request = Request::create('/', 'GET');
    $request->headers->set('Accept', 'application/json');

    $response = callControllerReturnFailure($controllerClass, $request, new Exception('you are not allowed to do that', 422));

    expect($response->getStatusCode())->toBe(422);
    expect($response->getData(true)['message'])->toBe('you are not allowed to do that');
})->with($controllers);

test('a non-JSON request still redirects back with a flashed error, unaffected by the fix', function (string $controllerClass) {
    $request = Request::create('/', 'GET');
    $request->headers->set('Accept', 'text/html');

    $response = callControllerReturnFailure($controllerClass, $request, new Exception('you are not allowed to do that', 422));

    expect($response->getTargetUrl())->not->toBeNull();
})->with($controllers);
