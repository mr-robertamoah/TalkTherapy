<?php

use App\Contracts\VideoProviderInterface;
use App\Services\Fake\FakeVideoProvider;

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
