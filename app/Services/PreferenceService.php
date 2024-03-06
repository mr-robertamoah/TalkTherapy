<?php

namespace App\Services;

use App\Actions\Preference\GetPreferenceDataAction;
use App\Actions\Preference\ThereAreNoPreferencesToSetAction;
use App\DTOs\SetPreferenceDTO;
use App\DTOs\UpdateUserDTO;
use App\Http\Resources\LanguageResource;
use App\Http\Resources\ReligionResource;
use App\Http\Resources\TherapyCaseResource;
use App\Models\Language;
use App\Models\Religion;
use App\Models\TherapyCase;
use App\Models\User;

class PreferenceService extends Service
{
    public function getPreferenceData(User $user): array
    {
        $data = [
            'loadedCases' => TherapyCaseService::new()->getCases(),
            'loadedLanguages' => LanguageService::new()->getLanguages(),
            'loadedReligions' => ReligionService::new()->getReligions(),
        ];

        if (!$user->settings || !count($user->settings)) return $data;

        if (array_key_exists('cases', $user->settings)) {
            $data['cases'] = TherapyCaseResource::collection(
                TherapyCase::query()->whereIn('id', $user->settings['cases'])->get()
            );
        }

        if (array_key_exists('languages', $user->settings)) {
            $data['languages'] = LanguageResource::collection(
                Language::query()->whereIn('id', $user->settings['languages'])->get()
            );
        }

        if (array_key_exists('religions', $user->settings)) {
            $data['religions'] = ReligionResource::collection(
                Religion::query()->whereIn('id', $user->settings['religions'])->get()
            );
        }

        return $data;
    }

    public function setPreferences(SetPreferenceDTO $setPreferenceDTO)
    {
        if (ThereAreNoPreferencesToSetAction::new()->execute($setPreferenceDTO)) return;
        
        $data = GetPreferenceDataAction::new()->execute($setPreferenceDTO);

        return UserService::new()->updateSettings(
            UpdateUserDTO::fromArray([
                'settings' => $data,
                'user' => $setPreferenceDTO->user
            ])
        );
    }
}