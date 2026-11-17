<?php

use App\Contracts\VideoProviderInterface;
use App\Models\User;
use App\Models\VideoSession;
use App\Providers\VideoServiceProvider;
use App\Services\Fake\FakeVideoProvider;
use Illuminate\Support\Facades\Log;

// SCRUM-458: the provider-less fake can only ever be bound in local and testing.

function fvpResolveIn(string $environment): VideoProviderInterface
{
    app()->detectEnvironment(fn () => $environment);
    config(['video.provider' => 'fake']);

    return app(VideoProviderInterface::class);
}

test('the fake video provider is refused outside local and testing', function (string $environment) {
    expect(fn () => fvpResolveIn($environment))
        ->toThrow(LogicException::class, 'video.provider "fake" is for local development and tests only');
})->with(['production', 'staging']);

test('the fake video provider resolves in local and testing', function (string $environment) {
    expect(fvpResolveIn($environment))->toBeInstanceOf(FakeVideoProvider::class);
})->with(['local', 'testing']);

// Boots a fresh copy of the provider: the app's own boot already ran before the test could set
// the environment or the config.
function fvpBootIn(string $environment, string $provider): void
{
    app()->detectEnvironment(fn () => $environment);
    config(['video.provider' => $provider]);
    Log::spy();

    (new VideoServiceProvider(app()))->boot();
}

test('booting with the fake outside local and testing logs a critical error, without throwing', function (string $environment) {
    fvpBootIn($environment, 'fake');

    Log::shouldHaveReceived('critical')->once()->withArgs(fn (string $message) => str_contains($message, 'video.provider is "fake" in '.$environment));
})->with(['production', 'staging']);

test('booting logs nothing for the fake in local or testing, or for a real provider in production', function (string $environment, string $provider) {
    fvpBootIn($environment, $provider);

    Log::shouldNotHaveReceived('critical');
})->with([
    ['local', 'fake'],
    ['testing', 'fake'],
    ['production', 'daily'],
    ['production', 'chime'],
]);

test('the fake refuses to end, eject from or re-permission a call another provider opened', function (string $method, array $extra) {
    $videoSession = VideoSession::factory()->create(['provider' => 'daily']);
    $user = User::factory()->create();
    $arguments = $method === 'endRoom' ? [$videoSession] : [$videoSession, $user, ...$extra];

    expect(fn () => (new FakeVideoProvider)->{$method}(...$arguments))
        ->toThrow(LogicException::class, "can't act on a daily call");
})->with([
    ['endRoom', []],
    ['removeParticipant', []],
    ['updateParticipantCapabilities', [true, true]],
]);

test('the fake acts on its own calls without complaint', function () {
    $videoSession = VideoSession::factory()->create(['provider' => 'fake']);
    $user = User::factory()->create();
    $fake = new FakeVideoProvider;

    expect(function () use ($fake, $videoSession, $user) {
        $fake->updateParticipantCapabilities($videoSession, $user, true, false);
        $fake->removeParticipant($videoSession, $user);
        $fake->endRoom($videoSession);
    })->not->toThrow(Throwable::class);
});
