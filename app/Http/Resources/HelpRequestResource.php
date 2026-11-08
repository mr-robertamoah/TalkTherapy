<?php

namespace App\Http\Resources;

use App\Enums\HelpRequestStatusEnum;
use App\Models\HelpRequest;
use App\Models\Language;
use App\Support\Countries;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

// SCRUM-33: a help request as its viewer may see it.
// - The person: its status, and once answered the counsellor's name and the chat's session.
// - A counsellor (rung, or the one who answered): the short summary only -- first name or
//   "Anonymous", adult or minor, country, languages, the person's note (the user's decision). Never
//   whether the account is suspended, nor anything else about them.
/** @mixin HelpRequest */
class HelpRequestResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $viewer = $request->user();
        $isPerson = $viewer && $this->resource->isFor($viewer);
        $connected = $this->status === HelpRequestStatusEnum::connected;

        return [
            'id' => $this->id,
            'status' => $this->status->value,
            'isPerson' => $isPerson,
            'createdAt' => $this->created_at?->toIso8601String(),
            'acceptedAt' => $this->accepted_at?->toIso8601String(),
            'endedAt' => $this->ended_at?->toIso8601String(),
            'sessionId' => $this->session_id,
            'counsellorName' => $this->counsellor_id ? $this->counsellor?->name : null,
            'anonymous' => $this->anonymous,
            // Audio: an adult, while connected (a minor needs a guardian's consent -- the server decides).
            // A suspended account can't reach the call routes, so they aren't offered it (text only).
            'audioPossible' => $connected && ! $this->was_minor && ! $viewer?->isAccountSuspended()
                && ! $this->user?->isAccountSuspended(),
            // Why not, for the chat to say: a minor needs a guardian's consent; otherwise text only.
            'audioUnavailable' => $this->was_minor ? 'minor' : 'text_only',
            'person' => $isPerson ? null : $this->summary(),
        ];
    }

    private function summary(): array
    {
        $person = $this->user;
        $languageIds = array_filter((array) ($person?->settings['languages'] ?? []), 'is_numeric');

        return [
            'name' => $this->anonymous ? 'Anonymous' : ($person?->firstName ?: 'Someone'),
            'isMinor' => $this->was_minor,
            'country' => Countries::nameOf($this->country_code),
            'languages' => $languageIds ? Language::query()->whereIn('id', $languageIds)->orderBy('name')->pluck('name')->all() : [],
            'note' => $this->note,
        ];
    }
}
