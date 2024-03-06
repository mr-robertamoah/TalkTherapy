<?php

namespace App\Http\Controllers;

use App\DTOs\SetPreferenceDTO;
use App\Services\PreferenceService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class PreferenceController extends Controller
{
    public function show(Request $request) {
        return Inertia::render(
            'Preferences',
            PreferenceService::new()->getPreferenceData($request->user())
        );
    }

    public function set(Request $request) {
        $request->validate([
            'selectedCases' => ['nullable', 'array'],
            'selectedLanguages' => ['nullable', 'array'],
            'selectedReligions' => ['nullable', 'array'],
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
