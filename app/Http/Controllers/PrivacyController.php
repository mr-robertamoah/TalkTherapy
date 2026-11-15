<?php

namespace App\Http\Controllers;

use App\Support\RetentionPeriods;
use Inertia\Inertia;

// SCRUM-368: the privacy policy states the configured retention periods, read on each request --
// never copies that could drift from what the scheduled jobs actually do.
class PrivacyController extends Controller
{
    public function __invoke()
    {
        return Inertia::render('Privacy', [
            'retentionDays' => RetentionPeriods::days(),
        ]);
    }
}
