<?php

namespace App\Http\Controllers;

use App\DTOs\SetPreferenceDTO;
use App\Services\PreferenceService;
use App\Support\PendingLink;
use App\Support\PreferenceRanking;
use Illuminate\Http\Request;
use Inertia\Inertia;

class PreferenceController extends Controller
{
    public function show(Request $request)
    {
        return Inertia::render('Preferences', [
            ...PreferenceService::new()->getPreferenceData($request->user()),
            // SCRUM-415: a link they opened before signing up, to go back to.
            'continueUrl' => PendingLink::continueUrl($request),
        ]);
    }

    public function set(Request $request)
    {
        // SCRUM-113: these now rank what people are shown -- real ids only, at most 20 of each (an
        // empty list clears them). Stored older values are cleaned where they're read (PreferenceMatch).
        $max = PreferenceRanking::MAX_PER_KIND;
        $request->validate([
            'selectedCases' => ['nullable', 'array', "max:{$max}"],
            'selectedCases.*' => ['integer', 'distinct', 'exists:therapy_cases,id'],
            'selectedLanguages' => ['nullable', 'array', "max:{$max}"],
            'selectedLanguages.*' => ['integer', 'distinct', 'exists:languages,id'],
            'selectedReligions' => ['nullable', 'array', "max:{$max}"],
            'selectedReligions.*' => ['integer', 'distinct', 'exists:religions,id'],
            'anonymous' => ['nullable', 'boolean'],
        ]);

        PreferenceService::new()->setPreferences(
            SetPreferenceDTO::fromArray([
                'selectedCases' => $request->selectedCases,
                'selectedLanguages' => $request->selectedLanguages,
                'selectedReligions' => $request->selectedReligions,
                'anonymous' => $request->anonymous,
                'user' => $request->user(),
            ])
        );

        return redirect()->route('preferences');
    }
}
