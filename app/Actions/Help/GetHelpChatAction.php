<?php

namespace App\Actions\Help;

use App\Actions\Action;
use App\Enums\HelpRequestStatusEnum;
use App\Exceptions\HelpException;
use App\Models\HelpRequest;
use App\Models\Message;
use App\Models\Session;
use App\Models\User;
use Illuminate\Support\Collection;

// SCRUM-33: who may look at a help request, and its chat's messages.
// - Its participants: always (afterwards it stays readable, read-only -- the user's decision).
// - A counsellor being rung: while it waits (the short summary only, no messages).
// Anyone else gets the same answer as for one that doesn't exist.
class GetHelpChatAction extends Action
{
    public const NOT_FOUND = 'This help request isn\'t available.';

    public function ensureCanView(User $user, ?HelpRequest $request): HelpRequest
    {
        if ($request && ($request->isParticipant($user) || $this->isRingingFor($user, $request))) {
            return $request;
        }

        throw new HelpException(self::NOT_FOUND, 404);
    }

    /** @return Collection<int, array> */
    public function messages(User $user, HelpRequest $request): Collection
    {
        if (! $request->isParticipant($user) || ! $request->session_id) {
            return collect();
        }

        return Message::query()->where('for_type', Session::class)->where('for_id', $request->session_id)
            ->orderBy('id')->get()
            ->map(fn (Message $message) => self::present($message, $user, $request));
    }

    public static function present(Message $message, User $viewer, HelpRequest $request): array
    {
        $fromPerson = $message->from_type === User::class && (int) $message->from_id === (int) $request->user_id;
        $mine = $fromPerson ? $request->isFor($viewer) : $request->isAnsweredBy($viewer);

        return [
            'id' => $message->id,
            'content' => $message->content,
            'mine' => $mine,
            'sender' => $mine ? 'You' : ($fromPerson ? ($request->anonymous ? 'Anonymous' : ($request->user?->firstName ?: 'Someone')) : ($request->counsellor?->getName() ?? 'Counsellor')),
            'createdAt' => $message->created_at?->toIso8601String(),
        ];
    }

    private function isRingingFor(User $user, HelpRequest $request): bool
    {
        return $request->status === HelpRequestStatusEnum::waiting
            && $user->counsellor
            && RingAvailableCounsellorsAction::mayAnswer($request, $user->counsellor);
    }
}
