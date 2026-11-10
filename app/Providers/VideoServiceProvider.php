<?php

namespace App\Providers;

use App\Contracts\VideoProviderInterface;
use App\Services\Chime\ChimeVideoProvider;
use App\Services\Daily\DailyVideoProvider;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;

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
                default => throw new InvalidArgumentException(
                    'Unknown video.provider config value: '.config('video.provider')
                ),
            };
        });
    }
}
