<?php

namespace App\Providers;

use App\Contracts\VideoProviderInterface;
use App\Services\Chime\ChimeVideoProvider;
use App\Services\Daily\DailyVideoProvider;
use App\Services\Fake\FakeVideoProvider;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;
use LogicException;

// TT-3.1a/SCRUM-274: the one place that reads config('video.provider') to decide which
// VideoProviderInterface adapter is bound -- every other class in the app depends on the
// interface only, resolved from the container, never a concrete provider class directly. See
// documentation/decision-log.md's 2026-09-11 entry for why this is a deployment-level config
// choice, not a live per-session runtime switch.
class VideoServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(VideoProviderInterface::class, function () {
            return match (config('video.provider')) {
                'daily' => $this->app->make(DailyVideoProvider::class),
                'chime' => $this->app->make(ChimeVideoProvider::class),
                'fake' => $this->fakeProvider(),
                default => throw new InvalidArgumentException(
                    'Unknown video.provider config value: '.config('video.provider')
                ),
            };
        });
    }

    // Security review (SCRUM-458): fakeProvider() only refuses lazily, at the first join, so a
    // deployment misconfigured with the fake would otherwise look healthy until someone tries a
    // call. Logged at boot instead -- never thrown here, which would take the whole site down.
    public function boot(): void
    {
        if (config('video.provider') === 'fake' && ! $this->fakeIsAllowed()) {
            Log::critical('video.provider is "fake" in '.$this->app->environment().': every video join will fail. Set VIDEO_PROVIDER to daily or chime.');
        }
    }

    // SCRUM-458: the fake has no provider behind it -- nobody would actually be connected to anyone,
    // and nothing would enforce what a participant may send. Refused at resolution (the first join),
    // not at boot, matching an unknown value above: a misconfigured deployment fails its video
    // calls loudly instead of taking the whole site down.
    private function fakeProvider(): FakeVideoProvider
    {
        if (! $this->fakeIsAllowed()) {
            throw new LogicException('video.provider "fake" is for local development and tests only, never '.$this->app->environment().'.');
        }

        return $this->app->make(FakeVideoProvider::class);
    }

    private function fakeIsAllowed(): bool
    {
        return $this->app->environment(['local', 'testing']);
    }
}
